<!DOCTYPE html>
<html>
<head>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans Mono, monospace; font-size: 9px; padding: 10px; }
    </style>
</head>
<body>
    @for ($i = 0; $i < $lines; $i++)
        <div>Linha {{ $i }}</div>
    @endfor
</body>
</html>
