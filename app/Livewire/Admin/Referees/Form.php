<?php

namespace App\Livewire\Admin\Referees;

use App\Models\Referee;
use Livewire\Component;

class Form extends Component
{
    public ?Referee $referee = null;

    public string $first_name = '';

    public string $last_name = '';

    public ?string $nationality = null;

    public ?string $tier = null;

    public ?string $photo_url = null;

    public function mount(): void
    {
        if ($this->referee && $this->referee->exists) {
            $this->first_name = $this->referee->first_name;
            $this->last_name = $this->referee->last_name;
            $this->nationality = $this->referee->nationality;
            $this->tier = $this->referee->tier;
            $this->photo_url = $this->referee->photo_url;
        }
    }

    protected function rules(): array
    {
        return [
            'first_name' => 'required|string|max:64',
            'last_name' => 'required|string|max:64',
            'nationality' => 'nullable|string|max:64',
            'tier' => 'nullable|string|max:64',
            'photo_url' => 'nullable|url|max:512',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        if ($this->referee) {
            $this->referee->update($data);
        } else {
            $this->referee = Referee::create($data);
        }

        session()->flash('message', 'Referee saved.');

        return redirect()->route('admin.referees.index');
    }

    public function render()
    {
        return view('livewire.admin.referees.form', [
            'nationalities' => config('rugby.nationalities', []),
        ])->layout('layouts.app', ['title' => $this->referee ? 'Edit Referee' : 'New Referee']);
    }
}
