<?php

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutMiddleware();

        // The app uses a dedicated "nativephp" SQLite connection as SSOT.
        // Using :memory: here is unsafe because separate PDO connections can see different DBs.
        // We force a single file-backed DB for repeatable, request-safe tests.
        $path = database_path('nativephp-testing.sqlite');
        if (! file_exists($path)) {
            touch($path);
        }

        config(['database.connections.nativephp.database' => $path]);
        DB::purge('nativephp');
        DB::reconnect('nativephp');

        $this->artisan('migrate', ['--database' => 'nativephp']);

        // RefreshDatabase only resets the default connection.
        // Ensure the nativephp SSOT starts clean for each test.
        DB::connection('nativephp')->table('vertical_interactions')->delete();
        DB::connection('nativephp')->table('verticals')->delete();
        DB::connection('nativephp')->table('managed_folders')->delete();
        DB::connection('nativephp')->table('help_interactions')->delete();
        DB::connection('nativephp')->table('knowledge')->delete();
        DB::connection('nativephp')->table('settings')->delete();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
