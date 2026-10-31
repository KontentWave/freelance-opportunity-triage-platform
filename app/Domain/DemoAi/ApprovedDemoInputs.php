<?php

namespace App\Domain\DemoAi;

use App\Domain\Triage\Support\CanonicalJson;
use JsonException;

final class ApprovedDemoInputs
{
    public const PROFILE_VERSION = 'public-profile-v2';

    public const PROMPT_VERSION = 'public-evaluation-v2';

    public const RUBRIC_VERSION = 'public-rubric-v2';

    public const SCHEMA_VERSION = 'ai-evaluation-v2';

    public const PARSER_VERSION = 'demo-json-v1';

    public const CRITERIA = [
        'delivery_fit', 'proportionate_evidence', 'client_value', 'scope_risk',
        'specialization', 'availability', 'devices', 'geography',
        'essential_capability', 'scope_fit', 'freshness', 'competition', 'uncertainty',
    ];

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $path = base_path('tests/Fixtures/Emails/demo/manifest.json');
        if (! is_readable($path)) {
            throw new DemoAiException('demo_ai.manifest_unavailable');
        }

        try {
            $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DemoAiException('demo_ai.manifest_invalid');
        }

        if (! is_array($manifest)
            || ($manifest['corpus_version'] ?? null) !== 'authored-demo-v2'
            || ($manifest['source_namespace'] ?? null) !== 'fotp-authored-demo-v1'
            || ! is_array($manifest['items'] ?? null)
            || count($manifest['items']) !== 20) {
            throw new DemoAiException('demo_ai.manifest_invalid');
        }

        return $manifest;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $path = resource_path('demo-ai/public-v2.json');
        if (! is_readable($path)) {
            throw new DemoAiException('demo_ai.profile_unavailable');
        }

        try {
            $snapshot = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DemoAiException('demo_ai.profile_invalid');
        }
        if (! is_array($snapshot) || ($snapshot['profile_version'] ?? null) !== self::PROFILE_VERSION
            || ($snapshot['prompt_version'] ?? null) !== self::PROMPT_VERSION) {
            throw new DemoAiException('demo_ai.profile_invalid');
        }

        return $snapshot;
    }

    public function versionHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'public' => $this->snapshot(),
            'manifest' => $this->manifest(),
            'parser' => self::PARSER_VERSION,
            'schema' => self::SCHEMA_VERSION,
        ]));
    }
}
