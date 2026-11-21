<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#117c66">
    <title>ClapsLearn Operations</title>
    @if(!empty($manifest['css']))<link rel="stylesheet" href="{{ $manifest['css'] }}">@endif
    @if(!empty($manifest['js']))<script type="module" src="{{ $manifest['js'] }}"></script>@endif
</head>
<body>
<script type="application/json" id="erp-session">{!! json_encode($session, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<div id="erp-root"></div>
<noscript>This application needs JavaScript. <a href="/">Use the classic screens</a>.</noscript>
</body>
</html>
