<?php

/*
|--------------------------------------------------------------------------
| Clinical AI assistant
|--------------------------------------------------------------------------
|
| The provider key lives only on the hosted control plane. A local desktop
| installation has no key: it relays through the hosted service with the
| Sanctum token it already holds for appointment sync, and the hosted
| service charges that cabinet's credit wallet (see App\Services\Ai\AiGateway).
|
*/

return [
    // Any OpenAI-compatible chat completions endpoint (Alibaba Model Studio here).
    'base_url' => rtrim((string) env('AI_BASE_URL', 'https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1'), '/'),
    'api_key' => (string) env('AI_API_KEY', ''),
    'model' => (string) env('AI_MODEL', 'qwen3.8-flash'),
    // Must accept image input; used only for uploaded scans and photos.
    'vision_model' => (string) env('AI_VISION_MODEL', env('AI_MODEL', 'qwen3.8-flash')),
    // ECG images. A reading is always paired with measurements the software
    // takes from the trace itself; see App\Services\Ai\EcgAiService.
    'ecg_model' => (string) env('AI_ECG_MODEL', env('AI_VISION_MODEL', env('AI_MODEL', 'qwen3.8-flash'))),
    'timeout' => (int) env('AI_TIMEOUT', 60),

    // Wallet every new cabinet starts with. Recharges are made by support
    // from the admin panel.
    'initial_credits' => (int) env('AI_INITIAL_CREDITS', 500),

    // Fixed price per action, so a doctor knows the cost before clicking.
    'costs' => [
        'consultation_text' => 1,
        'exam_suggestions' => 2,
        'prescription_suggestions' => 2,
        'document_analysis' => 3,
        'patient_analysis' => 5,
        'ecg_analysis' => 4,
        'ecg_chat' => 1,
        'copilot_chat' => 1,
    ],

    // Largest upload (bytes) sent to the vision model.
    'max_image_bytes' => 4 * 1024 * 1024,
];
