<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            $table->string('type')->index(); // chat_summary, habit, file_part, fact
            $table->text('content');
            $table->json('embedding');
            $table->json('metadata')->nullable();
            $table->integer('importance')->default(1);
            $table->timestamps();
        });

        // 4. Conversations (Thematic Sessions)
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->text('summary')->nullable(); // Extracted by Reflection
            $table->json('embedding')->nullable(); // Vector of the summary
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 5. Messages (Chat History)
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversation_id')->index();
            $table->string('role'); // user, assistant, system
            $table->text('content');
            $table->json('embedding')->nullable(); // Vector of the message
            $table->json('metadata')->nullable(); // Stores pending_actions, etc.
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
        });
        // 6. User Insights (Distilled Habits & Patterns)
        Schema::create('user_insights', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // fact, habit, pattern, personality
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('importance')->default(1);
            $table->integer('occurrence_count')->default(1);
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_insights');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('knowledge');
        Schema::dropIfExists('managed_folders');
        Schema::dropIfExists('settings');
    }
};
