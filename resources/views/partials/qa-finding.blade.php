@php
    $findingCategory = $finding->evidence['category'] ?? '';
    $findingSectorLabel = __('qa-sectors.names')[$finding->evidence['sector'] ?? ''] ?? null;
    $findingCategoryLabel = $findingSectorLabel ?? __('ui.audit.category')[$findingCategory] ?? null;
    $findingRule = $finding->evidence['rule'] ?? $finding->evidence['code'] ?? null;
    $advisoryUrl = $finding->evidence['advisory_url'] ?? null;
@endphp
<article class="finding" wire:key="finding-{{ $finding->id }}">
    <div class="finding-heading">
        <div>
            @if ($findingCategoryLabel)
                <p class="field-hint finding-category">{{ $findingCategoryLabel }}</p>
            @endif
            <h4>{{ $finding->title }}</h4>
        </div>
        <span class="badge {{ in_array($finding->severity, ['critical', 'high', 'medium'], true) ? 'badge-warning' : 'badge-neutral' }}">{{ __('ui.severity.'.$finding->severity) }}</span>
    </div>
    <dl>
        <dt>{{ __('ui.expected') }}</dt>
        <dd>{{ $finding->expected }}</dd>
        <dt>{{ __('ui.observed') }}</dt>
        <dd>{{ $finding->actual }}</dd>
    </dl>
    @if (is_string($findingRule))
        <p class="field-hint finding-location">{{ __('qa-sectors.rule') }} <code>{{ $findingRule }}</code></p>
    @endif
    @if (is_string($finding->evidence['path'] ?? null))
        <p class="field-hint finding-location">{{ __('ui.audit.location') }} <code>{{ $finding->evidence['path'] }}{{ isset($finding->evidence['line']) ? ':'.$finding->evidence['line'] : '' }}</code></p>
    @elseif (is_string($finding->evidence['url'] ?? null))
        <p class="field-hint finding-location">{{ __('ui.audit.location') }} <code>{{ $finding->evidence['url'] }}</code></p>
    @endif
    @if (is_string($finding->evidence['remediation'] ?? null))
        <div class="finding-remediation">
            <h5>{{ __('ui.audit.remediation') }}</h5>
            <p>{{ $finding->evidence['remediation'] }}</p>
        </div>
    @endif
    @if (is_string($finding->evidence['suggested_change'] ?? null))
        <details class="evidence-details">
            <summary>{{ __('ui.audit.suggested_change') }}</summary>
            <pre>{{ $finding->evidence['suggested_change'] }}</pre>
        </details>
    @endif
    @if (is_string($advisoryUrl) && preg_match('~\Ahttps://(?:osv\.dev/vulnerability/[A-Za-z0-9._-]+|github\.com/advisories/GHSA-[A-Za-z0-9-]+|www\.cve\.org/CVERecord\?id=CVE-[0-9-]+)\z~', $advisoryUrl))
        <a class="text-link" href="{{ $advisoryUrl }}" target="_blank" rel="noopener noreferrer">{{ __('ui.audit.advisory') }}</a>
    @endif
    @if (is_string($finding->evidence['artifact'] ?? null) && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.png\z/', $finding->evidence['artifact']))
        <a class="text-link" href="{{ route('runs.evidence', [$run, $finding->evidence['artifact']]) }}" target="_blank" rel="noopener noreferrer">
            <x-icon name="image" />
            {{ __('ui.view_screenshot') }}
        </a>
    @endif
    @if ($finding->evidence)
        <details class="evidence-details">
            <summary>{{ __('ui.view_evidence') }}</summary>
            <pre>{{ json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @endif
    @if ($findingSectorLabel)
        <button class="text-link" type="button" wire:click="useFindingForGuidance({{ $finding->id }})" wire:loading.attr="disabled" wire:target="useFindingForGuidance">{{ __('qa-sectors.use_guidance') }}</button>
    @endif
</article>
