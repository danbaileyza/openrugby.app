<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Open Rugby' }}</title>
    {{-- Theme init — runs before render to prevent flash of wrong theme.
         Defaults to dark (Stadium design is broadcast-first). User toggle
         is persisted in localStorage and wins over the default. --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('theme');
                document.documentElement.dataset.theme = stored || 'dark';
            } catch (e) {
                document.documentElement.dataset.theme = 'dark';
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" defer></script>
    @livewireStyles
</head>
<body class="h-full" style="background: var(--color-bg); color: var(--color-ink);">

@include('layouts.partials.chrome')

{{--
    Pages opt-in to full-width by passing ->layout(..., ['fullBleed' => true]).
    Stadium-redesigned pages use this to run hero/main edge-to-edge.
    Legacy pages use the default max-w container.
--}}
<main>
    @if($fullBleed ?? false)
        {{ $slot }}
    @else
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    @endif
</main>

@livewireScripts
</body>
</html>
