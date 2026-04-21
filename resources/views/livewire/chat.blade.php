<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Ask the Rugby Bot</h1>

    {{-- Chat Messages --}}
    <div class="rounded-xl bg-gray-900 border border-gray-800 p-4 mb-4 min-h-[400px] max-h-[600px] overflow-y-auto space-y-4" id="chat-messages">
        @if(empty($messages))
            <div class="text-center py-16">
                <div class="text-4xl mb-3">🏉</div>
                <p class="text-gray-400">Ask me anything about rugby stats, teams, players, or matches.</p>
                <div class="mt-6 flex flex-wrap justify-center gap-2">
                    @foreach([
                        'What data do you track?',
                        'How many South African players do you have?',
                        'Show me 5 back row players',
                        'How did the Stormers do in 2024?',
                        'List New Zealand teams',
                    ] as $suggestion)
                        <button wire:click="$set('question', '{{ $suggestion }}')"
                                class="rounded-lg bg-gray-800 px-3 py-1.5 text-xs text-gray-400 hover:text-white hover:bg-gray-700 transition">
                            {{ $suggestion }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach($messages as $msg)
            <div @class([
                'flex',
                'justify-end' => $msg['role'] === 'user',
                'justify-start' => $msg['role'] === 'assistant',
            ])>
                <div @class([
                    'max-w-[80%] rounded-xl px-4 py-3 text-sm',
                    'bg-emerald-600 text-white' => $msg['role'] === 'user',
                    'bg-gray-800 text-gray-200' => $msg['role'] === 'assistant',
                ])>
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
            <div class="flex justify-start">
                <div class="bg-gray-800 rounded-xl px-4 py-3 text-sm text-gray-400">
                    <span class="inline-flex gap-1">
                        <span class="animate-bounce">.</span>
                        <span class="animate-bounce" style="animation-delay: 0.1s">.</span>
                        <span class="animate-bounce" style="animation-delay: 0.2s">.</span>
                    </span>
                </div>
            </div>
        @endif
    </div>

    {{-- Input --}}
    <form wire:submit="ask" class="flex gap-3">
        <input wire:model="question" type="text" placeholder="Ask about rugby stats..."
               class="flex-1 rounded-xl bg-gray-800 border-gray-700 text-white px-4 py-3 placeholder-gray-500 focus:border-emerald-500 focus:ring-emerald-500"
               @if($loading) disabled @endif>
        <button type="submit"
                class="rounded-xl bg-emerald-600 px-6 py-3 font-medium text-white hover:bg-emerald-500 transition disabled:opacity-50"
                @if($loading) disabled @endif>
            Send
        </button>
    </form>
</div>
