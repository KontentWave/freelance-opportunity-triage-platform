<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="review-mode" content="{{ config('opportunity_review.mode') }}">
    <meta name="demo-ai-source-mode" content="{{ config('demo_ai.source_mode', 'authored') }}">
    <title>Review queue</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#main">Skip to review queue</a>
    <main id="main">
        <div id="review-queue-app"
             @if (($evaluatedProfile ?? null) !== null)
                 data-evaluated-profile="{{ json_encode($evaluatedProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
             @endif></div>
    </main>
</body>
</html>
