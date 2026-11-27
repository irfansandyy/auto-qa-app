@php
    $sectors = __('qa-sectors.names');
    $severityLabels = __('ui.severity');
    $statusLabels = __('qa-sectors.status');
    $checkLabels = __('qa-sectors.checks');
    $coverage = $audit['sectors'];
    $sectorFilter = array_key_exists($findingSector, $sectors) ? $findingSector : 'all';
    $severityFilter = array_key_exists($findingSeverity, $severityLabels) ? $findingSeverity : 'all';
    $severityRank = array_flip(['critical', 'high', 'medium', 'low', 'info']);
    $visibleFindings = $run->findings->filter(fn ($finding) =>
        ($sectorFilter === 'all' || ($finding->evidence['sector'] ?? null) === $sectorFilter)
        && ($severityFilter === 'all' || $finding->severity === $severityFilter))
        ->sortBy(fn ($finding) => $severityRank[$finding->severity] ?? count($severityRank));
@endphp

<section class="finding-section qa-sector-report" aria-labelledby="qa-sector-coverage-heading">
    <h3 id="qa-sector-coverage-heading">{{ __('qa-sectors.coverage') }}</h3>

    <div class="qa-sector-coverage-grid">
        @foreach ($sectors as $sector => $label)
            @php($result = $coverage[$sector] ?? ['status' => 'skipped', 'checks' => [], 'findings_count' => 0])
            <article class="qa-sector-coverage-card">
                <div class="qa-sector-coverage-heading">
                    <h4>{{ $label }}</h4>
                    <span class="badge {{ $result['status'] === 'reviewed' ? 'badge-success' : (in_array($result['status'], ['partial', 'unavailable'], true) ? 'badge-warning' : 'badge-neutral') }}">
                        {{ $statusLabels[$result['status']] ?? $statusLabels['skipped'] }}
                    </span>
                </div>

                @if ($result['status'] !== 'disabled')
                    <p>{{ trans_choice('qa-sectors.count', $result['findings_count']) }}</p>
                    <dl>
                        @foreach ($result['checks'] as $check => $checkStatus)
                            <div>
                                <dt>{{ $checkLabels[$check] ?? $check }}</dt>
                                <dd>{{ $statusLabels[$checkStatus] ?? $statusLabels['skipped'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($sector === 'ui' && ($audit['frontend']['pages_reviewed'] ?? 0) > 0)
                        <p class="field-hint">{{ trans_choice('ui.audit.pages', $audit['frontend']['pages_reviewed']) }}</p>
                    @elseif ($sector === 'security' && ($audit['repository']['packages_reviewed'] ?? 0) > 0)
                        <p class="field-hint">{{ trans_choice('ui.audit.packages', $audit['repository']['packages_reviewed']) }}</p>
                    @elseif ($sector === 'backend' && ($audit['repository']['files_reviewed'] ?? 0) > 0)
                        <p class="field-hint">{{ trans_choice('ui.audit.files', $audit['repository']['files_reviewed']) }}</p>
                    @elseif ($sector === 'database')
                        @if (($audit['repository']['migrations_reviewed'] ?? 0) > 0)
                            <p class="field-hint">{{ trans_choice('qa-sectors.migrations', $audit['repository']['migrations_reviewed']) }}</p>
                        @endif
                        @if (($audit['repository']['query_files_reviewed'] ?? 0) > 0)
                            <p class="field-hint">{{ trans_choice('qa-sectors.query_files', $audit['repository']['query_files_reviewed']) }}</p>
                        @endif
                    @elseif ($sector === 'performance' && ($audit['performance']['pages_reviewed'] ?? 0) > 0)
                        <p class="field-hint">{{ trans_choice('qa-sectors.performance_pages', $audit['performance']['pages_reviewed']) }}. {{ __('qa-sectors.performance_sample') }}</p>
                        <dl>
                            @foreach (__('qa-sectors.metric') as $code => $metric)
                                @if (isset($audit['performance']['metrics'][$code]))
                                    <div>
                                        <dt>{{ $metric }}</dt>
                                        <dd>
                                            @if ($code === 'measured_transfer_bytes')
                                                {{ number_format($audit['performance']['metrics'][$code] / 1024) }} KiB
                                            @else
                                                {{ number_format($audit['performance']['metrics'][$code]) }}{{ str_ends_with($code, '_ms') ? ' ms' : '' }}
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    @endif
                @endif
            </article>
        @endforeach
    </div>

    @if (filled($audit['repository']['source_sha'] ?? null) && ($audit['repository']['source_matches_deployment'] ?? false) === false)
        <p class="field-hint qa-unbound-note">{{ __('ui.audit.unbound') }}</p>
    @endif
</section>

<section class="finding-section qa-filtered-findings" aria-labelledby="qa-sector-findings-heading">
    <div class="section-heading">
        <h3 id="qa-sector-findings-heading">{{ __('qa-sectors.findings') }}</h3>
        <span role="status" class="badge badge-neutral">{{ trans_choice('qa-sectors.count', $visibleFindings->count()) }}</span>
    </div>
    @if ($run->findings->isNotEmpty())
        <fieldset class="qa-finding-filters">
            <legend class="sr-only">{{ __('qa-sectors.filters') }}</legend>
            <label class="field">
                <span>{{ __('qa-sectors.filter_sector') }}</span>
                <select wire:model.live="findingSector">
                    <option value="all">{{ __('qa-sectors.all_sectors') }}</option>
                    @foreach ($sectors as $sector => $label)
                        <option value="{{ $sector }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                <span>{{ __('qa-sectors.filter_severity') }}</span>
                <select wire:model.live="findingSeverity">
                    <option value="all">{{ __('qa-sectors.all_severities') }}</option>
                    @foreach ($severityLabels as $severity => $label)
                        <option value="{{ $severity }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </fieldset>
        @forelse ($visibleFindings as $finding)
            @include('partials.qa-finding', ['finding' => $finding])
        @empty
            <p class="field-hint">{{ __('qa-sectors.none_match') }}</p>
        @endforelse
    @else
        <p class="field-hint">{{ __('qa-sectors.none_found') }}</p>
    @endif
</section>
