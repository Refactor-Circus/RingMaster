<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $action->label }} · RingMaster</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #09090b; color: #e4e4e7; font: 16px/1.5 system-ui, sans-serif; }
        main { width: min(28rem, calc(100% - 2rem)); padding: 1.5rem; border: 1px solid #27272a; border-radius: 1rem; background: #18181b; }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; color: #fff; }
        p { margin: .5rem 0; color: #a1a1aa; white-space: pre-line; }
        button { width: 100%; margin-top: 1rem; padding: .85rem; border: 0; border-radius: .75rem; background: linear-gradient(90deg, #22d3ee, #818cf8, #e879f9); color: #09090b; font: inherit; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <h1>{{ $action->label }}</h1>
    @if ($done !== null)
        <p>{{ $done }}</p>
    @elseif ($usable)
        <p>Expires {{ $action->expires_at->diffForHumans() }}. Works once.</p>
        <form method="post">
            @csrf
            <button type="submit">{{ $action->label }}</button>
        </form>
    @else
        <p>This link was already used or has expired. Open RingMaster to do it there.</p>
    @endif
</main>
</body>
</html>
