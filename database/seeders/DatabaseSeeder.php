<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        \App\Models\Setting::set('llm_model', 'mistral');
        \App\Models\Setting::set('embedding_model', 'nomic-embed-text:latest');
        \App\Models\Setting::set('embedding_dimensions', '768');
    }
}
