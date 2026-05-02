<?php

namespace App\Livewire;

use App\Models\Competition;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reusable star/favourite toggle. Drop on any Team / Player / Competition
 * show page with:
 *   <livewire:favourite-button :type="'team'" :id="$team->id" />
 */
class FavouriteButton extends Component
{
    public string $type;

    public string $id;

    private const TYPE_MAP = [
        'team' => Team::class,
        'player' => Player::class,
        'competition' => Competition::class,
    ];

    public function toggle(): void
    {
        $user = auth()->user();
        if (! $user) {
            // Bounce to login with a friendly intent so the user lands back here.
            session()->flash('login_intent', 'favourite');
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $model = $this->resolveModel();
        if ($model) {
            $user->toggleFavourite($model);
            unset($this->isFavourited);
        }
    }

    #[Computed]
    public function isFavourited(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        $model = $this->resolveModel();

        return $model ? $user->hasFavourited($model) : false;
    }

    private function resolveModel(): ?Model
    {
        $class = self::TYPE_MAP[$this->type] ?? null;

        return $class ? $class::find($this->id) : null;
    }

    public function render()
    {
        return view('livewire.favourite-button');
    }
}
