<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Review queue</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#main">Skip to review queue</a>
    <main id="main"><div id="review-queue-app"></div></main>
</body>
</html>
