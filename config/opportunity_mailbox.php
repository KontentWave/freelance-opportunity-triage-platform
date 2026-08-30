<?php

return [
    'enabled' => env('OPPORTUNITY_MAILBOX_ENABLED', false),
    'workspace_id' => env('OPPORTUNITY_MAILBOX_WORKSPACE_ID'),
    'mailbox_key' => env('OPPORTUNITY_MAILBOX_KEY', 'primary'),
    'host' => env('OPPORTUNITY_MAILBOX_HOST'),
    'port' => env('OPPORTUNITY_MAILBOX_PORT', 993),
    'encryption' => env('OPPORTUNITY_MAILBOX_ENCRYPTION', 'ssl'),
    'validate_cert' => env('OPPORTUNITY_MAILBOX_VALIDATE_CERT', true),
    'username' => env('OPPORTUNITY_MAILBOX_USERNAME'),
    'password' => env('OPPORTUNITY_MAILBOX_PASSWORD'),
    'folder' => env('OPPORTUNITY_MAILBOX_FOLDER'),
    'candidate_from' => env('OPPORTUNITY_MAILBOX_CANDIDATE_FROM', 'upwork@t.upwork.com'),
    'candidate_subject_prefix' => env('OPPORTUNITY_MAILBOX_CANDIDATE_SUBJECT_PREFIX', 'New job alert:'),
    'batch_size' => env('OPPORTUNITY_MAILBOX_BATCH_SIZE', 25),
    'initial_lookback_hours' => env('OPPORTUNITY_MAILBOX_INITIAL_LOOKBACK_HOURS', 24),
    'max_attempts' => env('OPPORTUNITY_MAILBOX_MAX_ATTEMPTS', 3),
    'health_max_age_minutes' => env('OPPORTUNITY_MAILBOX_HEALTH_MAX_AGE_MINUTES', 15),
];
