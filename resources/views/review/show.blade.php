<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Opportunity details</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#main">Skip to opportunity details</a>
    <main id="main"><div id="opportunity-app" data-opportunity-id="{{ $opportunityId }}"></div></main>
</body>
</html>
