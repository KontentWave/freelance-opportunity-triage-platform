<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Fictional alert evaluation</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#main">Skip to evaluation</a>
    <main id="main">
        <div id="demo-ai-app"
             data-intent-url="{{ route('demo-ai.create', [], false) }}"
             data-admission-url="{{ route('demo-ai.store', [], false) }}"
             data-status-base="{{ route('demo-ai.store', [], false) }}"></div>
    </main>
</body>
</html>
