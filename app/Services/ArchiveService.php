<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchiveService
{
    protected array $supportedExtensions = ['php', 'js', 'ts', 'vue', 'md', 'txt', 'json', 'css', 'html'];
    protected array $ignoreFolders = ['.git', 'node_modules', 'vendor', 'storage', 'build', 'dist'];
    protected int $chunkSize = 1000; // Characters per chunk

    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {}

    /**
     * Synchronize all authorized folders.
     * Exposes simple behavior, encapsulates heavy logic.
     */
    public function sync(): array
    {
        $folders = ManagedFolder::all();
        $results = [
            'total_files' => 0,
            'indexed_chunks' => 0,
            'folders_processed' => 0
        ];

        foreach ($folders as $folder) {
            $report = $this->indexFolder($folder);
            $results['total_files'] += $report['files'];
            $results['indexed_chunks'] += $report['chunks'];
            $results['folders_processed']++;
            
            $folder->update(['last_indexed_at' => now()]);
        }

        return $results;
    }

    /**
     * Index a specific folder.
     */
    public function indexFolder(ManagedFolder $folder): array
    {
        if (!File::isDirectory($folder->path)) {
            return ['files' => 0, 'chunks' => 0];
        }

        $files = File::allFiles($folder->path);
        $indexedFiles = 0;
        $totalChunks = 0;

        foreach ($files as $file) {
            // 1. Skip ignored folders
            if ($this->shouldIgnore($file->getRelativePathname())) {
                continue;
            }

            // 2. Filter by extension
            if (!in_array($file->getExtension(), $this->supportedExtensions)) {
                continue;
            }

            // 3. Process file content
            $chunks = $this->processFile($file->getRealPath());
            $totalChunks += $chunks;
            $indexedFiles++;
        }

        return ['files' => $indexedFiles, 'chunks' => $totalChunks];
    }

    /**
     * Chunk file content and generate embeddings.
     */
    protected function processFile(string $path): int
    {
        $content = File::get($path);
        if (empty(trim($content))) return 0;

        $chunks = str_split($content, $this->chunkSize);
        $chunkCount = 0;

        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $dimensions = (int) Setting::get('embedding_dimensions', 768);
        $this->memory->ensureIndex($dimensions);

        foreach ($chunks as $index => $chunk) {
            $embedding = $this->ollama->embeddings($embeddingModel, $chunk);
            
            if ($embedding) {
                $id = Str::uuid()->toString();
                $metadata = [
                    'path' => $path,
                    'filename' => basename($path),
                    'chunk_index' => $index,
                    'total_chunks' => count($chunks)
                ];

                $this->memory->save($id, $chunk, $embedding, 'file', $metadata);
                $chunkCount++;
            }
        }

        return $chunkCount;
    }

    /**
     * Logic to determine if a path should be ignored.
     */
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
