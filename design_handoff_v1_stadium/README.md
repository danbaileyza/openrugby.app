# Handoff: Open Rugby — V1 "Stadium" Redesign

> **openrugby.app** · *Every league. Every match. Every player.*

## Overview

This package contains a full redesign of Open Rugby under the working codename **"V1 Stadium"** — a dark-first, broadcast-inspired visual language for managing rugby competitions, teams, players, matches, and live match capture. Eleven screens across desktop and mobile are included.

The target stack is **Laravel + Livewire**, light **and** dark mode are both required, and the team already has team-logo assets to wire up.

---

## ⚠ About the design files

The HTML files in `design_files/` are **design references, not production code**. They are prototypes rendered with hand-written CSS and a few inline JS flourishes purely to communicate intended look and behaviour.

Your job is to **recreate these designs inside the existing Laravel + Livewire app**, using its Blade layout, Livewire components, existing routes, migrations, models, and any CSS/JS pipeline (Tailwind, Vite, etc.) already in place. Do not copy HTML verbatim.

Where the prototype uses hardcoded data (player names, scores, competitions), replace with Eloquent queries against the real models. Where it uses inline event handlers, use `wire:click` / `wire:model` bindings.

---

## Fidelity

**High-fidelity.** Colors, typography, spacing, and component states are the specification. Match them pixel-close. Any value not covered by `tokens.css` should be derived from the scale (4px spacing, Archivo display / JetBrains Mono label, the palette below).

---

## Global design system

### Colors (see `tokens.css` for the full variable set)

| Token | Dark | Light | Purpose |
|---|---|---|---|
| `--color-bg` | `#07100c` | `#f4f6f3` | Page background |
| `--color-bg-2` | `#0b1712` | `#ffffff` | Cards, panels, table wrap |
| `--color-bg-3` | `#111c17` | `#e9ece7` | Row hover, raised surfaces |
| `--color-bg-inset` | `#050a08` | `#0a1410` | Top chrome (**stays dark in both themes** — brand identity) |
| `--color-ink` | `#ecf3ee` | `#0a1410` | Primary text |
| `--color-ink-dim` | `#aab4ad` | `#4a5550` | Secondary text |
| `--color-muted` | `#7a8a82` | `#6a746d` | Labels, metadata |
| `--color-line` | `rgba(255,255,255,.08)` | `rgba(10,20,16,.12)` | Borders |
| `--color-brand-yellow` | `#ffd100` | same | Primary accent — active nav, highlights, "hot" stats |
| `--color-live` | `#e63946` | same | LIVE indicator, destructive actions |
| `--color-home-bright` | `#6cd4a4` | `#0e6b40` | Home-side accent on scores |
| `--color-away-bright` | `#6cb4ff` | `#1e40af` | Away-side accent on scores |

**Light mode strategy.** The top chrome (`<header class="chrome">`) remains dark in both themes — it's a branded band. Everything else inverts. The brand yellow is strong enough to work on both backgrounds without adjustment. Team-colored action tiles use tinted-light backgrounds in light mode (`#e6f4ec` for Try vs. `#0f3a24` in dark) — values in `tokens.css`.

**Theme toggle.** Set `data-theme="light"` on `<html>`. Persist user preference; default to the system preference via `prefers-color-scheme`.

### Typography

Two families, both free via Google Fonts:

- **Archivo** — display, UI, numerals. Weights used: 400, 500, 600, 700, 800, 900.
- **JetBrains Mono** — labels, tags, timestamps, metadata. Weights: 400, 500, 700.

Scale is defined as utility classes in `tokens.css` (`.t-num-xl`, `.t-h1`, `.t-label-sm`, `.t-tag`, etc). Use these, don't eyeball sizes.

Distinctive treatments:
- Big scoreboard numerals use **-0.04em letter-spacing** and **900 weight**.
- Section headers are **uppercase Archivo 800**, paired with a **4×20px yellow bar** to the left.
- Metadata/labels are **all-caps mono 10–11px with 0.2em letter-spacing**.

### Spacing

4px base. Tokens `--s-1` … `--s-10`. Page-level padding is 40px on desktop, 20px on mobile. Card interior padding is 14–22px depending on density.

### Radius

Minimal. `--r-xs: 2px` (chips), `--r-sm: 3px`, `--r-md: 4px` (buttons). Cards are square-edged. The rugby design language favors crisp rectangles; avoid soft/rounded unless specified.

### Motion

- `.pulse-dot` — 8px dot, `animation: pulse 1.4s ease-in-out infinite` (opacity 1 ↔ 0.3). Used anywhere "LIVE" appears.
- Hover elevations: `transform: translateY(-2px); box-shadow: var(--shadow-card);` with `.12s` transition.
- No fancy page transitions.

