<?php

namespace App\Livewire;

use App\Models\Referee;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class RefereeShow extends Component
{
    public Referee $referee;

    public string $activeTab = 'matches';
    public ?string $expandedTeam = null;

    public function mount(Referee $referee)
    {
        $this->referee = $referee;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function toggleTeam(string $teamId): void
    {
        $this->expandedTeam = $this->expandedTeam === $teamId ? null : $teamId;
    }

    public function render()
    {
        // All match appointments with eager-loaded relationships
        $appointments = $this->referee->matchOfficials()
            ->with([
                'match.matchTeams.team',
                'match.season.competition',
                'match.venue',
            ])
            ->get()
            ->sortByDesc(fn ($a) => $a->match->kickoff);

        // Group by role
        $roleBreakdown = $appointments->groupBy('role')->map->count();

        // Build team stats (wins/losses for matches where this referee was the main referee)
        $refMatches = $appointments->where('role', 'referee');
        $teamStats = collect();

        foreach ($refMatches as $appointment) {
            $match = $appointment->match;
            foreach ($match->matchTeams as $mt) {
                if (! $mt->team) {
                    continue;
                }

                $teamId = $mt->team_id;
                if (! $teamStats->has($teamId)) {
                    $teamStats[$teamId] = [
                        'team' => $mt->team,
                        'matches' => 0,
                        'wins' => 0,
                        'losses' => 0,
                        'draws' => 0,
                    ];
                }

                $stats = $teamStats[$teamId];
                $stats['matches']++;

                $opponent = $match->matchTeams->firstWhere('team_id', '!=', $teamId);
                if ($mt->score !== null && $opponent?->score !== null) {
                    if ($mt->score > $opponent->score) {
                        $stats['wins']++;
                    } elseif ($mt->score < $opponent->score) {
                        $stats['losses']++;
                    } else {
                        $stats['draws']++;
                    }
                }

                $teamStats[$teamId] = $stats;
            }
        }

        $teamStats = $teamStats->sortByDesc('matches');

        return view('livewire.referee-show', [
            'appointments' => $appointments,
            'roleBreakdown' => $roleBreakdown,
            'teamStats' => $teamStats,
            'totalMatches' => $appointments->pluck('match_id')->unique()->count(),
            'asReferee' => $roleBreakdown->get('referee', 0),
        ])->layout('layouts.app', ['title' => $this->referee->full_name]);
    }
}
