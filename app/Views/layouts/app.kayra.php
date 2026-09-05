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
            --text: #14181f; --dim: #667085; --accent: #4f46e5; --ok: #059669; --bad: #dc2626;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b0d10; --panel: #14181d; --line: #242a31;
                --text: #e6e8ea; --dim: #8a939c; --accent: #818cf8; --ok: #34d399; --bad: #f87171;
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
        /* --- nav, forms and flashes (added by the demo app) --- */
        nav.top { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;
                  padding: 0 0 1rem; margin-bottom: 1.75rem; border-bottom: 1px solid var(--line); }
        nav.top .home { font-weight: 640; letter-spacing: -0.02em; text-decoration: none; }
        nav.top .home span { color: var(--accent); }
        nav.top .spacer { flex: 1; }
        nav.top .who { color: var(--dim); font-size: 0.88rem; }
        .post { background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
                padding: 1rem 1.15rem; margin-bottom: 1rem; }
        .post h3 { margin: 0 0 0.3rem; font-size: 1.1rem; }
        .post p { margin: 0.55rem 0 0; white-space: pre-wrap; }
        .post .meta { color: var(--dim); font-size: 0.85rem; }
        .post .actions { margin-top: 0.7rem; display: flex; gap: 0.9rem; align-items: center; font-size: 0.9rem; }
        label { display: block; font-size: 0.86rem; color: var(--dim); margin: 1rem 0 0.3rem; }
        input, textarea { width: 100%; padding: 0.6rem 0.7rem; border: 1px solid var(--line);
                          border-radius: 7px; background: var(--bg); color: var(--text); font: inherit; }
        textarea { min-height: 9rem; resize: vertical; }
        button { margin-top: 1.1rem; padding: 0.6rem 1.1rem; border: 0; border-radius: 7px;
                 background: var(--accent); color: #fff; font: inherit; font-weight: 560; cursor: pointer; }
        button.link { margin: 0; padding: 0; background: none; color: var(--bad);
                      font-weight: 400; text-decoration: underline; }
        form.inline { display: inline; }
        .flash, .errors { padding: 0.7rem 0.9rem; border-radius: 7px; margin-bottom: 1.25rem; }
        .flash { background: color-mix(in srgb, var(--ok) 12%, transparent);
                 border: 1px solid color-mix(in srgb, var(--ok) 35%, transparent); }
        .errors { background: color-mix(in srgb, var(--bad) 10%, transparent);
                  border: 1px solid color-mix(in srgb, var(--bad) 35%, transparent); }
        .errors ul { margin: 0.25rem 0 0; padding-left: 1.1rem; }
        .empty { color: var(--dim); padding: 2.5rem 0; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <nav class="top">
        <a class="home" href="{{ url('/') }}">Kayra<span>PHP</span></a>
        <a href="{{ url('/posts') }}">Posts</a>
        <a href="{{ route('docs') }}">Docs</a>
        <span class="spacer"></span>
        @isset($currentUser)
            <span class="who">{{ $currentUser->name }}</span>
            <form class="inline" method="post" action="{{ url('/logout') }}">
                @csrf
                <button class="link" type="submit">Sign out</button>
            </form>
        @else
            <a href="{{ url('/login') }}">Sign in</a>
            <a href="{{ url('/register') }}">Register</a>
        @endisset
    </nav>

    @isset($status)
        <div class="flash">{{ $status }}</div>
    @endisset

    @if(!empty($errors))
        <div class="errors">
            <strong>Please fix the following:</strong>
            <ul>
                @foreach($errors as $messages)
                    @foreach($messages as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif
    @yield('content')

    <footer>
        KayraPHP {{ $version ?? '' }} &middot; PHP {{ PHP_VERSION }} &middot;
        <a href="{{ route('docs') }}">Documentation</a>
    </footer>
</div>
</body>
</html>
