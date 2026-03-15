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

        // 2. Permissions (Authorized Directories)
        Schema::create('managed_folders', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('name');
            $table->boolean('is_indexing')->default(false);
            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();
        });

        // 3. Unified Knowledge Base (SSOT for RAG)
        Schema::create('knowledge', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->index(); // file_part, fact
            $table->text('content');
            $table->json('embedding');
            $table->json('metadata')->nullable();
            $table->integer('importance')->default(1);
            $table->timestamps();
        });

        // 4. Help Module: Interactions (Linear Stream)
        Schema::create('help_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('role'); // user, assistant, system
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 5. Vantage Module: Verticals (Isolated Document Analysis)
        Schema::create('verticals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('rag');
            $table->foreignId('folder_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // 6. Vantage Module: Interactions
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
        Schema::dropIfExists('managed_folders');
        Schema::dropIfExists('settings');
    }
};
