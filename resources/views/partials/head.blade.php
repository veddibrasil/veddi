<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/favicon/favicon.svg" />
<link rel="shortcut icon" href="/favicon/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />
<meta name="apple-mobile-web-app-title" content="MyWebSite" />
<link rel="manifest" href="/favicon/site.webmanifest" />

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,300;0,6..12,400;0,6..12,500;0,6..12,600;0,6..12,700;1,6..12,400&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
{{-- Avenir LT Pro (system font on macOS/iOS) with Nunito Sans as web fallback --}}
{{-- Gotham Book with Montserrat as web fallback --}}

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if(isset($currentCompany))
    <x-company-theme :company="$currentCompany" />
@endif
@if(!($disableAppearance ?? false))
    @fluxAppearance
@endif
