<?php

namespace App\Services\Extractors;

use Illuminate\Support\Facades\Log;

class PdfExtractor implements ExtractorInterface
{
    public function supports(string $extension): bool
    {
        return strtolower($extension) === 'pdf';
    }

    public function extract(string $path): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            return $pdf->getText();
        } catch (\Exception $e) {
            Log::error("Arkhein: Failed to parse PDF {$path}: " . $e->getMessage());
            return '';
        }
    }
}
