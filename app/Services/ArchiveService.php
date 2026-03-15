<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Models\Setting;
use App\Models\Knowledge;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchiveService
{
    protected array $supportedExtensions = ['pdf', 'md', 'txt', 'php', 'js', 'ts', 'vue', 'json', 'css', 'html'];
    protected array $ignoreFolders = ['.git', 'node_modules', 'vendor', 'storage', 'build', 'dist'];
    protected int $chunkSize = 1000;

    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {
        $this->chunkSize = config('arkhein.vantage.chunk_size', 1000);
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
            Knowledge::on('nativephp')
                ->where('type', 'file')
                ->where('metadata->folder_id', $folder->id)
                ->delete();
        }

        $files = File::allFiles($folder->path);
        $indexedFiles = 0;
        $totalChunks = 0;
        $anyChanges = false;

        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $dimensions = (int) Setting::get('embedding_dimensions', 768);

        foreach ($files as $file) {
            if ($this->shouldIgnore($file->getRelativePathname())) continue;
            
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $this->supportedExtensions)) continue;

            $filePath = $file->getRealPath();
            $lastModified = $file->getMTime();

            // INCREMENTAL CHECK
            if (!$forceFull) {
                $existing = Knowledge::on('nativephp')
                    ->where('type', 'file')
                    ->where('metadata->path', $filePath)
                    ->first();

                // If file exists and hasn't changed, skip it
                if ($existing && ($existing->metadata['last_modified'] ?? 0) >= $lastModified) {
                    continue;
                }

                // If file changed, delete its old chunks before re-indexing
                if ($existing) {
                    Knowledge::on('nativephp')
                        ->where('type', 'file')
                        ->where('metadata->path', $filePath)
                        ->delete();
                }
            }

            $content = $this->getContent($filePath);
            if (empty(trim($content))) continue;

            $chunks = str_split($content, $this->chunkSize);
            
            foreach ($chunks as $index => $chunk) {
                $embedding = $this->ollama->embeddings($embeddingModel, $chunk);
                
                if ($embedding) {
                    Knowledge::on('nativephp')->create([
                        'id' => Str::uuid()->toString(),
                        'type' => 'file',
                        'content' => $this->sanitizeUtf8($chunk),
                        'embedding' => $embedding,
                        'metadata' => [
                            'path' => $filePath,
                            'filename' => $file->getFilename(),
                            'chunk_index' => $index,
                            'total_chunks' => count($chunks),
                            'folder_id' => $folder->id,
                            'last_modified' => $lastModified, // Save mtime for next incremental sync
                        ]
                    ]);
                    $totalChunks++;
                }
            }
            $indexedFiles++;
            $anyChanges = true;
        }

        // Only rebuild binary index if something actually changed
        if ($anyChanges || $forceFull) {
            $this->memory->rebuildIndex($dimensions);
        }

        return ['files' => $indexedFiles, 'chunks' => $totalChunks];
    }

    protected function getContent(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $content = '';
        
        if ($extension === 'pdf') {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                $content = $pdf->getText();
            } catch (\Exception $e) {
                Log::error("Arkhein: Failed to parse PDF {$path}: " . $e->getMessage());
                return '';
            }
        } else {
            $content = File::get($path);
        }

        return $this->sanitizeUtf8($content);
    }

    protected function sanitizeUtf8(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[^\x20-\x7E\t\n\r\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}]/u', '', $text);
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
