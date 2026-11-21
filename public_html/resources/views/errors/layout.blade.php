<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · ClapsLearn</title>
    <style>
        :root { color-scheme: light dark; --ink: #1a1d1c; --muted: #5b6460; --bg: #f6f7f6; --card: #fff; --brand: #117c66; }
        @media (prefers-color-scheme: dark) { :root { --ink: #eceeed; --muted: #a7b0ac; --bg: #121514; --card: #1b1f1e; --brand: #4fc3a1; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--ink);
            font: 16px/1.5 Inter, system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 440px; background: var(--card); border-radius: 16px; padding: 32px; box-shadow: 0 1px 2px rgba(0,0,0,.06), 0 8px 24px rgba(0,0,0,.06); }
        .code { color: var(--brand); font-weight: 600; letter-spacing: .04em; }
        h1 { margin: 8px 0; font-size: 24px; line-height: 1.25; }
        p { margin: 0 0 24px; color: var(--muted); }
        a { display: inline-block; padding: 10px 16px; border-radius: 10px; background: var(--brand); color: #fff; text-decoration: none; font-weight: 600; }
        a:focus-visible { outline: 3px solid var(--ink); outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <div class="code">@yield('code')</div>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    @hasSection('action')@yield('action')@else<a href="/">Go to ClapsLearn</a>@endif
</main>
</body>
</html>
