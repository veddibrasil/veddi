@props(['company'])
<style>
    :root {
        --mc-red:        {{ $company->primary_color }};
        --mc-red-dark:   {{ $company->primary_color_dark }};
        --mc-red-light:  {{ $company->primary_color_light }};
        --mc-gold:       {{ $company->secondary_color }};
        --mc-gold-light: {{ $company->secondary_color_light }};
        --mc-cream:      {{ $company->accent_color }};
    }
</style>
