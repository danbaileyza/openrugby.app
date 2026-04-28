<button type="button"
        wire:click="toggle"
        @class(['fav-btn', 'is-on' => $this->isFavourited])
        title="{{ $this->isFavourited ? 'Remove favourite' : 'Add to favourites' }}"
        aria-pressed="{{ $this->isFavourited ? 'true' : 'false' }}">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="{{ $this->isFavourited ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
    </svg>
    <span class="fav-btn-label">{{ $this->isFavourited ? 'Favourited' : 'Favourite' }}</span>
</button>