### Iconography

Currently the design uses Unicode glyphs as placeholders (🏉 ⎯ ⊙ ◉ ⚑ ▮ ✕). **Replace with a proper icon set** — Phosphor, Lucide, or Tabler all work well with this aesthetic. Icon rules:
- Stroke icons, ~1.5px weight.
- 14px inside action tiles (on colored circle background), 18–22px elsewhere.
- A custom **rugby ball** mark exists in `.ball` in `_shared.css` — keep that SVG, it's brand.

---

## Global chrome (every page)

### Top bar (`.chrome`)
Sticky, full-width, dark (both themes). Height ≈ 52px.

- **Left:** Rugby-ball logo + "RugbyStats" wordmark (900 weight, -0.01em tracking).
- **Center (nav):** Dashboard · Competitions · Teams · Players · Matches · Live Capture · Admin. Active link has a **yellow pill background** (`--color-brand-yellow`) with dark ink. Inactive links are `#aab4ad`, go white on hover.
- **Right:** `Dan [ADMIN]` tag, `Sign Out`, and an **"Ask the Bot"** CTA (solid yellow button, 800 weight).

In Livewire: build this as a single Blade partial (`resources/views/layouts/chrome.blade.php`) included by every full-page view. The active-nav logic should key off the current route name.

### Footer switcher (`.switcher`)
Floating bottom-right panel, `position: fixed`. On every V1 page for easy cross-navigation during design review. **Remove in production** — or keep for staging-only.

---

## Screens

Screenshots are in `screenshots/`. Source HTML is in `design_files/`.

### 1. Dashboard (`dashboard.html`)
**Purpose.** Operator landing — at-a-glance health of the league + quick-jump to live matches.

**Layout.**
- Page header (`.page-head`): breadcrumb, large `H1` ("DASHBOARD" with the dot in yellow), subtitle.
- **KPI row (`.stat-row`):** 4 equal-width stat cards — Competitions (40), Teams (532), Players (9,032), Matches (9,120 — "hot" variant, yellow top border + yellow numeral).
- **Live strip:** horizontal band, gradient-from-red background, pulse-dot + "LIVE · 3 matches" + "ALL →". Three live match rows below with minute, teams, scores, competition tag.
- **Latest results:** standard match rows, 8–12 items.
- **Quick actions (right column or below):** Add Match, Import Roster, etc.

**Livewire mapping.**
- `App\Livewire\Dashboard` component with properties `$kpis`, `$liveMatches`, `$recentMatches`.
- Poll live-match scores with `wire:poll.10s` on the live strip only — don't re-render KPIs.

### 2. Competitions list (`competitions.html`)
**Purpose.** Browse & filter all competitions.

**Layout.**
- Page header, tabs (All / International / Club / Youth), filter chips (Active / Upcoming / Concluded / Archived).
- Table: competition name, nation/region, season, # teams, # matches, status pill, "VIEW →".

**Livewire mapping.** `App\Livewire\Competitions\Index` — standard index with filters + pagination.

### 3. Competition detail (`competition.html`)
**Purpose.** Drill into one competition — standings, fixtures, top scorers.

**Layout.**
- Hero strip with trophy/crest placeholder, competition name as H1, season dropdown, edit button.
- Stat row: # teams, # matches, # tries scored, points avg.
- Tabs: **Standings** (default) / Fixtures / Results / Scorers / Squads.
- Standings table: P/W/D/L/F/A/+/-/BP/Pts, with form guide (last 5 results as dots).

**Livewire mapping.** `App\Livewire\Competitions\Show` with nested components per tab to keep the URL shareable (use `wire:navigate`).

### 4. Teams list (`teams.html`)
**Purpose.** Browse teams.

**Layout.**
- Filter chips by nation + by competition membership.
- **Team cards grid** (3–4 cols desktop, 2 tablet, 1 mobile). Each card: team logo (80×80, placeholder jersey-color block for now), team name (Archivo 800 uppercase), nation/competition row, next fixture, stat micro-row (win %, last-5 form dots).
- Logo slot: **use the client's real team-logo assets** — save them to `public/storage/team-logos/{slug}.svg` and reference via `Storage::url()`.

### 5. Team detail (`team.html`)
**Purpose.** One team — roster, fixtures, season stats.

**Layout.**
- Hero: team logo, name, nation, competition badges, "EST. 1998" mono label.
- Stat row: Played / Won / Win % / Points For / Points Against.
- Tabs: **Roster** (default) / Fixtures / Results / Staff / Stats.
- Roster grid — player cards grouped by position (Forwards / Backs).

### 6. Players list (`players.html`)
**Purpose.** Search/filter the 9k-player database.

