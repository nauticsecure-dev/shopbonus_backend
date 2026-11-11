<!doctype html>
<html lang="{{ $locale }}"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#182331">
@include('mail.brand')
<h1>{{ __($confirmation ? 'contact.confirmation_subject' : 'contact.support_subject', [], $locale) }}</h1>
@if ($confirmation)
<p>{{ __('contact.confirmation_body', [], $locale) }}</p>
@else
<p>{{ $submission['name'] }} · {{ $submission['email'] }}</p>
<h2>{{ $submission['subject'] }}</h2>
<p style="white-space:pre-wrap">{{ $submission['message'] }}</p>
@endif
</body></html>
