<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#117c66">
    <title>{{ $title ?? 'ClapsLearn Operations' }}</title>
    @if(!empty($manifest['css']))<link rel="stylesheet" href="{{ $manifest['css'] }}">@endif
    @if(!empty($manifest['js']))<script type="module" src="{{ $manifest['js'] }}"></script>@endif
</head>
<body>
<script type="application/json" id="erp-session">{!! json_encode($session, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<div id="erp-root">@if(empty($manifest['js']))<p style="font:16px system-ui;padding:24px">The new app has not been built on this server (run <code>npm run production</code>).</p>@endif</div>
<noscript>ClapsLearn needs JavaScript. Turn it on in your browser settings and reload.</noscript>
</body>
</html>
