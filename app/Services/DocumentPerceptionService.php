<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DocumentPerceptionService
{
    public function __construct(
        protected OllamaService $ollama,
        protected CognitiveService $cognitive
    ) {}

    /**
     * Perceive the semantic nature of a document and extract structured metadata.
     * Uses a multi-stage reasoning pipeline recorded in the 'workflows' storage.
     */
    public function perceive(string $content, string $filename, string $extension, int $folderId): array
    {
        Log::info("Arkhein Perception: Starting cognitive ingestion for {$filename}");

        $preview = mb_substr($content, 0, 6000);
        
        // Stage 1: Preliminary Structural Analysis
        $stage1Prompt = "Stage 1: Document Classification & Structure Analysis
        FILENAME: {$filename}
        
        Analyze the following text and determine:
        1. Document Type (e.g., INVOICE, CONTRACT, DIAGNOSIS, etc.)
        2. Visual Structure (e.g., Table-heavy, List-based, Narrative, Form-style)
        3. Language
        
        TEXT:
        \"\"\"
        {$preview}
        \"\"\"
        
        Write your findings in a professional analytical tone.";

        $analysis = $this->ollama->generate($stage1Prompt);
        
        // Record workflow start
        $workflowContent = "# Ingestion Workflow: {$filename}\n\n## Phase 1: Structural Analysis\n{$analysis}\n\n";
        $this->cognitive->persistCoT('workflow', (string) $folderId, "{$filename}.ingestion.md", $workflowContent);

        // Stage 2: Targeted Extraction based on Phase 1
        $stage2Prompt = "Stage 2: Targeted Data Extraction
        DOCUMENT ANALYSIS: {$analysis}
        
        Based on the analysis above, extract the core structured data from the text.
        If it's an INVOICE or RECEIPT, you MUST find:
        - total_amount (Find 'Total', 'Totale', 'Importo', 'Grand Total')
        - tax_amount (VAT, IVA, Tax)
        - sender/vendor
        - recipient
        - date
        - currency
        
        If it's a MEDICAL DIAGNOSIS, extract:
        - patient_context
        - findings
        - recommendations
        
        TEXT:
        \"\"\"
        {$preview}
        \"\"\"
        
        Respond ONLY in a strict JSON format.";

        $extractionRes = $this->ollama->generate($stage2Prompt, null, ['format' => 'json']);
        $data = json_decode($extractionRes, true);

        if (!$data) {
            if (preg_match('/\{.*\}/s', $extractionRes, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        $workflowContent .= "## Phase 2: Targeted Extraction\n```json\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n```\n\n";
        $this->cognitive->persistCoT('workflow', (string) $folderId, "{$filename}.ingestion.md", $workflowContent);
        
        // Stage 3: Summary & Synthesis
        $stage3Prompt = "Stage 3: Final Synthesis
        Extract a one-sentence semantic summary of this document based on the extracted data: " . json_encode($data);
        
        $summary = $this->ollama->generate($stage3Prompt);
        $workflowContent .= "## Phase 3: Final Synthesis\n{$summary}\n";
        
        $this->cognitive->persistCoT('workflow', (string) $folderId, "{$filename}.ingestion.md", $workflowContent);

        return [
            'document_type' => $data['document_type'] ?? 'GENERAL_DOCUMENT',
            'confidence' => 0.9,
            'extracted_metadata' => $data,
            'semantic_summary' => trim($summary)
        ];
    }

    protected function defaultPerception(string $extension): array
    {
        return [
            'document_type' => 'GENERAL_DOCUMENT',
            'confidence' => 0.5,
            'extracted_metadata' => [
                'extension' => $extension
            ],
            'semantic_summary' => 'Standard local document.'
        ];
    }
}
