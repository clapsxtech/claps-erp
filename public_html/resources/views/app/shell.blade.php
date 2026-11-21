<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#117c66">
    {{-- Installable from the phone browser ("Add to Home screen"): parents get their own app that opens on their portal. --}}
    <link rel="manifest" href="{{ request()->is('parent', 'parent/*') ? '/parent.webmanifest' : '/manifest.webmanifest' }}">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ClapsLearn">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="format-detection" content="telephone=no">
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
