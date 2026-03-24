@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: block; text-decoration: none; background: linear-gradient(135deg, #5c0079 0%, #7A00A3 60%, #9B10C8 100%); padding: 28px 40px; text-align: center;">
    <img src="{{ asset('logo_branca.png') }}" alt="{{ config('app.name') }}" style="height: 48px; width: 48px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,0.4); display: block; margin: 0 auto 10px;">
    <span style="color: #fff; font-size: 20px; font-weight: 800; letter-spacing: -0.3px; display: block;">{{ config('app.name') }}</span>
</a>
</td>
</tr>
