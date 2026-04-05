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
use App\Services\Extractors\PresenceProcessor;
use App\Services\Extractors\Splitters\MarkdownSplitter;
use App\Services\Extractors\Splitters\StandardSplitter;
use App\ValueObjects\CognitiveFragment;
use App\ValueObjects\MediaResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchiveService
{
    /** @var MediaProcessorInterface[] */
    protected array $processors = [];
    protected array $splitters = [];
    protected array $ignoreFolders = ['.git', '.arkhein', 'node_modules', 'vendor', 'storage', 'build', 'dist'];
    protected int $chunkSize;
    protected int $chunkOverlap = 100;

    public function __construct(
        protected OllamaService $ollama,
        protected MemoryService $memory
    ) {
        $this->chunkSize = config('arkhein.vantage.chunk_size', 1000);
        $this->registerDefaultProcessors();
        $this->registerDefaultSplitters();
    }

    protected function registerDefaultProcessors(): void
    {
        $this->processors[] = new TextProcessor();
        $this->processors[] = new PdfProcessor();
        $this->processors[] = new VisualProcessor($this->ollama);
        $this->processors[] = new PresenceProcessor();
    }

    protected function registerDefaultSplitters(): void
    {
        $this->splitters['md'] = new MarkdownSplitter();
        $this->splitters['default'] = new StandardSplitter();
    }

    /**
     * Index a specific folder (Incremental by default).
     */
    public function indexFolder(ManagedFolder $folder, bool $forceFull = false, ?\App\Models\SystemTask $task = null): array
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
            'current_indexing_file' => null,
            'sync_status' => ManagedFolder::STATUS_INDEXING
        ]);

        if ($task) {
            $task->update(['status' => \App\Models\SystemTask::STATUS_RUNNING]);
        }

        $lastUpdateAt = microtime(true);

        foreach ($files as $index => $file) {
            $relativePath = $file->getRelativePathname();
            
            // Phase 1: 0% -> 90% (Processing files and getting embeddings)
            // Throttle DB updates to once per second to prevent SQLite lock contention
            if (microtime(true) - $lastUpdateAt > 1.0) {
                $progress = (int) (($index / $totalFiles) * 90);
                $folder->update([
                    'indexing_progress' => $progress,
                    'current_indexing_file' => $relativePath
                ]);

                if ($task) {
                    $task->update([
                        'progress' => $progress,
                        'description' => "Indexing: " . basename($relativePath)
                    ]);
                }

                $lastUpdateAt = microtime(true);
            }

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
            if ($task) $task->update(['description' => 'Optimizing vector search index...', 'progress' => 95]);
            
            $dimensions = (int) Setting::get('embedding_dimensions', config('services.ollama.embedding_dimensions'));
            $this->memory->rebuildIndex($dimensions, $folder->id, $folder);
            $this->memory->rebuildGlobalIndex($dimensions);
        }

        // Phase 3: Finalize Grounding
        $integrity = app(\App\Services\SiloIntegrityService::class);
        
        $folder->update([
            'is_indexing' => false,
            'indexing_progress' => 0,
            'current_indexing_file' => null,
            'last_indexed_at' => now(),
            'sync_status' => ManagedFolder::STATUS_IDLE,
            'disk_signature' => $integrity->computeSignature($folder->path)
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

        // Smart Promotion/Demotion: 
        // Promotion: We have sight now, but only presence exists.
        $needsPromotion = $vessel && 
                         str_starts_with($mimeType, 'image/') && 
                         $folder->allow_visual_indexing && 
                         ($vessel->metadata['is_presence_only'] ?? false);

        // Demotion: We revoked sight, but deep analysis chunks still exist.
        $needsDemotion = $vessel && 
                        str_starts_with($mimeType, 'image/') && 
                        !$folder->allow_visual_indexing && 
                        !($vessel->metadata['is_presence_only'] ?? false);

        if (!$force && $vessel && $vessel->checksum === $checksum && !$needsPromotion && !$needsDemotion) {
            return ['chunks' => 0];
        }

        // 2. Select Processor (Now handles Controlled Vision internally)
        $processor = $this->getProcessor($extension, $mimeType, $folder);

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

        // 6. Create Cognitive Fragments
        $splitter = $this->splitters[$extension] ?? $this->splitters['default'];
        $rawChunks = !empty($result->fragments) ? $result->fragments : $splitter->split($content, $this->chunkSize);
        
        $embeddingModel = Setting::get('embedding_model', config('services.ollama.embedding_model'));
        $chunksCreated = 0;

        foreach ($rawChunks as $index => $chunk) {
            // Anchor the fragment to its parent context for mathematically superior retrieval
            $fragment = CognitiveFragment::make($chunk, $vessel->summary ?? 'No summary available', $relativePath);
            
            // We use the 'vectorAnchor' (enriched text) for the embedding calculation
            $embedding = $this->ollama->embeddings($fragment->vectorAnchor, $embeddingModel);
            
            if ($embedding) {
                $this->memory->save(
                    Str::uuid()->toString(),
                    $fragment->content, // Store the raw content for LLM reading
                    $embedding,
                    $result->type,
                    [
                        'path' => $relativePath,
                        'filename' => $vessel->filename,
                        'chunk_index' => $index,
                        'total_chunks' => count($rawChunks),
                        'folder_id' => $folder->id,
                        'folder_name' => $folder->name,
                    ],
                    true, // skipIndex: bulk mode
                    $vessel->id,
                    $mimeType,
                    $fragment->vectorAnchor
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

    protected function getProcessor(string $extension, string $mimeType, ManagedFolder $folder): ?MediaProcessorInterface
    {
        // 1. Specialized Case: Images (Controlled Vision)
        if (str_starts_with($mimeType, 'image/')) {
            if ($folder->allow_visual_indexing) {
                return $this->getProcessorByClass(VisualProcessor::class);
            }
            return $this->getProcessorByClass(PresenceProcessor::class);
        }

        // 2. Normal Processor Detection
        foreach ($this->processors as $processor) {
            // Skip these during normal detection
            if ($processor instanceof PresenceProcessor || $processor instanceof VisualProcessor) {
                continue;
            }

            if ($processor->supports($extension, $mimeType)) {
                return $processor;
            }
        }
        
        // 3. Last Resort: Catch-all Presence
        return $this->getProcessorByClass(PresenceProcessor::class);
    }

    protected function getProcessorByClass(string $className): ?MediaProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if (get_class($processor) === $className) {
                return $processor;
            }
        }
        return null;
    }

    protected function sanitizeUtf8(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[^\x20-\x7E\t\n\x{00A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}]/u', '', $text);
        return trim($text);
    }

    protected function shouldIgnore(string $relativePath): bool
    {
        $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
        $rootFolder = $segments[0] ?? '';

        // Only ignore standard 'junk' if it's at the TOP level of the authorized silo
        // or if it's a hidden folder (starts with .)
        foreach ($this->ignoreFolders as $ignored) {
            if ($rootFolder === $ignored || str_starts_with($rootFolder, '.')) {
                return true;
            }
        }

        return false;
    }
}
