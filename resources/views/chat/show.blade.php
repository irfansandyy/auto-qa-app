@extends('layouts.app')

@section('content')
<main id="main" class="page-body">
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ __('messages.chat_transcript') }}</p>
            <h1>{{ $session->title ?: __('messages.chat_transcript') }}</h1>
            <p>{{ __('messages.created') }} {{ $session->created_at->toDayDateTimeString() }}</p>
        </div>
        <a class="button button-secondary" href="{{ route('dashboard') }}">{{ __('ui.projects') }}</a>
    </div>

    <section class="card">
        <div class="section-heading">
            <h2>{{ __('messages.chat_transcript') }}</h2>
            <p>{{ __('messages.message_count', ['count' => $session->messages->count()]) }}</p>
        </div>

        <div class="chat-transcript">
            @foreach ($session->messages as $message)
                <article class="chat-message chat-message-{{ $message->role }}">
                    <header>
                        <strong>{{ ucfirst($message->role) }}</strong>
                        <span class="fine-print">
                            #{{ $message->sequence }}
                            @if ($message->model_used)
                                · {{ $message->model_used }}
                            @endif
                            @if ($message->total_tokens)
                                · {{ __('messages.tokens', ['count' => $message->total_tokens]) }}
                            @endif
                        </span>
                    </header>
                    <pre>{{ $message->content }}</pre>

                    @if ($message->toolExecutionLogs->isNotEmpty())
                        <div class="chat-tool-logs">
                            @foreach ($message->toolExecutionLogs as $log)
                                <div class="chat-tool-log">
                                    <strong>{{ $log->tool_name }}</strong>
                                    <span class="badge {{ $log->status === 'succeeded' ? 'badge-success' : 'badge-warning' }}">{{ $log->status }}</span>
                                    <span class="fine-print">{{ $log->duration_ms }} ms</span>
                                    <pre>{{ json_encode($log->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
</main>
@endsection
