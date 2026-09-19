<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') &mdash; {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --accent: #d97706;
            --accent-text: #ffffff;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0a0a0a;
                --card: #171717;
                --border: #262626;
                --text: #fafafa;
                --muted: #a3a3a3;
                --accent: #f59e0b;
                --accent-text: #1c1917;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
        }

        .card {
            width: 100%;
            max-width: 30rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 40px 32px;
            text-align: center;
        }

        .code {
            font-size: 0.8125rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 0 0 12px;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 650;
            margin: 0 0 12px;
        }

        p {
            margin: 0 0 28px;
            color: var(--muted);
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        a.button {
            display: inline-block;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid transparent;
        }

        a.primary { background: var(--accent); color: var(--accent-text); }
        a.secondary { border-color: var(--border); color: var(--text); }
        a.button:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <main class="card">
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>

        <div class="actions">
            <a class="button primary" href="{{ url('/admin') }}">{{ __('admin.errors.back_to_dashboard') }}</a>
            <a class="button secondary" href="javascript:history.back()">{{ __('admin.errors.go_back') }}</a>
        </div>
    </main>
</body>
</html>
