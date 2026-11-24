@props(['date', 'identity', 'label' => null, 'timeOnly' => false])

@php
    $locale = app()->getLocale();
    $utc = $date->copy()->utc()->locale($locale);
@endphp

<span>
    @if ($label)
        {{ $label }}
    @endif
    <time
        wire:key="local-time-{{ $identity }}-{{ $utc->getTimestamp() }}-{{ $locale }}"
        wire:ignore
        datetime="{{ $utc->toIso8601String() }}"
        lang="{{ $locale }}"
        @if ($timeOnly) data-time-only="true" @endif
        x-data
        x-init="$el.textContent = new Intl.DateTimeFormat($el.lang, {
            ...($el.hasAttribute('data-time-only') ? {} : { day: '2-digit', month: 'short' }),
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).format(new Date($el.dateTime))"
    >{{ $utc->translatedFormat($timeOnly ? 'H:i' : 'd M, H:i') }} UTC</time>
</span>
