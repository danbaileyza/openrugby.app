<?php

namespace App\Livewire\Admin\Teams;

use App\Models\Season;
use App\Models\Team;
use Livewire\Component;

class Form extends Component
{
    public ?Team $team = null;

    public string $name = '';

    public ?string $short_name = null;

    public string $country = '';

    public string $type = 'club';

    public ?string $founded_year = null;

    public ?string $primary_color = null;

    public ?string $secondary_color = null;

    public array $season_ids = [];

    public function mount(): void
    {
        if ($this->team && $this->team->exists) {
            $this->name = $this->team->name;
            $this->short_name = $this->team->short_name;
            $this->country = $this->team->country_display ?: '';
            $this->type = $this->team->type;
            $this->founded_year = $this->team->founded_year;
            $this->primary_color = $this->team->primary_color;
            $this->secondary_color = $this->team->secondary_color;
            $this->season_ids = $this->team->seasons()->pluck('seasons.id')->toArray();
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:16',
            'country' => 'required|string|max:64',
            'type' => 'required|in:club,national,franchise,provincial,invitational',
            'founded_year' => 'nullable|digits:4',
            'primary_color' => 'nullable|string|max:16',
            'secondary_color' => 'nullable|string|max:16',
            'season_ids' => 'array',
            'season_ids.*' => 'uuid|exists:seasons,id',
        ];
    }

    public function save()
    {
        $data = $this->validate();
        $seasonIds = $data['season_ids'] ?? [];
        unset($data['season_ids']);

        if ($this->team) {
            $this->team->update($data);
            $message = 'Team updated.';
        } else {
            $this->team = Team::create($data);
            $message = 'Team created.';
        }

        $this->team->seasons()->sync($seasonIds);

        session()->flash('message', $message);

        return redirect()->route('admin.teams.index');
    }

    public function render()
    {
        $seasons = Season::with('competition')
            ->orderByDesc('start_date')
            ->limit(200)
            ->get();

        return view('livewire.admin.teams.form', [
            'seasons' => $seasons,
        ])->layout('layouts.app', ['title' => $this->team ? 'Edit Team' : 'New Team']);
    }
}
