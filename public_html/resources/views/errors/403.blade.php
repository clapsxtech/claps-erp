<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>No access · ClapsLearn</title>
<style>
  /* Self-contained so it renders whatever caused the refusal. */
  :root { color-scheme: light; --ink: #1f2a2e; --muted: #5b6b70; --line: #dfe7e5; --brand: #13775f; --bg: #f5f7f6; --card: #fff; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 28px 24px; }
  .brand { font-size: 14px; font-weight: 600; color: var(--muted); margin: 0 0 16px; }
  h1 { font-size: 22px; line-height: 1.3; margin: 0 0 12px; }
  p { margin: 0 0 12px; color: var(--muted); }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
  a, button { font: inherit; font-weight: 600; border-radius: 10px; padding: 10px 16px; cursor: pointer; text-decoration: none; }
  a.primary { background: var(--brand); color: #fff; border: 1px solid var(--brand); }
  button { color: var(--brand); border: 1px solid var(--line); background: #fff; }
</style>
</head>
<body>
<main>
  <p class="brand">ClapsLearn</p>
  <h1>You don't have access to this page</h1>
  @php($reason = trim((string) ($exception->getMessage() ?? '')))
  @if($reason !== '' && !in_array($reason, ['Forbidden', 'This action is unauthorized.'], true))
    <p>{{ $reason }}</p>
  @endif
  <p>If you think you should, ask an administrator to check your team and role.</p>
  <div class="actions">
    <a class="primary" href="{{ url('/') }}">Go to home</a>
    <button type="button" onclick="history.back()">Go back</button>
  </div>
</main>
</body>
</html>
