{{ __('password-reset.intro', ['app' => config('app.name')]) }}

{{ $code }}

{{ __('password-reset.expiry', ['minutes' => $minutes]) }}
{{ __('password-reset.unrequested') }}
