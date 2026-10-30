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
    'counting_policy_mode' => env('DEMO_AI_COUNTING_POLICY_MODE', 'verified_zero_charge'),
    'counting_policy_evidence_sha256' => env('DEMO_AI_COUNTING_POLICY_EVIDENCE_SHA256'),
    'counting_risk_acceptance_reference' => env('DEMO_AI_COUNTING_RISK_ACCEPTANCE_REFERENCE'),
    'model' => 'gpt-6-luna',
    'pricing_version' => 'openai-standard-2026-10-27',
    'owner_delivery' => [
        'enabled' => (bool) env('DEMO_AI_OWNER_DELIVERY_ENABLED', false),
        'recipient' => env('DEMO_AI_OWNER_RECIPIENT'),
        'allowlist_sha256' => env('DEMO_AI_OWNER_RECIPIENT_SHA256'),
        'version' => env('DEMO_AI_OWNER_RECIPIENT_VERSION'),
        'verification_reference' => env('DEMO_AI_OWNER_VERIFICATION_REFERENCE'),
    ],
];
