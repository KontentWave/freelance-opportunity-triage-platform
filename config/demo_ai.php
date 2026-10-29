<?php

return [
    'enabled' => (bool) env('DEMO_AI_ENABLED', false),
    'scheduler_enabled' => (bool) env('DEMO_AI_SCHEDULER_ENABLED', false),
    'mailbox_key' => env('DEMO_AI_MAILBOX_KEY'),
    'mailbox_host' => env('DEMO_AI_MAILBOX_HOST'),
    'mailbox_port' => (int) env('DEMO_AI_MAILBOX_PORT', 993),
    'mailbox_username' => env('DEMO_AI_MAILBOX_USERNAME'),
    'mailbox_password' => env('DEMO_AI_MAILBOX_PASSWORD'),
    'mailbox_folder' => env('DEMO_AI_MAILBOX_FOLDER'),
    'api_key' => env('DEMO_AI_OPENAI_KEY'),
    'counting_enabled' => (bool) env('DEMO_AI_COUNTING_ENABLED', false),
    'counting_policy_evidence_sha256' => env('DEMO_AI_COUNTING_POLICY_EVIDENCE_SHA256'),
    'model' => 'gpt-6-luna',
    'pricing_version' => 'openai-standard-2026-10-27',
];
