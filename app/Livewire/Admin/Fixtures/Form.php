<?php

namespace App\Livewire\Admin\Fixtures;

use App\Models\Competition;
use App\Models\RugbyMatch;
use App\Models\Season;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Form extends Component
{
    public ?RugbyMatch $match = null;

    public string $competition_id = '';

    public string $season_id = '';

    public string $home_team_id = '';

    public string $away_team_id = '';

    public ?string $kickoff_date = null;

    public ?string $kickoff_time = null;

    public ?string $round = null;

    public ?string $stage = null;

    public string $status = 'scheduled';

    public function mount(): void
    {
        if ($this->match && $this->match->exists) {
            $this->match->load(['season.competition', 'matchTeams']);
            $this->season_id = $this->match->season_id;
            $this->competition_id = $this->match->season->competition_id;
            $home = $this->match->matchTeams->firstWhere('side', 'home');
            $away = $this->match->matchTeams->firstWhere('side', 'away');
            $this->home_team_id = $home?->team_id ?? '';
            $this->away_team_id = $away?->team_id ?? '';
            $this->kickoff_date = $this->match->kickoff?->format('Y-m-d');
            $this->kickoff_time = $this->match->kickoff?->format('H:i');
            $this->round = $this->match->round;
            $this->stage = $this->match->stage;
            $this->status = $this->match->status;
        } else {
            $this->kickoff_time = '15:00';
        }
    }

    public function updatedCompetitionId(): void
    {
        $this->season_id = '';
        $this->home_team_id = '';
        $this->away_team_id = '';
    }

    public function updatedSeasonId(): void
    {
        $this->home_team_id = '';
        $this->away_team_id = '';
    }

    protected function rules(): array
    {
        return [
            'season_id' => 'required|uuid|exists:seasons,id',
            'home_team_id' => 'required|uuid|exists:teams,id|different:away_team_id',
            'away_team_id' => 'required|uuid|exists:teams,id',
            'kickoff_date' => 'required|date',
            'kickoff_time' => 'required|date_format:H:i',
            'round' => 'nullable|string|max:32',
            'stage' => 'nullable|string|max:64',
            'status' => 'required|in:scheduled,live,ft,postponed,cancelled,abandoned',
        ];
    }

    public function save()
    {
        $this->validate();

        $kickoff = $this->kickoff_date.' '.$this->kickoff_time.':00';

        DB::transaction(function () use ($kickoff) {
            if ($this->match) {
                $this->match->update([
                    'season_id' => $this->season_id,
                    'kickoff' => $kickoff,
                    'round' => $this->round,
                    'stage' => $this->stage,
                    'status' => $this->status,
                ]);

                // Update match_teams team_ids (preserve scores)
                $home = $this->match->matchTeams()->where('side', 'home')->first();
                $away = $this->match->matchTeams()->where('side', 'away')->first();
                if ($home) {
                    $home->update(['team_id' => $this->home_team_id]);
                }
                if ($away) {
                    $away->update(['team_id' => $this->away_team_id]);
                }
            } else {
                $this->match = RugbyMatch::create([
                    'season_id' => $this->season_id,
                    'kickoff' => $kickoff,
                    'round' => $this->round,
                    'stage' => $this->stage,
                    'status' => $this->status,
                    'score_source' => RugbyMatch::SOURCE_ADMIN,
                    'captured_by_user_id' => auth()->id(),
                ]);

                $this->match->matchTeams()->createMany([
                    ['team_id' => $this->home_team_id, 'side' => 'home'],
                    ['team_id' => $this->away_team_id, 'side' => 'away'],
                ]);
            }
        });

        session()->flash('message', $this->match->wasRecentlyCreated ? 'Fixture created.' : 'Fixture updated.');

        return redirect()->route('admin.fixtures.index');
    }

    public function render()
    {
        $competitions = Competition::orderBy('level')->orderBy('name')->get();

        $seasons = $this->competition_id
            ? Season::where('competition_id', $this->competition_id)->orderByDesc('start_date')->get()
            : collect();

        $teams = $this->season_id
            ? Season::find($this->season_id)?->teams()->orderBy('name')->get() ?? collect()
            : collect();

        return view('livewire.admin.fixtures.form', [
            'competitions' => $competitions,
            'seasons' => $seasons,
            'teams' => $teams,
        ])->layout('layouts.app', ['title' => $this->match ? 'Edit Fixture' : 'New Fixture']);
    }
}
