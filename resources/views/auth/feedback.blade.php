@if ($errors->any())
    <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
        <strong>{{ $errorHeading ?? __('auth-ui.check_details') }}</strong>
        <ul>
            @foreach ($errors->getMessages() as $field => $messages)
                <li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>
            @endforeach
        </ul>
    </div>
@endif
