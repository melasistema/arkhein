<?php

namespace App\Services\Extractors;

use App\ValueObjects\MediaResult;
use Illuminate\Support\Facades\Log;

class PdfProcessor implements MediaProcessorInterface
{
    public function supports(string $extension, string $mimeType): bool
    {
        return strtolower($extension) === 'pdf' || $mimeType === 'application/pdf';
    }

    public function process(string $path): MediaResult
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();
            
            return MediaResult::fromText($text);
        } catch (\Exception $e) {
            Log::error("Arkhein MediaCore: Failed to parse PDF {$path}: " . $e->getMessage());
            return new MediaResult('');
        }
    }
}
