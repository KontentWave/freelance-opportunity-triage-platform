<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
    <title>Fictional demo report {{ $report['id'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0 auto; padding: 1.5rem; max-width: 70rem; font: 1rem/1.6 system-ui, sans-serif; color: #17221d; background: #f4f5ef; overflow-wrap: anywhere; }
        h1, h2, h3 { line-height: 1.25; }
        h1, h2 { font-family: Georgia, serif; }
        article, section { margin-block: 1.5rem; border-top: 2px solid #718078; padding-block: 1rem; }
        article { background: #fff; padding: 1.25rem; }
        dt { font-weight: 700; }
        dd { margin: 0 0 0.75rem; white-space: pre-wrap; }
        pre { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 0.9rem; }
        blockquote { margin-inline: 1rem; border-left: 3px solid #174d3b; padding-inline: 1rem; }
        @media print {
            body { max-width: none; padding: 0; color: #000; background: #fff; font-size: 10pt; }
            article { padding-inline: 0; }
            h2, h3, dt { break-after: avoid; }
            blockquote { break-inside: avoid; }
            @page { margin: 16mm; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <p><strong>Fictional / synthetic data. Not live marketplace listings.</strong></p>
        <h1>Fictional demo alerts evaluated against Marcel's public profile</h1>
        <p>These recommendations concern Marcel's approved public profile, not the visitor.
            AI suggestions can be wrong and require human review. No proposal is sent.</p>
    </header>
    <section aria-labelledby="run-heading">
        <h2 id="run-heading">Stored evaluation report</h2>
        <dl>
            <dt>Run identity</dt><dd>{{ $report['id'] }}</dd>
            <dt>Final state</dt><dd>{{ $report['status'] }}{{ $report['status'] === 'completed' && $report['selected_count'] === 0 ? ' (empty selection)' : '' }}</dd>
            <dt>Actual counts</dt><dd>{{ $report['selected_count'] }} selected; {{ $report['succeeded_count'] }} evaluated successfully; {{ $report['failed_count'] }} unsuccessful or unconfirmed.</dd>
            <dt>Requested at (UTC)</dt><dd>{{ $report['admitted_at'] }}</dd>
            <dt>Evaluation run started (UTC)</dt><dd>{{ $report['started_at'] ?? 'Not started' }}</dd>
            <dt>Finalized at (UTC)</dt><dd>{{ $report['finalized_at'] }}</dd>
            <dt>Report expires at (UTC)</dt><dd>{{ $report['expires_at'] }} (seven-day retention)</dd>
        </dl>
        @if ($report['explanation'])
            <p>{{ $report['explanation'] }}</p>
        @endif
        @if ($report['selected_count'] === 0)
            <p>No alerts were selected for evaluation. This is not a recommendation.</p>
        @endif
        @if ($report['scan'])
            <h3>Bounded scan counts (not total inbox size)</h3>
            <dl>
                @foreach ($report['scan'] as $name => $count)
                    <dt>{{ $name }}</dt><dd>{{ $count }}</dd>
                @endforeach
            </dl>
        @endif
    </section>
    <section aria-labelledby="profile-heading">
        <h2 id="profile-heading">Approved public profile and provenance</h2>
        <dl>
            @foreach (['profile_version', 'rubric_version', 'prompt_version', 'manifest_version', 'parser_version', 'schema_version', 'model', 'pricing_version'] as $version)
                <dt>{{ str_replace('_', ' ', $version) }}</dt><dd>{{ $report['approved'][$version] ?? 'Not recorded' }}</dd>
            @endforeach
            <dt>Stored version-set SHA-256</dt><dd>{{ $report['version_hash'] }}</dd>
            <dt>Approved snapshot SHA-256</dt><dd>{{ $report['approved_sha256'] }}</dd>
        </dl>
        <h3>Profile used for this run</h3>
        <dl>
            @foreach ($report['approved']['profile'] ?? [] as $field => $value)
                <dt>{{ $field }}</dt><dd>{{ $value }}</dd>
            @endforeach
        </dl>
        <h3>Approved criteria and outcome meanings</h3>
        <pre>{{ json_encode($report['approved']['rubric'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        <h3>Stored evaluation prompt</h3>
        @foreach ($report['approved']['prompt'] ?? [] as $instruction)
            <p>{{ $instruction }}</p>
        @endforeach
    </section>
    <section aria-labelledby="items-heading">
        <h2 id="items-heading">Individual alerts</h2>
        @foreach ($report['items'] as $item)
            <article aria-labelledby="item-{{ $loop->index }}">
                <h3 id="item-{{ $loop->index }}">{{ $item['source_id'] }}: {{ $item['input']['title'] ?? 'Title unavailable' }}</h3>
                <p><strong>{{ $item['status'] === 'succeeded' && $item['result'] ? $item['result']['recommendation'] : 'UNEVALUATED' }}</strong>
                    &mdash; stored item state: {{ $item['status'] }}</p>
                <p>Alert posted timestamp: {{ $item['input']['posted_at'] ?? 'Unknown' }}. This is not the evaluation time.</p>
                @if ($item['status'] !== 'succeeded' || ! $item['result'])
                    <p>{{ $item['explanation'] ?? 'No confirmed recommendation was available when this report finalized.' }}</p>
                @else
                    @foreach ($item['result']['reasons'] ?? [] as $reason)
                        <h4>{{ $reason['criterion_id'] }}</h4>
                        <p>{{ $reason['explanation'] }}</p>
                        @foreach ($reason['evidence'] as $evidence)
                            <blockquote><p>{{ $evidence['quote'] }}</p><footer>Stored evidence: {{ $evidence['field'] }}</footer></blockquote>
                        @endforeach
                    @endforeach
                    <h4>Missing information</h4>
                    <p>{{ $item['result']['missing_information'] ? implode(', ', $item['result']['missing_information']) : 'None identified by the evaluation; not a guarantee of complete information.' }}</p>
                @endif
                <h4>Stored public input</h4>
                <pre>{{ json_encode($item['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                <p>Input SHA-256: {{ $item['input_sha256'] }}</p>
                <h4>Provider provenance at finalization</h4>
                @forelse ($item['attempts'] as $attempt)
                    <dl>
                        <dt>Attempt / recorded outcome</dt><dd>{{ $attempt['number'] }} / {{ $attempt['status'] }}</dd>
                        <dt>Provider</dt><dd>{{ $attempt['provider'] ?? 'Not recorded' }}</dd>
                        <dt>Inference authorized (UTC)</dt><dd>{{ $attempt['evaluation_started_at'] ?? 'Not recorded' }}</dd>
                        <dt>Outcome settled (UTC)</dt><dd>{{ $attempt['settled_at'] ?? 'Not confirmed at finalization' }}</dd>
                        <dt>Requested / returned model</dt><dd>{{ $attempt['requested_model'] ?? 'Not recorded' }} / {{ $attempt['returned_model'] ?? 'Not recorded' }}</dd>
                        <dt>Provider request identity</dt><dd>{{ $attempt['provider_request_id'] ?? 'Not recorded' }}</dd>
                        <dt>Request SHA-256</dt><dd>{{ $attempt['request_sha256'] ?? 'Not recorded' }}</dd>
                        <dt>Stored request settings</dt><dd>{{ json_encode($attempt['settings'], JSON_UNESCAPED_SLASHES) }}</dd>
                    </dl>
                @empty
                    <p>No provider attempt recorded.</p>
                @endforelse
            </article>
        @endforeach
    </section>
    <p>This immutable report records what was known at finalization. Later accounting reconciliation
        does not create recommendations or rewrite this report. Fictional source identities have no marketplace links.</p>
</main>
</body>
</html>
