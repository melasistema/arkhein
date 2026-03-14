<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge', function (Blueprint $table) {
            $table->uuid('id')->primary(); // The stable link to Vektor
            $table->string('type')->index(); // chat, insight, habit, file, etc.
            $table->text('content');
            $table->json('embedding'); // The SSOT Vector
            $table->json('metadata')->nullable(); // Flexible properties
            $table->integer('importance')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge');
    }
};
