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

        // 4. Help Module: Sessions
        Schema::create('help_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->json('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 5. Help Module: Interactions
        Schema::create('help_interactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('help_session_id')->index();
            $table->string('role'); // user, assistant, system
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('help_session_id')->references('id')->on('help_sessions')->onDelete('cascade');
        });

        // 6. Vantage Verticals (Isolated Document Analysis)
        Schema::create('verticals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('rag');
            $table->foreignId('folder_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // 7. Vantage Interaction History
        Schema::create('vantage_interactions', function (Blueprint $table) {
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
        Schema::dropIfExists('vantage_interactions');
        Schema::dropIfExists('verticals');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('knowledge');
        Schema::dropIfExists('managed_folders');
        Schema::dropIfExists('settings');
    }
};
