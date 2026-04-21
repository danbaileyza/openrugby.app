<?php

namespace App\Livewire;

use App\Models\MatchTeam;
use App\Models\RugbyMatch;
use Illuminate\Support\Collection;
use Livewire\Component;

class MatchShow extends Component
{
    public RugbyMatch $match;

    public string $activeTab = 'overview';

    public function mount(): void
    {
        // Livewire auto-binds $match from the {match} route param via HasSlug::resolveRouteBinding,
        // which tries slug first then UUID. If neither matched, Laravel would have 404'd before mount.
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render()
    {
        $this->match->load([
            'season.competition',
            'venue',
            'matchTeams.team',
            'events.player',
            'events.team',
            'lineups.player',
            'lineups.team',
            'officials.referee',
            'matchStats.team',
        ]);

        $home = $this->match->matchTeams->firstWhere('side', 'home');
        $away = $this->match->matchTeams->firstWhere('side', 'away');

        // All scoring/card events for the timeline (exclude sub events).
        // Secondary sort by event-type priority so a try always appears
        // before the conversion that followed it, even when both are at
        // the same minute.
        $typePriority = [
            'try' => 1,
            'conversion' => 2,
            'conversion_miss' => 2,
            'penalty_goal' => 3,
            'penalty_miss' => 3,
            'drop_goal' => 4,
            'penalty_conceded' => 5,
            'yellow_card' => 6,
            'red_card' => 7,
        ];
        $events = $this->match->events
            ->whereNotIn('type', ['substitution_on', 'substitution_off'])
            ->sortBy(fn ($e) => str_pad((string) ($e->minute ?? 0), 3, '0', STR_PAD_LEFT)
                .'-'.($typePriority[$e->type] ?? 9)
                .'-'.($e->second ?? 0));

        $stats = $this->match->matchStats;
        $statsAreDerived = false;

        if ($stats->isEmpty() && $home && $away && $events->isNotEmpty()) {
            $derived = $this->deriveStatsFromEvents($events, $home, $away);
            if ($derived->isNotEmpty()) {
                $stats = $derived;
                $statsAreDerived = true;
            }
        }

        return view('livewire.match-show', [
            'home' => $home,
            'away' => $away,
            'events' => $events,
            'tries' => $events->where('type', 'try'),
            'cards' => $events->whereIn('type', ['yellow_card', 'red_card']),
            'homeLineup' => $this->match->lineups->where('team_id', $home?->team_id)->sortBy('jersey_number'),
            'awayLineup' => $this->match->lineups->where('team_id', $away?->team_id)->sortBy('jersey_number'),
            'stats' => $stats,
            'statsAreDerived' => $statsAreDerived,
        ])->layout('layouts.app', ['title' => ($home?->team->name ?? '').' vs '.($away?->team->name ?? ''), 'fullBleed' => true]);
    }

    private function deriveStatsFromEvents(Collection $events, MatchTeam $home, MatchTeam $away): Collection
    {
        $countTypes = [
            'try' => 'tries',
            'conversion' => 'conversions',
            'conversion_miss' => 'conversions_missed',
            'penalty_goal' => 'penalty_goals',
            'penalty_miss' => 'penalties_missed',
            'penalty_conceded' => 'penalties_conceded',
            'drop_goal' => 'drop_goals',
            'yellow_card' => 'yellow_cards',
            'red_card' => 'red_cards',
        ];

        $derived = collect();

        foreach ($countTypes as $type => $key) {
            $homeCount = $events->where('type', $type)->where('team_id', $home->team_id)->count();
            $awayCount = $events->where('type', $type)->where('team_id', $away->team_id)->count();

            if ($homeCount === 0 && $awayCount === 0) {
                continue;
            }

            $derived->push((object) ['team_id' => $home->team_id, 'stat_key' => $key, 'stat_value' => $homeCount]);
            $derived->push((object) ['team_id' => $away->team_id, 'stat_key' => $key, 'stat_value' => $awayCount]);
        }

        return $derived;
    }
}
