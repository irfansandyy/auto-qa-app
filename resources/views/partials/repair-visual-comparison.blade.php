@php
    $comparison = $run->verification['visual_comparison'] ?? [];
    $before = $comparison['before'] ?? null;
    $after = $comparison['after'] ?? null;
@endphp

@if (is_string($before) && is_string($after)
    && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.png\z/', $before)
    && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.png\z/', $after))
    <section class="finding-section repair-comparison" aria-labelledby="repair-comparison-heading">
        <h3 id="repair-comparison-heading">{{ __('repair-comparison.title') }}</h3>
        <p>{{ __('repair-comparison.description') }}</p>
        <div class="repair-comparison-grid">
            @foreach (['before' => $before, 'after' => $after] as $stage => $image)
                <figure>
                    <figcaption>{{ __('repair-comparison.'.$stage) }}</figcaption>
                    <a href="{{ route('runs.evidence', [$run, $image]) }}">
                        <img src="{{ route('runs.evidence', [$run, $image]) }}" alt="{{ __('repair-comparison.'.$stage.'_alt') }}" loading="lazy" decoding="async">
                    </a>
                </figure>
            @endforeach
        </div>
    </section>
@endif
