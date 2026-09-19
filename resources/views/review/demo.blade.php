<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Synthetic review demonstration</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main class="login-shell">
        <section class="login-panel" aria-labelledby="demo-heading">
            <p class="eyebrow">Synthetic data only</p>
            <h1 id="demo-heading">Review dashboard demonstration</h1>
            <p>This shared demonstration uses fixed fictional opportunities and may be reset.</p>
            <form method="POST" action="{{ route('demo-session.store') }}">
                @csrf
                <button class="primary-button" type="submit">Start demo</button>
            </form>
        </section>
    </main>
</body>
</html>
