<?php

namespace Tests\Feature;

use App\Models\ManagedFolder;
use App\Services\FileOperationService;
use Illuminate\Support\Facades\File;

test('it can resolve @ mentions correctly', function () {
    ManagedFolder::create([
        'name' => 'docs',
        'path' => '/Users/test/Documents'
    ]);

    $service = app(FileOperationService::class);
    
    $method = new \ReflectionMethod(FileOperationService::class, 'resolvePath');
    $method->setAccessible(true);

    $resolved = $method->invoke($service, '@docs/file.txt');
    
    expect($resolved)->toBe('/Users/test/Documents/file.txt');
});

test('it correctly identifies authorized paths', function () {
    // Create a physical directory in temp
    $tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arkhein_test_' . uniqid();
    if (!is_dir($tempRoot)) mkdir($tempRoot, 0777, true);
    
    // Create a nested file
    $nestedDir = $tempRoot . DIRECTORY_SEPARATOR . 'inner';
    if (!is_dir($nestedDir)) mkdir($nestedDir, 0777, true);
    $nestedFile = $nestedDir . DIRECTORY_SEPARATOR . 'file.txt';
    file_put_contents($nestedFile, 'test');

    ManagedFolder::create([
        'name' => 'sandbox',
        'path' => $tempRoot
    ]);

    $service = app(FileOperationService::class);
    $method = new \ReflectionMethod(FileOperationService::class, 'isAuthorized');
    $method->setAccessible(true);

    // Exact match
    expect($method->invoke($service, '@sandbox'))->toBeTrue();
    
    // Subpath (using the physical file we created)
    expect($method->invoke($service, '@sandbox/inner/file.txt'))->toBeTrue();
    
    // Unauthorized path (trying to escape)
    expect($method->invoke($service, '@sandbox/../../etc/passwd'))->toBeFalse();

    // Clean up
    File::deleteDirectory($tempRoot);
});

test('sovereign configuration is loaded correctly', function () {
    expect(config('arkhein.identity.name'))->toBe('Arkhein');
    expect(config('arkhein.boundaries.max_file_scan_depth'))->toBeInt();
});
