<!doctype html>
<html lang="{{ $locale }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:20px;font-family:Arial,sans-serif;background:#f5f7fa;color:#182331">
<table role="presentation" style="width:100%;max-width:600px;margin:auto;background:white;padding:24px"><tr><td>
@include('mail.brand')
<h1 style="font-size:24px">{{ $title }}</h1><p>{{ $body }}</p><p><a href="{{ $url }}">{{ $action_label }}</a></p>
<p style="font-size:12px">{{ __('identity.notification_current_details', [], $locale) }}</p>
</td></tr></table></body></html>
