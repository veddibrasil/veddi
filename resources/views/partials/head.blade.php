<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,300;0,6..12,400;0,6..12,500;0,6..12,600;0,6..12,700;1,6..12,400&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
{{-- Avenir LT Pro (system font on macOS/iOS) with Nunito Sans as web fallback --}}
{{-- Gotham Book with Montserrat as web fallback --}}

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if(isset($currentCompany))
    <x-company-theme :company="$currentCompany" />
@endif
@fluxAppearance
