<?php

return [
    'email_max_bytes' => 1_048_576,
    'upwork' => [
        'from_addresses' => [
            'donotreply@upwork.com',
            'upwork@t.upwork.com',
        ],
        'subject_prefix' => 'New job alert:',
        'allowed_host' => 'www.upwork.com',
        'template_fingerprint' => 'upwork-alert-hourly-v1',
        'currency' => 'USD',
    ],
];
