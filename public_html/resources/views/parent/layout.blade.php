<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#117c66"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex"><title>@yield('title','Parent portal') · ClapsLearn</title><link rel="stylesheet" href="{{ mix('css/erp.css') }}"></head>
<body class="erp-app teacher-dashboard parent-portal"><a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-body" style="margin-left:0"><header class="app-topbar"><div class="breadcrumb"><strong>ClapsLearn</strong><span>Parent portal</span></div>@isset($student)<form method="post" action="{{ route('parent.logout') }}" style="margin:0;flex:none;width:auto;display:block">@csrf<button class="button button-secondary" type="submit" style="width:auto;min-height:36px;padding:6px 14px">Sign out</button></form>@endisset</header>
<main class="app-main" id="main-content" tabindex="-1">
@if(session('status'))<p class="success" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@yield('content')
</main></div></body></html>