**Layout.**
- Search input (full-width, prominent).
- Filter chips: position, nation, team, caps range.
- Table: name, position, team, nation, age, caps, tries, points. Sortable columns.

### 7. Player profile (`player.html`)
**Purpose.** Deep dive on one player.

**Layout.**
- Hero left: photo placeholder 200×240, position tag, cap count, nation.
- Hero right: stat blocks (caps, tries, points, starts).
- Tabs: Career history / Recent matches / Honors / Measurables.

**Note.** Player photography is a **TODO** — use silhouette placeholders. Fonts (Archivo + JetBrains Mono) load from Google Fonts; photos need a real asset pipeline (S3/CDN).

### 8. Match detail (`match.html`)
**Purpose.** Full view of a match — live or concluded.

**Layout.**
- Big scoreboard strip: HOME tag + team name | **big score (56px)** | AWAY tag + team name. Pulse-dot + "LIVE · mm:ss" under score if in-progress.
- Tabs: Timeline / Lineups / Stats / Officials.
- Timeline: vertical list of events with minute gutter, icon bubble, scorer name, team side tag.
- Stats: possession bar, territory bar, penalties count bar — each rendered as left-home / right-away split bars.

### 9. Live Capture (`capture.html`) ⭐ Core operator screen
**Purpose.** Data-entry station during a live match. Used by the statistician at the touchline.

**Layout.** Two columns (1.3fr / 1fr), stacks vertically <1100px.

**Left column (capture):**
1. Warning banner — "Capturing live — events publish instantly. Undo available 30s."
2. Mini scoreboard (same structure as match detail).
3. Team-select buttons: **[SOU]** vs **[ROM]** — whichever side the next event is FOR. Active state: filled with that team's deep color.
4. Minute block:
   - `[ − ] [ number ] [ + ] [ ↻ ]` row, 42px square buttons.
   - Quick-jump strip: KO (0') · HT (40') · 2H (41') · FT (80').
   - Scorer dropdown — optional, grouped by Starters / Bench.
5. **Action tile grid** — 4 columns, aspect-ratio 1.1:1 tiles:
   - Row 1 (scoring): Try +5 · Conversion +2 · Pen Kick +3 · Drop Goal +3.
   - Row 2 (non-scoring): Conv Missed · Pen Missed · Penalty Conceded · Yellow Card.
   - Row 3: Red Card (single tile).
   - Each tile: circular icon top-left, name bottom-left, point-value bottom-right. Colors are semantic — see `--surf-*` tokens.
6. **End Match · Publish Final** — full-width red button with confirm dialog.

**Right column (log):**
1. Recent Events list — min / icon / scorer name + action / HOME or AWAY tag / ✕ delete. Newest on top.
2. Match scorers summary (grouped by team).
3. Keyboard shortcuts panel — **T** Try, **C** Conv, **P** Pen, **D** Drop, **Y** Yellow, **R** Red, **⌘Z** Undo, **← →** Swap side, **↑ ↓** Minute ±1.

**Livewire mapping (critical).**
```php
// App\Livewire\Match\Capture
public Match $match;
public int $minute = 0;
public string $captureFor = 'home';        // 'home' | 'away'
public ?int $scorerId = null;
public Collection $events;                 // reverse-chrono

public function logEvent(string $type) { /* validate, persist, append to $events, broadcast */ }
public function undoLast() { /* soft-delete newest $events entry */ }
public function endMatch() { /* lock scoreline, emit MatchCompleted event */ }

// Publish in real time to public match-detail page:
//   broadcast(new MatchEventCaptured($match, $event))->toOthers();
```

Real-time needs **Laravel Echo + Reverb** (or Pusher) so the public match-detail page picks up events within ~1s. Bind each action tile to `wire:click="logEvent('try')"`. Use Alpine (`@keydown.window`) for keyboard shortcuts — Livewire round-trips are too slow.

**Behavior details.**
- When an event is logged: green flash row in the log (`background: rgba(255,209,0,.12); transition .6s`), then fade.
- Undo is "optimistic" — removes from DOM immediately, sends delete request.
- Auto-save state: the form ("capturing for", minute, scorer) must survive Livewire refreshes — use `wire:model.live`.
- The pulse-dot + "BROADCASTING · 12 viewers" stat in the header is fed by a Reverb presence channel count.

### 10. Mobile index (`mobile.html`)
Three iOS-style mockups side-by-side on one canvas — Dashboard, Match (live), Player profile. Use these as reference when you build the mobile-responsive views (or a companion PWA). Layouts use iOS dynamic-island + home-indicator chrome rendered by the `ios-frame.jsx` helper — this is **for design presentation only**, not production.

