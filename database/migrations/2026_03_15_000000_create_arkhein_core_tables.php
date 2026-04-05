<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Settings (Config Store)
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // 2. Permissions (Authorized Silos)
        Schema::create('managed_folders', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('name');
            $table->string('sync_status')->default('idle'); // idle, queued, indexing, stale, drafting
            $table->json('environmental_schema')->nullable(); // Level 0 Grounding: patterns, types, purpose
            $table->string('disk_signature')->nullable(); // For drift detection (count + mtime)
            $table->boolean('is_indexing')->default(false);
            $table->integer('indexing_progress')->default(0);
            $table->string('current_indexing_file')->nullable();
            $table->string('binary_hash')->nullable();
            $table->boolean('allow_visual_indexing')->default(false);
            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();
        });

        // 3. Vessels (Document Containers)
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('folder_id')->constrained('managed_folders')->cascadeOnDelete();
            $table->string('path'); // Relative path within silo
            $table->string('filename');
            $table->string('extension')->nullable();
            $table->string('mime_type')->nullable(); // For multimodal routing
            $table->text('summary')->nullable(); // High-level "Vessel Map"
            $table->string('checksum')->nullable(); // To detect content changes
            $table->json('metadata')->nullable(); // Structural info (folder depth, etc.)
            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['folder_id', 'path']);
        });

        // 4. Fragments (Knowledge Base SSOT)
        Schema::create('knowledge', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->string('type')->index(); // file_part, fact, insight, visual_description
            $table->string('mime_type')->nullable(); // Fragment-level media identification
            $table->text('content'); // Raw content for LLM
            $table->text('vector_anchor')->nullable(); // Enriched context used for embedding
            $table->json('embedding');
            $table->json('metadata')->nullable();
            $table->integer('importance')->default(1);
            $table->timestamps();
        });

        // 5. System Tasks (Task Registry)
        Schema::create('system_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // indexing, drafting, vision, sync
            $table->string('status')->default('queued'); // queued, running, completed, failed
            $table->foreignId('folder_id')->nullable()->constrained('managed_folders')->nullOnDelete();
            $table->string('description')->nullable();
            $table->integer('progress')->default(0);
            $table->json('metadata')->nullable(); // For roadmap, thinking blocks, errors
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // 6. Help Module: Interactions
        Schema::create('help_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('role'); // user, assistant, system
            $table->text('content');
            $table->text('thought')->nullable();
            $table->json('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 7. Vantage Module: Verticals
        Schema::create('verticals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('rag');
            $table->foreignId('folder_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // 8. Vantage Module: Interactions
        Schema::create('vertical_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user, assistant
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vertical_interactions');
        Schema::dropIfExists('verticals');
        Schema::dropIfExists('help_interactions');
        Schema::dropIfExists('knowledge');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('managed_folders');
        Schema::dropIfExists('settings');
    }
};
