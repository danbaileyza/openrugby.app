<?php

namespace App\Livewire\Admin\Seasons;

use App\Models\Competition;
use App\Models\Season;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?Competition $competition = null;

    public ?Season $season = null;

    public string $label = '';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public bool $is_current = false;

    public function mount(): void
    {
        if ($this->season && $this->season->exists) {
            $this->competition = $this->season->competition;
            $this->label = $this->season->label;
            $this->start_date = $this->season->start_date?->format('Y-m-d');
            $this->end_date = $this->season->end_date?->format('Y-m-d');
            $this->is_current = (bool) $this->season->is_current;
        }
    }

    protected function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:64', Rule::unique('seasons', 'label')
                ->where('competition_id', $this->competition->id)
                ->ignore($this->season?->id)],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_current' => 'boolean',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        if ($this->is_current) {
            // Only one current season per competition
            $this->competition->seasons()
                ->where('is_current', true)
                ->when($this->season, fn ($q) => $q->where('id', '!=', $this->season->id))
                ->update(['is_current' => false]);
        }

        if ($this->season) {
            $this->season->update($data);
            $message = 'Season updated.';
        } else {
            $this->season = $this->competition->seasons()->create($data);
            $message = 'Season created.';
        }

        session()->flash('message', $message);

        return redirect()->route('admin.competitions.seasons', $this->competition);
    }

    public function render()
    {
        return view('livewire.admin.seasons.form')
            ->layout('layouts.app', ['title' => $this->season ? 'Edit Season' : 'New Season']);
    }
}
