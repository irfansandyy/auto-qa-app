@php
    $sourceReference = $run->report['source_reference'] ?? null;
    $sourceOrigins = __('dashboard-panels.runs.source_origins');
    $sourceOrigin = $sourceOrigins[$sourceReference['origin'] ?? ''] ?? null;
@endphp

@if (is_array($sourceReference) && (filled($sourceReference['sha'] ?? null) || $sourceOrigin))
    <dl class="run-source-reference">
        @if (filled($sourceReference['sha'] ?? null))
            <div>
                <dt>{{ __('dashboard-panels.runs.source_revision') }}</dt>
                <dd><code>{{ $sourceReference['sha'] }}</code></dd>
            </div>
        @endif
        @if (filled($sourceReference['branch'] ?? null))
            <div>
                <dt>{{ __('ui.base_branch') }}</dt>
                <dd><code>{{ $sourceReference['branch'] }}</code></dd>
            </div>
        @endif
        @if ($sourceOrigin)
            <div>
                <dt>{{ __('dashboard-panels.runs.source_origin') }}</dt>
                <dd>{{ $sourceOrigin }}</dd>
            </div>
        @endif
    </dl>
@endif

@if (is_array($runRuleGuide = $run->report['rule_guide'] ?? null) && $runRuleGuide !== [])
    <details class="result-details run-rule-reference">
        <summary>{{ __('dashboard-panels.runs.rule_reference') }}</summary>
        <p class="field-hint">{{ __('dashboard-panels.runs.rule_reference_help') }}</p>
        <ul>
            @foreach ($runRuleGuide as $rule)
                @if (is_array($rule) && is_string($rule['id'] ?? null) && is_string($rule['title'] ?? null))
                    <li><code>{{ $rule['id'] }}</code> <span lang="en">{{ $rule['title'] }}</span></li>
                @endif
            @endforeach
        </ul>
    </details>
@endif
