<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Models\Knowledge;
use App\Models\Setting;
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
            Knowledge::on('nativephp')->where('type', 'file')
                ->where('metadata->folder_id', $folder->id)
                ->delete();
        }

        $files = File::allFiles($folder->path);
        $indexedFiles = 0;
        $totalChunks = 0;
        $anyChanges = false;

        foreach ($files as $file) {
            if ($this->shouldIgnore($file->getRelativePathname())) continue;
            
            $res = $this->indexFile($folder, $file->getRealPath(), $forceFull);
            if ($res['chunks'] > 0) {
                $indexedFiles++;
                $totalChunks += $res['chunks'];
                $anyChanges = true;
            }
        }

        if ($anyChanges || $forceFull) {
            $dimensions = (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
            $this->memory->rebuildIndex($dimensions);
        }

        return ['files' => $indexedFiles, 'chunks' => $totalChunks];
    }

    /**
     * Index a single file within a managed folder.
     */
    public function indexFile(ManagedFolder $folder, string $filePath, bool $force = false): array
    {
        if (!File::exists($filePath)) return ['chunks' => 0];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $extractor = $this->getExtractor($extension);
        if (!$extractor) return ['chunks' => 0];

        $lastModified = File::lastModified($filePath);

        // INCREMENTAL CHECK
        if (!$force) {
            $existing = Knowledge::on('nativephp')->where('type', 'file')
                ->where('metadata->path', $filePath)
                ->first();

            if ($existing && ($existing->metadata['last_modified'] ?? 0) >= $lastModified) {
                return ['chunks' => 0];
            }

            if ($existing) {
                Knowledge::on('nativephp')->where('type', 'file')
                    ->where('metadata->path', $filePath)
                    ->delete();
            }
        }

        $content = $extractor->extract($filePath);
        $content = $this->sanitizeUtf8($content);
        if (empty(trim($content))) return ['chunks' => 0];

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
                        'path' => $filePath,
                        'filename' => basename($filePath),
                        'chunk_index' => $index,
                        'total_chunks' => count($chunks),
                        'folder_id' => $folder->id,
                        'last_modified' => $lastModified,
                    ]
                );
                $chunksCreated++;
            }
        }

        return ['chunks' => $chunksCreated];
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

    /**
     * A simple recursive-ish character splitter to avoid cutting semantic blocks.
     */
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
            // No more separators, forced split
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
                    // Part itself is too big, recurse on it
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
