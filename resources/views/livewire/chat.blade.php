<div>
    {{-- ═══ Page head ═══ --}}
    <section class="page-head">
        <div class="crumb">← <a href="{{ route('dashboard') }}">back to dashboard</a></div>
        <h1>Ask the <span class="yellow">Bot</span>.</h1>
        <p class="sub">A retrieval-augmented assistant trained on every match, player, and competition we track. Ask about stats, results, schedules, or anything in the data.</p>
    </section>

    {{-- ═══ Body ═══ --}}
    <div class="page-body chat-page">
        <div class="chat-shell">
            <div class="chat-thread" id="chat-messages">
                @if(empty($messages))
                    <div class="chat-empty">
                        <div class="chat-empty-eyebrow">Get started</div>
                        <p class="chat-empty-lead">Ask anything about rugby stats, teams, players, or matches.</p>
                        <div class="chat-suggestions">
                            @foreach([
                                'What data do you track?',
                                'How many South African players do you have?',
                                'Show me 5 back row players',
                                'How did the Stormers do in 2024?',
                                'List New Zealand teams',
                            ] as $suggestion)
                                <button type="button"
                                        wire:click="$set('question', '{{ $suggestion }}')"
                                        class="chat-suggestion">
                                    {{ $suggestion }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach($messages as $msg)
                    <div @class([
                        'chat-msg',
                        'is-user' => $msg['role'] === 'user',
                        'is-bot'  => $msg['role'] === 'assistant',
                    ])>
                        <div class="chat-msg-meta">{{ $msg['role'] === 'user' ? 'You' : 'Bot' }}</div>
                        <div class="chat-msg-body">
                            @if($msg['role'] === 'assistant')
                                {!! preg_replace(
                                    ['/\*\*(.+?)\*\*/', '/\*(.+?)\*/', '/^• /m', '/\n/'],
                                    ['<strong>$1</strong>', '<em>$1</em>', '&bull; ', '<br>'],
                                    e($msg['content'])
                                ) !!}
                            @else
                                {{ $msg['content'] }}
                            @endif
                        </div>
                    </div>
                @endforeach

                @if($loading)
                    <div class="chat-msg is-bot">
                        <div class="chat-msg-meta">Bot</div>
                        <div class="chat-msg-body chat-typing">
                            <span></span><span></span><span></span>
                        </div>
                    </div>
                @endif
            </div>

            <form wire:submit="ask" class="chat-input-row">
                <input wire:model="question"
                       type="text"
                       placeholder="Ask about rugby stats..."
                       autocomplete="off"
                       @if($loading) disabled @endif>
                <button type="submit" class="chat-send" @if($loading) disabled @endif>
                    Send
                </button>
            </form>
        </div>
    </div>
</div>