### 11. Mobile Live Capture (`capture-mobile.html`)
Three states: Fresh (0-0, ready), Mid-match (events streaming), End-confirm (destructive red dialog).

**Mobile capture layout.**
- Sticky header — "← MATCH DETAIL" left, "● LIVE" right.
- Compact scoreboard (no team-tag row, names below score).
- Team toggle (SOU / ROM) — full-width, two-up.
- Minute block — same as desktop but stacked, 42px controls.
- 2-column action grid instead of 4-column. Minimum 100px tile height so it hits the **44pt touch-target** requirement on every side.
- Events log — same markup, narrower padding.
- End Match button — full-width at bottom; confirm screen replaces it inline with a Cancel/End split.

**Mobile Livewire considerations.** Same component, different Blade view via `wire:layout`. Wire-up `@env('sanctum')` for native-app tokens if you eventually wrap this in a WebView.

---

## Interactions & behavior summary

| Interaction | Trigger | Behavior |
|---|---|---|
| Log scoring event | Tile tap or hotkey | Append to events, increment scoreboard, broadcast, flash-highlight |
| Undo last | "↶ Undo last" or ⌘Z | Remove newest event, rollback score, 30s window before hard-commit |
| Swap capture side | ← / → or SOU/ROM tap | Flip `captureFor` state |
| Minute ± 1 | ↑ / ↓ or +/− buttons | `Math.max(0, minute ± 1)`. Quick-jumps snap. |
| End match | Red button | Confirm dialog → lock scoreline, broadcast `MatchCompleted`, route away |
| Live poll | `wire:poll.10s` | Dashboard KPIs + live-match rows only |

---

## State management

The Live Capture page is the only non-trivial state. Events need to be **optimistically** rendered client-side (via Alpine) and **durably** persisted server-side (via Livewire). Use this pattern:

```js
// Alpine on the action-tile grid
x-on:click="logOptimistic('try', 5); $wire.logEvent('try')"
```

Server authority wins on conflict — if the Livewire roundtrip rejects, roll back the optimistic entry and flash an error banner.

---

## Assets

| Asset | Source | Action |
|---|---|---|
| Team logos | Client has them | Save as SVG to `public/storage/team-logos/{slug}.svg`, use an `<x-team-logo :team="$team" size="lg"/>` Blade component |
| Player photos | **TODO — client to supply** | Use neutral silhouette placeholder; reserve aspect 5:6 |
| Competition crests | **TODO** | Same as above; silver/bronze medal emoji fallback is fine for now |
| Fonts | Google Fonts | `<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">` — or self-host with `laravel-vite-plugin` + `@fontsource` for GDPR |
| Icons | **Choose one** | Phosphor/Lucide/Tabler — lock in before dev starts |
| Rugby ball mark | In `_shared.css` (`.ball`) | Convert to SVG component, keep as brand element |

---

## Design tokens recap

See `tokens.css` — copy directly into `resources/css/tokens.css`, import from your main stylesheet, reference via `var(--color-...)` everywhere.

---

## Files in this package

```
design_handoff_v1_stadium/
├── README.md                     ← this file
├── tokens.css                    ← design tokens, light + dark
├── design_files/
│   ├── _shared.css               ← prototype CSS (reference only)
│   ├── ios-frame.jsx             ← mobile-frame helper (reference only)
│   ├── dashboard.html
│   ├── competitions.html
│   ├── competition.html
│   ├── teams.html
│   ├── team.html
│   ├── players.html
│   ├── player.html
│   ├── match.html
│   ├── capture.html              ← ⭐ primary new feature
│   ├── capture-mobile.html
│   └── mobile.html
└── screenshots/
    ├── 01-dashboard.png
    ├── 02-competitions.png
    ├── 03-competition-detail.png
    ├── 04-teams.png
    ├── 05-team-detail.png
    ├── 06-players.png
    ├── 07-player-profile.png
    ├── 08-match-detail.png
    ├── 09-live-capture.png
    ├── 10-mobile.png (three phones)
    └── 11-mobile-capture.png (three phones)
```

## Suggested implementation order

1. Tokens + chrome layout + light/dark toggle (foundation).
2. Dashboard (proves the token system + live-poll infra).
3. Competitions + Teams + Players index pages (standard CRUD pattern).
4. Detail pages (share a hero-strip + tabs component).
5. Match detail (read-only public view).
6. **Live Capture desktop** (⭐ — where Reverb + optimistic UI matters).
7. Mobile-responsive pass on every page.
8. Mobile Live Capture — same Livewire component, mobile Blade view.

Ship the Dashboard + read-only Match detail first to prove the design system, then layer Live Capture on top once Reverb is wired.
