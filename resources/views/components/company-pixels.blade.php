@props(['company'])
@php
    // Re-checked here (not just trusted from DB) so a value written outside the
    // Livewire form (seeder, tinker, future admin tool) can never reach inline JS
    // with an unexpected charset.
    $fbPixelId = preg_match('/^[0-9]{5,20}$/', (string) $company->facebook_pixel_id) ? $company->facebook_pixel_id : null;
    $gaId = preg_match('/^G-[A-Z0-9]{4,12}$/', (string) $company->google_analytics_id) ? $company->google_analytics_id : null;
    $adsId = preg_match('/^AW-[0-9]{6,12}$/', (string) $company->google_ads_id) ? $company->google_ads_id : null;
@endphp
@if($gaId || $adsId)
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId ?: $adsId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){ dataLayer.push(arguments); }
        gtag('js', new Date());
        @if($gaId)
            gtag('config', '{{ $gaId }}');
        @endif
        @if($adsId)
            gtag('config', '{{ $adsId }}');
        @endif
    </script>
@endif
@if($fbPixelId)
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '{{ $fbPixelId }}');
        fbq('track', 'PageView');
    </script>
    <noscript>
        <img height="1" width="1" style="display:none" alt=""
             src="https://www.facebook.com/tr?id={{ $fbPixelId }}&ev=PageView&noscript=1" />
    </noscript>
@endif
