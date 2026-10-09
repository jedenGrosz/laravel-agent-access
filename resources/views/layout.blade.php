<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Laravel Agent Access</title>
    <style>
        :root{color-scheme:light;--ink:#17243a;--muted:#66758b;--line:#dce4ee;--blue:#215cdc}
        *{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:var(--ink);font:15px/1.6 system-ui,sans-serif}
        a{color:var(--blue)}header{background:#13233c;color:white;padding:22px max(24px,calc((100vw - 1120px)/2));display:flex;justify-content:space-between;gap:20px}
        header strong{font-size:19px}header small{color:#bed0ec}main{max-width:1120px;margin:32px auto;padding:0 24px}
        nav{display:flex;gap:24px;border-bottom:1px solid var(--line);padding-bottom:16px;margin-bottom:28px;flex-wrap:wrap}nav a{text-decoration:none;font-weight:600}
        h1{font-size:30px;line-height:1.2;margin:12px 0}h2{font-size:19px}p{margin:8px 0 20px}.muted{color:var(--muted)}
        .card{background:white;border:1px solid var(--line);border-radius:12px;padding:24px;margin:20px 0}.table{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:left}th{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}td,th{padding:14px 12px;border-bottom:1px solid var(--line)}tr:last-child td{border-bottom:0}
        .badge{display:inline-block;background:#eaf0ff;color:#234eab;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:700}.notice{background:#e4f6ee;color:#165c40;padding:12px 18px;border-radius:8px}.actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
        button,.button{border:0;border-radius:7px;background:var(--blue);color:white;padding:11px 16px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}.secondary{background:#e9eef6;color:var(--ink)}.danger{background:#ae3246}
        input,select,textarea{border:1px solid #b8c5d8;border-radius:7px;padding:10px;font:inherit;max-width:100%}textarea{display:block;width:100%;min-height:90px;margin:8px 0 16px}.actions + form{margin-top:20px}label{font-weight:600}dl{display:grid;grid-template-columns:180px 1fr;gap:10px}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}code,pre{font-family:ui-monospace,monospace}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f6fb;border-radius:8px;padding:18px}.small{font-size:12px}.empty{text-align:center;padding:36px;color:var(--muted)}:focus-visible{outline:3px solid #efad35;outline-offset:3px}
        @media(max-width:640px){header{flex-direction:column;gap:4px}main{padding:0 16px}.card{padding:16px}dl{grid-template-columns:1fr;gap:4px}dd{margin-bottom:12px}}
    </style>
</head>
<body>
<header><strong>Laravel Agent Access</strong><small>{{ __('Signed in as') }} {{ $principalId }}</small></header>
<main>
    <nav aria-label="{{ __('Access navigation') }}">
        <a href="{{ route('agent-access.panel.requests') }}">{{ __('Requests') }}</a>
        <a href="{{ route('agent-access.panel.grants') }}">{{ __('Grants') }}</a>
        @if($canInspect)<a href="{{ route('agent-access.panel.hierarchy') }}">{{ __('Hierarchy & rules') }}</a>@endif
    </nav>
    @if(session('agent_access_status'))<p class="notice" role="status">{{ session('agent_access_status') }}</p>@endif
    @if($errors->any())<p class="notice" role="alert">{{ __('Check the submitted fields and try again.') }}</p>@endif
    @yield('content')
    <p class="muted small">{{ __('Authority comes from the current IAM and a complete grant rule. One use means one attempt.') }}</p>
</main>
</body></html>
