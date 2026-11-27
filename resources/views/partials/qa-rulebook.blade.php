<div class="page-heading">
    <div>
        <h1>{{ __('dashboard-panels.nav.rules') }}</h1>
        <p>{{ __('dashboard-panels.rules.description') }}</p>
    </div>
</div>

<section class="card qa-rulebook" aria-label="{{ __('dashboard-panels.nav.rules') }}">
    <div class="qa-rule-filters">
        <label class="field" for="qa-rule-search">
            <span>{{ __('dashboard-panels.rules.search') }}</span>
            <input id="qa-rule-search" type="search" wire:model.live.debounce.300ms="ruleSearch" maxlength="100" placeholder="{{ __('dashboard-panels.rules.search_example') }}">
        </label>
        <label class="field" for="qa-rule-sector">
            <span>{{ __('qa-sectors.filter_sector') }}</span>
            <select id="qa-rule-sector" wire:model.live="ruleSector">
                <option value="all">{{ __('qa-sectors.all_sectors') }}</option>
                @foreach ($ruleCategories as $sector => $category)
                    <option value="{{ $sector }}">{{ $category['label'] }}</option>
                @endforeach
            </select>
        </label>
    </div>
    <p class="qa-rule-result-count field-hint" role="status">
        {{ __('dashboard-panels.rules.count', ['matches' => number_format($ruleTotal), 'total' => number_format($ruleCatalogTotal)]) }}
    </p>

    <ul class="qa-rule-list">
        @forelse ($ruleEntries as $rule)
            <li wire:key="qa-rule-{{ $rule['id'] }}">
                <details class="qa-rule-details">
                    <summary class="qa-rule-summary">
                        <span class="qa-rule-name">
                            <code>{{ $rule['id'] }}</code>
                            <strong lang="en">{{ $rule['title'] }}</strong>
                        </span>
                        <span class="qa-rule-metadata">
                            <span>{{ $ruleCategories[$rule['sector']]['label'] ?? $rule['sector'] }}</span>
                            <span class="badge {{ $rule['severity'] === 'high' ? 'badge-warning' : 'badge-neutral' }}">{{ __('ui.severity.'.$rule['severity']) }}</span>
                            <x-icon name="add" class="qa-rule-expand" />
                        </span>
                    </summary>
                    <div class="qa-rule-content">
                        <div>
                            <span class="badge badge-neutral">{{ __('dashboard-panels.rules.mode.'.$rule['mode']) }}</span>
                            <span class="field-hint qa-rule-scope">{{ __('dashboard-panels.rules.scope.'.$rule['scope']) }}</span>
                        </div>
                        <dl>
                            <div>
                                <dt>{{ __('dashboard-panels.rules.check') }}</dt>
                                <dd lang="en">{{ $rule['check'] }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('dashboard-panels.rules.remediation') }}</dt>
                                <dd lang="en">{{ $rule['remediation'] }}</dd>
                            </div>
                        </dl>
                        @if ($project)
                            <button class="button button-secondary" type="button" wire:click="useRuleForGuidance(@js($rule['id']))" wire:loading.attr="disabled" wire:target="useRuleForGuidance">
                                {{ __('dashboard-panels.rules.add_to_guidance') }}
                            </button>
                        @endif
                    </div>
                </details>
            </li>
        @empty
            <li class="qa-rule-empty">{{ __('dashboard-panels.rules.empty') }}</li>
        @endforelse
    </ul>

    @if ($ruleTotalPages > 1)
        <nav class="qa-rule-pagination" aria-label="{{ __('dashboard-panels.rules.pages') }}">
            <button class="button button-secondary" type="button" wire:click="changeRulePage({{ $ruleCurrentPage - 1 }})" wire:loading.attr="disabled" wire:target="changeRulePage" @disabled($ruleCurrentPage <= 1)>
                {{ __('dashboard-panels.rules.previous') }}
            </button>
            <span class="field-hint">{{ __('dashboard-panels.rules.page', ['page' => $ruleCurrentPage, 'total' => $ruleTotalPages]) }}</span>
            <button class="button button-secondary" type="button" wire:click="changeRulePage({{ $ruleCurrentPage + 1 }})" wire:loading.attr="disabled" wire:target="changeRulePage" @disabled($ruleCurrentPage >= $ruleTotalPages)>
                {{ __('dashboard-panels.rules.next') }}
            </button>
        </nav>
    @endif
</section>
