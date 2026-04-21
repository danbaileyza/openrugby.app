<?php

namespace App\Livewire\Admin\Teams;

use App\Models\Player;
use App\Models\PlayerContract;
use App\Models\PlayerMeasurement;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Squad extends Component
{
    public Team $team;

    public string $newFirstName = '';

    public string $newLastName = '';

    public string $newPosition = 'flanker';

    public ?string $newDob = null;

    public ?string $newNationality = null;

    public ?int $newHeightCm = null;

    public ?int $newWeightKg = null;

    public const POSITIONS = [
        'loosehead_prop', 'hooker', 'tighthead_prop',
        'lock', 'flanker', 'number_eight',
        'scrum_half', 'fly_half',
        'centre', 'wing', 'full_back',
    ];

    public function mount(): void
    {
        $this->team->load([]);
    }

    protected function rules(): array
    {
        return [
            'newFirstName' => 'required|string|max:64',
            'newLastName' => 'required|string|max:64',
            'newPosition' => 'required|in:'.implode(',', self::POSITIONS),
            'newDob' => 'nullable|date|before:today',
            'newNationality' => 'nullable|string|max:64',
            'newHeightCm' => 'nullable|integer|min:100|max:230',
            'newWeightKg' => 'nullable|integer|min:30|max:200',
        ];
    }

    public function addPlayer(): void
    {
        $data = $this->validate();

        DB::transaction(function () use ($data) {
            $player = Player::create([
                'first_name' => $data['newFirstName'],
                'last_name' => $data['newLastName'],
                'position' => $data['newPosition'],
                'dob' => $data['newDob'],
                'nationality' => $data['newNationality'],
                'height_cm' => $data['newHeightCm'],
                'weight_kg' => $data['newWeightKg'],
                'is_active' => true,
            ]);

            PlayerContract::create([
                'player_id' => $player->id,
                'team_id' => $this->team->id,
                'from_date' => now()->toDateString(),
                'is_current' => true,
            ]);

            if ($player->height_cm || $player->weight_kg) {
                PlayerMeasurement::create([
                    'player_id' => $player->id,
                    'height_cm' => $player->height_cm,
                    'weight_kg' => $player->weight_kg,
                    'recorded_at' => now()->toDateString(),
                    'source' => PlayerMeasurement::SOURCE_ON_EDIT,
                    'captured_by_user_id' => auth()->id(),
                ]);
            }
        });

        $this->reset(['newFirstName', 'newLastName', 'newDob', 'newNationality', 'newHeightCm', 'newWeightKg']);
        $this->newPosition = 'flanker';

        session()->flash('message', 'Player added to squad.');
    }

    public function removeFromSquad(string $contractId): void
    {
        $contract = PlayerContract::where('id', $contractId)
            ->where('team_id', $this->team->id)
            ->first();

        if ($contract) {
            $contract->update([
                'is_current' => false,
                'to_date' => now()->toDateString(),
            ]);
            session()->flash('message', 'Player removed from squad.');
        }
    }

    public function render()
    {
        $squad = PlayerContract::where('team_id', $this->team->id)
            ->where('is_current', true)
            ->with('player')
            ->get()
            ->filter(fn ($c) => $c->player)
            ->sortBy(fn ($c) => $c->player->last_name.' '.$c->player->first_name)
            ->values();

        return view('livewire.admin.teams.squad', [
            'squad' => $squad,
            'positions' => self::POSITIONS,
            'nationalities' => config('rugby.nationalities', []),
        ])->layout('layouts.app', ['title' => $this->team->name.' — Squad']);
    }
}
