<?php

namespace App\Services;

use App\Models\ManagedFolder;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchiveService
{
    protected array $supportedExtensions = ['pdf', 'md', 'txt', 'php', 'js', 'ts', 'vue', 'json', 'css', 'html'];
    protected array $ignoreFolders = ['.git', 'node_modules', 'vendor', 'storage', 'build', 'dist'];
    protected int $chunkSize = 1000; // Characters per chunk

    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {
        $this->chunkSize = config('arkhein.vantage.chunk_size', 1000);
    }

    /**
     * Synchronize all authorized folders.
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

        // 1. Scoped Cleanse: Only remove chunks belonging to THIS folder
        \App\Models\Knowledge::on('nativephp')
            ->where('type', 'file')
            ->where('metadata->folder_id', $folder->id)
            ->delete();

        $files = File::allFiles($folder->path);
        $indexedFiles = 0;
        $totalChunks = 0;

        foreach ($files as $file) {
            if ($this->shouldIgnore($file->getRelativePathname())) continue;
            
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $this->supportedExtensions)) continue;

            $chunks = $this->processFile($file->getRealPath(), $folder->id);
            $totalChunks += $chunks;
            $indexedFiles++;
        }

        return ['files' => $indexedFiles, 'chunks' => $totalChunks];
    }

    /**
     * Extract content based on file type.
     */
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

    /**
     * Clean malformed UTF-8 characters.
     */
    protected function sanitizeUtf8(string $text): string
    {
        // 1. Convert encoding to strip invalid sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // 2. Remove non-printable characters except basic whitespace
        $text = preg_replace('/[^\x20-\x7E\t\n\r\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}]/u', '', $text);

        return trim($text);
    }

    /**
     * Chunk file content and generate embeddings.
     */
    protected function processFile(string $path, int $folderId): int
    {
        $content = $this->getContent($path);
        if (empty(trim($content))) return 0;

        // Use slightly larger chunks for document analysis if needed, 
        // but keeping 1000 for now.
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
                    'total_chunks' => count($chunks),
                    'folder_id' => $folderId, // CRITICAL: Tag with folder_id
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
