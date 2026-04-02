<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Models\Knowledge;
use App\Models\Setting;
use App\Models\Document;
use App\Services\Extractors\MediaProcessorInterface;
use App\Services\Extractors\TextProcessor;
use App\Services\Extractors\PdfProcessor;
use App\Services\Extractors\VisualProcessor;
use App\ValueObjects\MediaResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchiveService
{
    /** @var MediaProcessorInterface[] */
    protected array $processors = [];
    protected array $ignoreFolders = ['.git', 'node_modules', 'vendor', 'storage', 'build', 'dist'];
    protected int $chunkSize;
    protected int $chunkOverlap = 100;

    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {
        $this->chunkSize = config('arkhein.vantage.chunk_size', 1000);
        $this->registerDefaultProcessors();
    }

    protected function registerDefaultProcessors(): void
    {
        $this->processors[] = new TextProcessor();
        $this->processors[] = new PdfProcessor();
        $this->processors[] = new VisualProcessor($this->ollama);
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
     * Index a single file using the Media Core pipeline.
     */
    public function indexFile(ManagedFolder $folder, string $filePath, bool $force = false): array
    {
        if (!File::exists($filePath)) return ['chunks' => 0];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = File::mimeType($filePath);
        $relativePath = str_replace($folder->path . DIRECTORY_SEPARATOR, '', $filePath);
        $checksum = md5_file($filePath);

        // 1. Vessel Incremental Check
        $vessel = Document::where('folder_id', $folder->id)
            ->where('path', $relativePath)
            ->first();

        if (!$force && $vessel && $vessel->checksum === $checksum) {
            return ['chunks' => 0];
        }

        // 2. Select Processor
        $processor = $this->getProcessor($extension, $mimeType);
        if (!$processor) {
            Log::debug("Arkhein: No processor found for {$relativePath} ({$mimeType})");
            return ['chunks' => 0];
        }

        // 3. Process Media
        $result = $processor->process($filePath);
        $content = $this->sanitizeUtf8($result->content);
        
        if (empty(trim($content)) && empty($result->fragments)) {
            return ['chunks' => 0];
        }

        // 4. Prepare Vessel (Document)
        if (!$vessel) {
            $vessel = Document::create([
                'folder_id' => $folder->id,
                'path' => $relativePath,
                'filename' => basename($filePath),
                'extension' => $extension,
                'mime_type' => $mimeType,
                'checksum' => $checksum,
                'metadata' => array_merge($result->metadata, [
                    'depth' => count(explode(DIRECTORY_SEPARATOR, $relativePath)),
                    'subfolder' => dirname($relativePath) === '.' ? '' : dirname($relativePath)
                ])
            ]);
        } else {
            $vessel->fragments()->delete();
            $vessel->update([
                'checksum' => $checksum, 
                'mime_type' => $mimeType,
                'last_indexed_at' => now(),
                'metadata' => array_merge($vessel->metadata ?? [], $result->metadata)
            ]);
        }

        // 5. High-Level Summary
        $summary = $result->summary;
        if (!$summary && strlen($content) > 2000) {
            $summary = $this->generateSummary($content);
        }
        if ($summary) {
            $vessel->update(['summary' => $summary]);
        }

        // 6. Create Fragments
        $chunks = !empty($result->fragments) ? $result->fragments : $this->splitContent($content);
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $chunksCreated = 0;

        foreach ($chunks as $index => $chunk) {
            $embedding = $this->ollama->embeddings($chunk, $embeddingModel);
            
            if ($embedding) {
                $this->memory->save(
                    Str::uuid()->toString(),
                    $chunk,
                    $embedding,
                    $result->type,
                    [
                        'path' => $relativePath,
                        'filename' => $vessel->filename,
                        'chunk_index' => $index,
                        'total_chunks' => count($chunks),
                        'folder_id' => $folder->id,
                        'folder_name' => $folder->name,
                    ],
                    true, // skipIndex: bulk mode
                    $vessel->id,
                    $mimeType
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

    protected function getProcessor(string $extension, string $mimeType): ?MediaProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($extension, $mimeType)) {
                return $processor;
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
