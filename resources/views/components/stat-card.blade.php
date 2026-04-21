@props(['label', 'value', 'icon' => null, 'color' => 'emerald'])

<div class="rounded-xl bg-gray-900 border border-gray-800 p-6">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm font-medium text-gray-400">{{ $label }}</p>
            <p class="mt-1 text-3xl font-bold text-white">{{ $value }}</p>
        </div>
        @if($icon)
            <div class="rounded-lg bg-{{ $color }}-600/20 p-3 text-{{ $color }}-400">
                {{ $icon }}
            </div>
        @endif
    </div>
    @if($slot->isNotEmpty())
        <div class="mt-3 text-sm text-gray-400">{{ $slot }}</div>
    @endif
</div>
