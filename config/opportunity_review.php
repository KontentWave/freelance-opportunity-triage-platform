<?php

$mode = env('OPPORTUNITY_REVIEW_MODE', 'private');

return [
    'mode' => $mode,
    'profile_path' => $mode === 'demo'
        ? resource_path('triage/profiles/demo-v1.json')
        : env('OPPORTUNITY_REVIEW_PROFILE_PATH'),
    'demo_user_id' => env('OPPORTUNITY_REVIEW_DEMO_USER_ID'),
    'demo_database_suffixes' => ['_demo', '_test'],
    'demo_note' => 'Reviewed in the synthetic shared demonstration.',
    'demo_presets' => [
        'confirm_hourly_rate' => [
            'label' => 'Confirm a $40 maximum hourly rate',
            'full_description' => 'Synthetic scope confirms an hourly engagement with a maximum rate of $40.00.',
            'overrides' => ['hourly_max' => '40.00'],
        ],
        'confirm_quality_skills' => [
            'label' => 'Confirm quality engineering skills',
            'full_description' => 'Synthetic scope confirms quality assurance and project management requirements.',
            'overrides' => ['skills' => ['quality assurance', 'project management']],
        ],
    ],
];
