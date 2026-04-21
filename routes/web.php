<?php

use App\Livewire\Admin\Competitions\Form as CompetitionForm;
use App\Livewire\Admin\Competitions\Index as CompetitionAdminIndex;
use App\Livewire\Admin\Fixtures\Form as FixtureForm;
use App\Livewire\Admin\Fixtures\Index as FixtureAdminIndex;
use App\Livewire\Admin\Index as AdminIndex;
use App\Livewire\Admin\Players\Form as PlayerAdminForm;
use App\Livewire\Admin\Referees\Form as RefereeAdminForm;
use App\Livewire\Admin\Referees\Index as RefereeAdminIndex;
use App\Livewire\Admin\Seasons\Form as SeasonForm;
use App\Livewire\Admin\Seasons\Index as SeasonAdminIndex;
use App\Livewire\Admin\Teams\Form as TeamAdminForm;
use App\Livewire\Admin\Teams\Index as TeamAdminIndex;
use App\Livewire\Admin\Teams\Squad as TeamSquad;
use App\Livewire\Admin\Users\Form as UserForm;
use App\Livewire\Admin\Users\Index as UserAdminIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Chat;
use App\Livewire\CompetitionList;
use App\Livewire\CompetitionShow;
use App\Livewire\Dashboard;
use App\Livewire\MatchCapture;
use App\Livewire\MatchLineupEntry;
use App\Livewire\MatchList;
use App\Livewire\MatchShow;
use App\Livewire\PlayerList;
use App\Livewire\PlayerShow;
use App\Livewire\RefereeList;
use App\Livewire\RefereeShow;
use App\Livewire\TeamList;
use App\Livewire\TeamShow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class)->name('dashboard');

Route::get('/competitions', CompetitionList::class)->name('competitions.index');
Route::get('/competitions/{competition}', CompetitionShow::class)->name('competitions.show');

Route::get('/teams', TeamList::class)->name('teams.index');
Route::get('/teams/{team}', TeamShow::class)->name('teams.show');

Route::get('/players', PlayerList::class)->name('players.index');
Route::get('/players/{player}', PlayerShow::class)->name('players.show');

Route::get('/matches', MatchList::class)->name('matches.index');
Route::get('/matches/{match}', MatchShow::class)->name('matches.show');
Route::get('/matches/{match}/capture', MatchCapture::class)->middleware('auth')->name('matches.capture');
Route::get('/matches/{match}/lineup/{side?}', MatchLineupEntry::class)->middleware('auth')->name('matches.lineup');

Route::get('/referees', RefereeList::class)->name('referees.index');
Route::get('/referees/{referee}', RefereeShow::class)->name('referees.show');

Route::get('/chat', Chat::class)->name('chat');

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('dashboard');
})->middleware('auth')->name('logout');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminIndex::class)->name('index');

    // Competitions
    Route::get('/competitions', CompetitionAdminIndex::class)->name('competitions.index');
    Route::get('/competitions/create', CompetitionForm::class)->name('competitions.create');
    Route::get('/competitions/{competition}/edit', CompetitionForm::class)->name('competitions.edit');

    // Seasons (nested under competition)
    Route::get('/competitions/{competition}/seasons', SeasonAdminIndex::class)->name('competitions.seasons');
    Route::get('/competitions/{competition}/seasons/create', SeasonForm::class)->name('competitions.seasons.create');
    Route::get('/seasons/{season}/edit', SeasonForm::class)->name('seasons.edit');

    // Teams
    Route::get('/teams', TeamAdminIndex::class)->name('teams.index');
    Route::get('/teams/create', TeamAdminForm::class)->name('teams.create');
    Route::get('/teams/{team}/edit', TeamAdminForm::class)->name('teams.edit');
    Route::get('/teams/{team}/squad', TeamSquad::class)->name('teams.squad');

    // Players
    Route::get('/players/{player}/edit', PlayerAdminForm::class)->name('players.edit');

    // Referees
    Route::get('/referees', RefereeAdminIndex::class)->name('referees.index');
    Route::get('/referees/create', RefereeAdminForm::class)->name('referees.create');
    Route::get('/referees/{referee}/edit', RefereeAdminForm::class)->name('referees.edit');

    // Fixtures
    Route::get('/fixtures', FixtureAdminIndex::class)->name('fixtures.index');
    Route::get('/fixtures/create', FixtureForm::class)->name('fixtures.create');
    Route::get('/fixtures/{match}/edit', FixtureForm::class)->name('fixtures.edit');

    // Users
    Route::get('/users', UserAdminIndex::class)->name('users.index');
    Route::get('/users/create', UserForm::class)->name('users.create');
    Route::get('/users/{user}/edit', UserForm::class)->name('users.edit');
});
