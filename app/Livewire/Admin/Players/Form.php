<?php

namespace App\Livewire\Admin\Players;

use App\Models\Player;
use App\Models\PlayerContract;
use App\Models\PlayerMeasurement;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Form extends Component
{
    public ?Player $player = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $position = 'flanker';

    public ?string $position_group = null;

    public ?string $dob = null;

    public ?string $nationality = null;

    public ?int $height_cm = null;

    public ?int $weight_kg = null;

    public ?string $photo_url = null;

    public bool $is_active = true;

    public ?string $current_team_id = null;

    public const POSITIONS = [
        'loosehead_prop', 'hooker', 'tighthead_prop',
        'lock', 'flanker', 'number_eight',
        'scrum_half', 'fly_half',
        'centre', 'wing', 'full_back',
    ];

    public function mount(): void
    {
        if ($this->player && $this->player->exists) {
            $this->first_name = $this->player->first_name;
            $this->last_name = $this->player->last_name;
            $this->position = $this->player->position;
            $this->position_group = $this->player->position_group;
            $this->dob = $this->player->dob?->format('Y-m-d');
            $this->nationality = $this->player->nationality;
            $this->height_cm = $this->player->height_cm;
            $this->weight_kg = $this->player->weight_kg;
            $this->photo_url = $this->player->photo_url;
            $this->is_active = (bool) $this->player->is_active;
            $this->current_team_id = $this->player->currentContract()?->team_id;
        }
    }

    protected function rules(): array
    {
        return [
            'first_name' => 'required|string|max:64',
            'last_name' => 'required|string|max:64',
            'position' => 'required|in:'.implode(',', self::POSITIONS),
            'position_group' => 'nullable|string|max:32',
            'dob' => 'nullable|date|before:today',
            'nationality' => 'nullable|string|max:64',
            'height_cm' => 'nullable|integer|min:100|max:230',
            'weight_kg' => 'nullable|integer|min:30|max:200',
            'photo_url' => 'nullable|url|max:512',
            'is_active' => 'boolean',
            'current_team_id' => 'nullable|uuid|exists:teams,id',
        ];
    }

    public function save()
    {
        $data = $this->validate();
        $teamId = $data['current_team_id'] ?? null;
        unset($data['current_team_id']);

        DB::transaction(function () use ($data, $teamId) {
            $oldHeight = $this->player?->height_cm;
            $oldWeight = $this->player?->weight_kg;

            if ($this->player) {
                $this->player->update($data);
            } else {
                $this->player = Player::create($data);
            }

            // Record a measurement snapshot if height or weight changed
            $newHeight = $this->player->height_cm;
            $newWeight = $this->player->weight_kg;
            $heightChanged = ($oldHeight ?? null) !== ($newHeight ?? null);
            $weightChanged = ($oldWeight ?? null) !== ($newWeight ?? null);
            if (($newHeight || $newWeight) && ($heightChanged || $weightChanged)) {
                PlayerMeasurement::create([
                    'player_id' => $this->player->id,
                    'height_cm' => $newHeight,
                    'weight_kg' => $newWeight,
                    'recorded_at' => now()->toDateString(),
                    'source' => PlayerMeasurement::SOURCE_ON_EDIT,
                    'captured_by_user_id' => auth()->id(),
                ]);
            }

            $existingCurrent = $this->player->contracts()->where('is_current', true)->first();

            if ($teamId) {
                if ($existingCurrent && $existingCurrent->team_id !== $teamId) {
                    $existingCurrent->update(['is_current' => false, 'to_date' => now()->toDateString()]);
                    $existingCurrent = null;
                }
                if (! $existingCurrent) {
                    PlayerContract::create([
                        'player_id' => $this->player->id,
                        'team_id' => $teamId,
                        'from_date' => now()->toDateString(),
                        'is_current' => true,
                    ]);
                }
            } else {
                if ($existingCurrent) {
                    $existingCurrent->update(['is_current' => false, 'to_date' => now()->toDateString()]);
                }
            }
        });

        session()->flash('message', 'Player saved.');

        if ($teamId) {
            return redirect()->route('admin.teams.squad', $teamId);
        }

        return redirect()->route('admin.index');
    }

    public function render()
    {
        return view('livewire.admin.players.form', [
            'positions' => self::POSITIONS,
            'teams' => Team::orderBy('name')->get(),
            'nationalities' => config('rugby.nationalities', []),
        ])->layout('layouts.app', ['title' => $this->player ? 'Edit Player' : 'New Player']);
    }
}
