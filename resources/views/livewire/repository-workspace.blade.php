<div class="repository-workspace">
    @if ($repositoryPollActive)
        <span wire:poll.5s="refreshRunChanges" hidden></span>
    @endif
    @include('partials.repository-workspace')
</div>
