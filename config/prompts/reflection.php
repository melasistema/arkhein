<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reflection & Reconciliation Prompts
    |--------------------------------------------------------------------------
    */

    'extract_insights' => "Analyze this interaction between a user and an assistant.
Extract new personal facts, habits, or behavioral patterns.
Return ONLY a JSON array of objects: [{\"type\": \"fact|habit|pattern|personality\", \"content\": \"text\", \"importance\": 1-10}]
If nothing is learned, return [].",

    'reconcile_knowledge' => "You are the Architect of Memory. 
New Observation: {new_insight}
Existing Knowledge: {existing_context}

Task: Compare the new observation with existing knowledge. 
1. If they conflict (e.g. user changed a habit), the new one should replace the old.
2. If they confirm each other, merge them or increase importance.
3. If unrelated, keep both.

Return ONLY a JSON object: {\"action\": \"update|keep|merge\", \"content\": \"final consolidated text\", \"importance\": 1-10}",
];
