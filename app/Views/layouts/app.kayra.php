<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'KayraPHP')</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #ffffff; --panel: #f7f8fa; --line: #e4e7ec;
            --text: #14181f; --dim: #667085; --accent: #4f46e5; --ok: #059669;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b0d10; --panel: #14181d; --line: #242a31;
                --text: #e6e8ea; --dim: #8a939c; --accent: #818cf8; --ok: #34d399;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--text);
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .wrap { max-width: 62rem; margin: 0 auto; padding: 3rem 1.5rem 4rem; }
        header { border-bottom: 1px solid var(--line); }
        .brand { font-size: 2.25rem; font-weight: 680; letter-spacing: -0.025em; margin: 0; }
        .brand span { color: var(--accent); }
        .tag { color: var(--dim); margin: 0.35rem 0 0; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); margin: 2rem 0; }
        .card {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 10px; padding: 1rem 1.1rem;
        }
        .card dt { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.07em; color: var(--dim); margin: 0; }
        .card dd { margin: 0.3rem 0 0; font-size: 1.35rem; font-weight: 620; }
        h2 { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--dim); margin: 2.5rem 0 0.75rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        td, th { padding: 0.55rem 0.5rem; border-bottom: 1px solid var(--line); text-align: left; }
        th { color: var(--dim); font-weight: 500; }
        code {
            font: 0.88em ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 4px; padding: 0.1em 0.4em;
        }
        .yes { color: var(--ok); font-weight: 600; }
        .no { color: var(--dim); }
        a { color: var(--accent); }
        footer { margin-top: 3rem; padding-top: 1.25rem; border-top: 1px solid var(--line); color: var(--dim); font-size: 0.88rem; }
    </style>
</head>
<body>
<div class="wrap">
    @yield('content')

    <footer>
        KayraPHP {{ $version ?? '' }} &middot; PHP {{ PHP_VERSION }} &middot;
        <a href="{{ route('docs') }}">Documentation</a>
    </footer>
</div>
</body>
</html>
