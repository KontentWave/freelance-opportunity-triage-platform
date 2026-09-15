<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | Opportunity Review</title>
    @vite(['resources/css/app.css'])
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-intro">
            <p class="eyebrow">Private workspace</p>
            <h1>Opportunity Review</h1>
            <p>Inspect saved email evidence and make your own decision. Suggestions remain experimental.</p>
        </section>
        <form class="login-form" method="POST" action="/login">
            @csrf
            <div>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
            </div>
            @error('email')<p class="error-banner" role="alert">{{ $message }}</p>@enderror
            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="primary-button" type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
