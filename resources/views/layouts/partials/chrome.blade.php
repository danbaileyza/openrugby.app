{{--
    V1 Stadium — top chrome.
    Stays dark in both light and dark themes (brand identity).
    Active nav determined from route name.
--}}
@php
    $navLinks = [
        ['label' => 'Dashboard',    'route' => 'dashboard',          'active' => fn() => request()->routeIs('dashboard')],
        ['label' => 'Competitions', 'route' => 'competitions.index', 'active' => fn() => request()->routeIs('competitions.*')],
        ['label' => 'Teams',        'route' => 'teams.index',        'active' => fn() => request()->routeIs('teams.*')],
        ['label' => 'Players',      'route' => 'players.index',      'active' => fn() => request()->routeIs('players.*')],
        ['label' => 'Matches',      'route' => 'matches.index',      'active' => fn() => request()->routeIs('matches.*')],
        ['label' => 'Referees',     'route' => 'referees.index',     'active' => fn() => request()->routeIs('referees.*')],
    ];
@endphp

<header class="chrome">
    <div class="chrome-inner">
        <a href="{{ route('dashboard') }}" class="chrome-brand">
            <svg class="chrome-ball" viewBox="0 0 32 20" fill="none" aria-hidden="true">
                <ellipse cx="16" cy="10" rx="15" ry="9" stroke="currentColor" stroke-width="1.5"/>
                <path d="M7 10h18M10 6l12 8M10 14l12-8" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
            <span class="chrome-wordmark">Open Rugby</span>
        </a>

        <nav class="chrome-nav">
            @foreach($navLinks as $link)
                <a href="{{ route($link['route']) }}" @class([
                    'chrome-link',
                    'is-active' => $link['active'](),
                ])>{{ $link['label'] }}</a>
            @endforeach

            @auth
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.index') }}" @class(['chrome-link', 'is-active' => request()->routeIs('admin.*')])>Admin</a>
                @endif
            @endauth
        </nav>

        <div class="chrome-actions">
            {{-- Theme toggle --}}
            <button
                type="button"
                class="chrome-icon-btn"
                x-data
                x-on:click="
                    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                    document.documentElement.dataset.theme = next;
                    localStorage.setItem('theme', next);
                "
                aria-label="Toggle theme"
                title="Toggle light / dark"
            >
                <x-lucide-sun class="chrome-theme-icon chrome-theme-sun" />
                <x-lucide-moon class="chrome-theme-icon chrome-theme-moon" />
            </button>

            @auth
                <span class="chrome-user">
                    <span class="chrome-user-name">{{ auth()->user()->name }}</span>
                    @if(auth()->user()->isAdmin())
                        <span class="chrome-role-tag">ADMIN</span>
                    @endif
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="chrome-link chrome-link-plain">Sign Out</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="chrome-link chrome-link-plain">Sign In</a>
            @endauth

            <a href="{{ route('chat') }}" class="chrome-cta">Ask the Bot</a>
        </div>
    </div>
</header>
