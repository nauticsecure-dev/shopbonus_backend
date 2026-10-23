<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shopbonus API</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; }
        .bar { display: flex; gap: 16px; align-items: center; padding: 10px 16px; background: #1d4ed8; color: #fff; font-size: 14px; }
        .bar a { color: #fff; }
        .bar strong { font-size: 15px; }
    </style>
</head>
<body>
    <div class="bar">
        <strong>Shopbonus API v1</strong>
        <a href="{{ route('docs.index') }}">OpenAPI</a>
        <a href="{{ route('docs.reference') }}">Reference with captured examples</a>
        <a href="{{ route('docs.spec') }}">openapi.yaml</a>
    </div>
    <redoc spec-url="{{ route('docs.spec') }}" hide-download-button="false"></redoc>
    <script src="https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js"></script>
</body>
</html>
