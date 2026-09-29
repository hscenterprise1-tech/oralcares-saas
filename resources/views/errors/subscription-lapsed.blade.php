{{--
    Shown when a tenant's subscription window has lapsed.

    Deliberately self-contained. The sibling error views extend `errors::minimal`,
    a hint path the framework only registers while it is rendering an exception —
    so a view returned directly from middleware, as this one is, cannot use it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Access Ended') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            padding: 24px;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0b1220; color: #e2e8f0; }
            .card { background: #111c30; border-color: #1e293b; }
            .code { color: #64748b; }
        }
        .card {
            max-width: 32rem;
            width: 100%;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            padding: 40px;
            text-align: center;
        }
        .code { font-size: 0.75rem; letter-spacing: 0.12em; text-transform: uppercase; color: #94a3b8; margin: 0 0 12px; }
        h1 { font-size: 1.375rem; margin: 0 0 12px; }
        p { margin: 0; line-height: 1.6; color: inherit; opacity: 0.85; }
    </style>
</head>
<body>
    <main class="card">
        <p class="code">402 &middot; {{ __('Access Ended') }}</p>
        <h1>{{ __('Your access has ended') }}</h1>
        <p>{{ $message ?? __('Contact your account manager to restore it. Your clinic data is retained.') }}</p>
    </main>
</body>
</html>
