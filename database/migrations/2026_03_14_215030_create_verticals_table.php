<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('nativephp')->create('verticals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('rag'); // rag, vision, code_analysis
            $table->foreignId('folder_id')->nullable();
            $table->json('settings')->nullable(); // Specific prompts, model overrides
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('nativephp')->dropIfExists('verticals');
    }
};
