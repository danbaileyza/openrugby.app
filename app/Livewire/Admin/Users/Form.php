<?php

namespace App\Livewire\Admin\Users;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $role = User::ROLE_TEAM_USER;

    public string $password = '';

    public bool $resetPassword = false;

    public array $team_ids = [];

    public function mount(): void
    {
        if ($this->user && $this->user->exists) {
            $this->name = $this->user->name;
            $this->email = $this->user->email;
            $this->role = $this->user->role;
            $this->team_ids = $this->user->teams()->pluck('teams.id')->toArray();
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user?->id)],
            'role' => 'required|in:admin,team_user',
            'password' => 'nullable|string|min:8|max:255',
            'team_ids' => 'array',
            'team_ids.*' => 'uuid|exists:teams,id',
        ];
    }

    public function save()
    {
        $data = $this->validate();
        $password = $data['password'] ?: null;
        $generatedPassword = null;

        $payload = [
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
        ];

        if (! $this->user) {
            // New user — generate password if blank
            if (! $password) {
                $password = Str::random(12);
                $generatedPassword = $password;
            }
            $payload['password'] = Hash::make($password);
            $this->user = User::create($payload);
            $message = 'User created.';
        } else {
            if ($password || $this->resetPassword) {
                if (! $password) {
                    $password = Str::random(12);
                    $generatedPassword = $password;
                }
                $payload['password'] = Hash::make($password);
            }
            $this->user->update($payload);
            $message = 'User updated.';
        }

        $this->user->teams()->sync($data['role'] === 'team_user' ? ($data['team_ids'] ?? []) : []);

        session()->flash('message', $message);
        if ($generatedPassword) {
            session()->flash('new-password', $generatedPassword);
            session()->flash('new-email', $this->user->email);
        }

        return redirect()->route('admin.users.index');
    }

    public function render()
    {
        $teams = Team::orderBy('name')->get();

        return view('livewire.admin.users.form', [
            'teams' => $teams,
        ])->layout('layouts.app', ['title' => $this->user ? 'Edit User' : 'Invite User']);
    }
}
