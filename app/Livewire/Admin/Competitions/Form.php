<?php

namespace App\Livewire\Admin\Competitions;

use App\Models\Competition;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?Competition $competition = null;

    public string $name = '';

    public string $code = '';

    public string $format = 'union';

    public string $level = 'school';

    public ?string $grade = null;

    public ?string $country = null;

    public ?string $tier = null;

    public bool $has_standings = true;

    public function mount(): void
    {
        if ($this->competition && $this->competition->exists) {
            $this->name = $this->competition->name;
            $this->code = $this->competition->code;
            $this->format = $this->competition->format;
            $this->level = $this->competition->level;
            $this->grade = $this->competition->grade;
            $this->country = $this->competition->country;
            $this->tier = $this->competition->tier;
            $this->has_standings = (bool) $this->competition->has_standings;
        }
    }

    public function updatedName(): void
    {
        if ($this->competition === null && trim($this->code) === '') {
            $this->code = Str::of($this->name)->slug('_')->limit(60, '');
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('competitions', 'code')->ignore($this->competition?->id)],
            'format' => 'required|in:union,league,sevens',
            'level' => 'required|in:professional,club,school',
            'grade' => 'nullable|string|max:64',
            'country' => 'nullable|string|max:64',
            'tier' => 'nullable|string|max:64',
            'has_standings' => 'boolean',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        if ($this->competition) {
            $this->competition->update($data);
            $message = 'Competition updated.';
        } else {
            $this->competition = Competition::create($data);
            $message = 'Competition created.';
        }

        session()->flash('message', $message);

        return redirect()->route('admin.competitions.index');
    }

    public function render()
    {
        return view('livewire.admin.competitions.form')
            ->layout('layouts.app', ['title' => $this->competition ? 'Edit Competition' : 'New Competition']);
    }
}
