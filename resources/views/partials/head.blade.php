<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/favicon/favicon.svg" />
<link rel="shortcut icon" href="/favicon/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />
<meta name="apple-mobile-web-app-title" content="MyWebSite" />
<link rel="manifest" href="/favicon/site.webmanifest" />

{{-- Bunny Fonts: é o único host de fontes liberado no CSP (SecurityHeaders); Google Fonts era bloqueado. --}}
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link href="https://fonts.bunny.net/css?family=nunito-sans:300,400,500,600,700,400i|montserrat:300,400,500,600,700&display=swap" rel="stylesheet">
{{-- Avenir LT Pro (system font on macOS/iOS) with Nunito Sans as web fallback --}}
{{-- Gotham Book with Montserrat as web fallback --}}

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if(isset($currentCompany))
    <x-company-theme :company="$currentCompany" />
@endif
@if(!($disableAppearance ?? false))
    @fluxAppearance
@endif
