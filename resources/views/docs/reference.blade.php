<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shopbonus API reference</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; color: #111827; background: #f8fafc; }
        .bar { display: flex; gap: 16px; align-items: center; padding: 10px 16px; background: #1d4ed8; color: #fff; font-size: 14px; }
        .bar a { color: #fff; }
        main { max-width: 980px; margin: 0 auto; padding: 24px 16px 64px; line-height: 1.55; }
        pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 13px; }
        code { font-size: 13px; }
        :not(pre) > code { background: #e2e8f0; padding: 1px 4px; border-radius: 4px; }
        table { border-collapse: collapse; display: block; overflow-x: auto; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 8px; text-align: left; vertical-align: top; }
        h2 { border-top: 1px solid #cbd5e1; padding-top: 24px; margin-top: 40px; }
    </style>
</head>
<body>
    <div class="bar">
        <strong>Shopbonus API v1</strong>
        <a href="{{ route('docs.index') }}">OpenAPI</a>
        <a href="{{ route('docs.reference') }}">Reference with captured examples</a>
        <a href="{{ route('docs.spec') }}">openapi.yaml</a>
    </div>
    <main>{!! $html !!}</main>
</body>
</html>
