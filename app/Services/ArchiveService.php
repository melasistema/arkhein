<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Models\Knowledge;
use App\Models\Setting;
use App\Models\Document;
use App\Services\Extractors\ExtractorInterface;
use App\Services\Extractors\TextExtractor;
use App\Services\Extractors\PdfExtractor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchiveService
{
    protected array $extractors = [];
    protected array $ignoreFolders = ['.git', 'node_modules', 'vendor', 'storage', 'build', 'dist'];
    protected int $chunkSize;
    protected int $chunkOverlap = 100;

    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {
        $this->chunkSize = config('arkhein.vantage.chunk_size', 1000);
        $this->registerDefaultExtractors();
    }

    protected function registerDefaultExtractors(): void
    {
        $this->extractors[] = new TextExtractor();
        $this->extractors[] = new PdfExtractor();
    }

    /**
     * Index a specific folder (Incremental by default).
     */
    public function indexFolder(ManagedFolder $folder, bool $forceFull = false): array
    {
        if (!File::isDirectory($folder->path)) {
            return ['files' => 0, 'chunks' => 0];
        }

        Log::info("Arkhein: Starting " . ($forceFull ? 'FULL' : 'INCREMENTAL') . " index for @{$folder->name}");

        if ($forceFull) {
            Knowledge::on('nativephp')->where('metadata->folder_id', $folder->id)->delete();
            Document::where('folder_id', $folder->id)->delete();
        }

        $files = File::allFiles($folder->path);
        $totalFiles = count($files);
        $indexedFiles = 0;
        $totalChunks = 0;
        $anyChanges = false;

        $folder->update([
            'is_indexing' => true,
            'indexing_progress' => 0,
            'current_indexing_file' => null
        ]);

        foreach ($files as $index => $file) {
            $relativePath = $file->getRelativePathname();
            
            // Phase 1: 0% -> 90% (Processing files and getting embeddings)
            $progress = (int) (($index / $totalFiles) * 90);
            $folder->update([
                'indexing_progress' => $progress,
                'current_indexing_file' => $relativePath
            ]);

            if ($this->shouldIgnore($relativePath)) continue;
            
            $res = $this->indexFile($folder, $file->getRealPath(), $forceFull);
            if ($res['chunks'] > 0) {
                $indexedFiles++;
                $totalChunks += $res['chunks'];
                $anyChanges = true;
            }
        }

        // Phase 2: 90% -> 100% (Vektor binary rebuild)
        if ($anyChanges || $forceFull) {
            $dimensions = (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
            $this->memory->rebuildIndex($dimensions, $folder->id, $folder);
            $this->memory->rebuildGlobalIndex($dimensions);
        }

        $folder->update([
            'is_indexing' => false,
            'indexing_progress' => 0,
            'current_indexing_file' => null,
            'last_indexed_at' => now()
        ]);

        return ['files' => $indexedFiles, 'chunks' => $totalChunks];
    }

    /**
     * Index a single file as a Vessel with Fragments.
     */
    public function indexFile(ManagedFolder $folder, string $filePath, bool $force = false): array
    {
        if (!File::exists($filePath)) return ['chunks' => 0];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $relativePath = str_replace($folder->path . DIRECTORY_SEPARATOR, '', $filePath);
        $lastModified = File::lastModified($filePath);
        $checksum = md5_file($filePath);

        // 1. Vessel Incremental Check
        $vessel = Document::where('folder_id', $folder->id)
            ->where('path', $relativePath)
            ->first();

        if (!$force && $vessel && $vessel->checksum === $checksum) {
            return ['chunks' => 0];
        }

        $extractor = $this->getExtractor($extension);
        if (!$extractor) return ['chunks' => 0];

        $content = $extractor->extract($filePath);
        $content = $this->sanitizeUtf8($content);
        if (empty(trim($content))) return ['chunks' => 0];

        // 2. Prepare Vessel (Document)
        if (!$vessel) {
            $vessel = Document::create([
                'folder_id' => $folder->id,
                'path' => $relativePath,
                'filename' => basename($filePath),
                'extension' => $extension,
                'checksum' => $checksum,
                'metadata' => [
                    'depth' => count(explode(DIRECTORY_SEPARATOR, $relativePath)),
                    'subfolder' => dirname($relativePath) === '.' ? '' : dirname($relativePath)
                ]
            ]);
        } else {
            $vessel->fragments()->delete();
            $vessel->update(['checksum' => $checksum, 'last_indexed_at' => now()]);
        }

        // 3. Optional: Generate high-level summary if the file is large
        if (strlen($content) > 2000) {
            $vessel->update(['summary' => $this->generateSummary($content)]);
        }

        // 4. Create Fragments (Knowledge chunks)
        $chunks = $this->splitContent($content);
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $chunksCreated = 0;

        foreach ($chunks as $index => $chunk) {
            $embedding = $this->ollama->embeddings($chunk, $embeddingModel);
            
            if ($embedding) {
                $this->memory->save(
                    Str::uuid()->toString(),
                    $chunk,
                    $embedding,
                    'file',
                    [
                        'path' => $relativePath,
                        'filename' => $vessel->filename,
                        'chunk_index' => $index,
                        'total_chunks' => count($chunks),
                        'folder_id' => $folder->id,
                        'folder_name' => $folder->name,
                        'last_modified' => $lastModified,
                    ],
                    true, // skipIndex: bulk mode
                    $vessel->id // Pass document_id as direct argument
                );
                $chunksCreated++;
            }
        }

        return ['chunks' => $chunksCreated];
    }

    protected function generateSummary(string $content): string
    {
        $preview = mb_substr($content, 0, 3000);
        $prompt = "Summarize the following document content in one short, professional paragraph. Focus on the core topic and purpose.\n\nCONTENT:\n{$preview}";
        
        return $this->ollama->generate($prompt);
    }

    protected function getExtractor(string $extension): ?ExtractorInterface
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($extension)) {
                return $extractor;
            }
        }
        return null;
    }

    protected function splitContent(string $text): array
    {
        $separators = ["\n\n", "\n", ". ", " ", ""];
        return $this->recursiveSplit($text, $separators, $this->chunkSize);
    }

    protected function recursiveSplit(string $text, array $separators, int $maxSize): array
    {
        if (strlen($text) <= $maxSize) {
            return [$text];
        }

        $separator = array_shift($separators);
        if ($separator === null) {
            return str_split($text, $maxSize);
        }

        $chunks = [];
        $parts = explode($separator, $text);
        $currentChunk = "";

        foreach ($parts as $part) {
            if (strlen($currentChunk . $separator . $part) <= $maxSize) {
                $currentChunk .= ($currentChunk ? $separator : "") . $part;
            } else {
                if ($currentChunk) {
                    $chunks[] = $currentChunk;
                }
                
                if (strlen($part) > $maxSize) {
                    $subChunks = $this->recursiveSplit($part, $separators, $maxSize);
                    foreach ($subChunks as $sc) {
                        $chunks[] = $sc;
                    }
                    $currentChunk = "";
                } else {
                    $currentChunk = $part;
                }
            }
        }

        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    protected function sanitizeUtf8(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[^\x20-\x7E\t\n\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}]/u', '', $text);
        return trim($text);
    }

    protected function shouldIgnore(string $relativePath): bool
    {
        foreach ($this->ignoreFolders as $folder) {
            if (str_contains($relativePath, DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR) || 
                str_starts_with($relativePath, $folder . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }
        return false;
    }
}
