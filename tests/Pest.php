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
        // In tests we point it to :memory: (phpunit.xml) and ensure schema exists.
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
