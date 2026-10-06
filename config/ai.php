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

    // Voice dictation recorded by the desktop app (whose web view has no
    // working browser speech recognition). Each audio segment is turned into
    // text here, then structured into the visit like typed notes.
    // - `chat_input_audio`: an `input_audio` part sent to chat completions,
    //   which is how Alibaba Model Studio's compatible mode serves Qwen3-ASR.
    // - `openai_transcriptions`: OpenAI-style multipart /audio/transcriptions
    //   (Whisper and compatible servers).
    'transcription_driver' => (string) env('AI_TRANSCRIPTION_DRIVER', 'chat_input_audio'),
    'transcription_model' => (string) env('AI_TRANSCRIPTION_MODEL', 'qwen3-asr-flash'),
    // Speech models are sometimes served from another endpoint or plan than
    // the chat models; left empty, the chat base_url and api_key are used.
    'transcription_base_url' => rtrim((string) env('AI_TRANSCRIPTION_BASE_URL', ''), '/'),
    'transcription_api_key' => (string) env('AI_TRANSCRIPTION_API_KEY', ''),
    'transcription_language' => (string) env('AI_TRANSCRIPTION_LANGUAGE', 'fr'),
    // Largest audio segment (bytes) accepted for transcription.
    'max_audio_bytes' => 10 * 1024 * 1024,

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
        // Free: a dictation is cut into many short segments, and the doctor
        // already pays for the action that uses the text ("Ranger dans la
        // visite", consultation_text). Every segment is still logged.
        'dictation_transcription' => 0,
    ],

    // Largest upload (bytes) sent to the vision model.
    'max_image_bytes' => 4 * 1024 * 1024,
];
