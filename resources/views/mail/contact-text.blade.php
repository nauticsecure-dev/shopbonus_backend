ShopBonus

{{ __($confirmation ? 'contact.confirmation_subject' : 'contact.support_subject', [], $locale) }}

@if ($confirmation)
{{ __('contact.confirmation_body', [], $locale) }}
@else
{{ $submission['name'] }} · {{ $submission['email'] }}
{{ $submission['subject'] }}

{{ $submission['message'] }}
@endif
