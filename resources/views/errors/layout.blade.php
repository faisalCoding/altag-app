@php
    // An error page may be drawn because the database is the thing that
    // failed, so the academy's name and colour are asked for, not relied on.
    $siteName = rescue(fn () => \App\Support\Branding::siteName(), \App\Support\Branding::DEFAULT_NAME, false);
    $color = rescue(fn () => \App\Support\Branding::color(), \App\Support\Branding::DEFAULT_COLOR, false);
    $logo = rescue(fn () => \App\Support\Branding::logoUrl(), asset(\App\Support\Branding::DEFAULT_LOGO), false);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') - {{ $siteName }}</title>
    {{-- Self-contained on purpose: no Vite, no Livewire, so the page still
         shows when the build or the app behind it is what broke. --}}
    <style>
        @font-face { font-family: 'Lama Sans'; font-weight: 400; font-display: swap; src: url('/fonts/lama-sans/lama-sans-400.woff2') format('woff2'); }
        @font-face { font-family: 'Lama Sans'; font-weight: 700; font-display: swap; src: url('/fonts/lama-sans/lama-sans-700.woff2') format('woff2'); }
        :root { --brand: {{ $color }}; --bg: #fafafa; --card: #ffffff; --text: #171717; --muted: #737373; --line: #e5e5e5; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0a0a0a; --card: #171717; --text: #f5f5f5; --muted: #a3a3a3; --line: #262626; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--text); font-family: 'Lama Sans', system-ui, sans-serif; }
        main { width: 100%; max-width: 420px; text-align: center; background: var(--card); border: 1px solid var(--line);
               border-radius: 24px; padding: 32px 24px; }
        img { display: block; height: 72px; width: auto; margin: 0 auto 16px; }
        .code { display: inline-block; font-weight: 700; font-size: 13px; color: var(--brand); border: 1px solid var(--line);
                border-radius: 999px; padding: 2px 12px; margin-bottom: 12px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: var(--muted); line-height: 1.8; margin: 0 0 24px; }
        .actions { display: flex; flex-direction: column; gap: 8px; }
        a, button { display: block; width: 100%; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer;
                    border-radius: 14px; padding: 12px 16px; border: 1px solid transparent; }
        .primary { background: var(--brand); color: #fff; }
        .secondary { background: transparent; color: var(--text); border-color: var(--line); }
    </style>
</head>
<body>
    <main>
        <img src="{{ $logo }}" alt="{{ $siteName }}">
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            @hasSection('actions')
                @yield('actions')
            @else
                <a class="primary" href="{{ url('/') }}">العودة إلى الرئيسية</a>
                <button class="secondary" type="button" onclick="history.back()">الرجوع للصفحة السابقة</button>
            @endif
        </div>
    </main>
</body>
</html>
