<div class="mx-auto max-w-sm">
    <div class="rounded-xl bg-gray-900 border border-gray-800 p-6">
        <h1 class="text-xl font-bold text-white mb-1">Sign In</h1>
        <p class="text-sm text-gray-400 mb-6">Admins and team users only.</p>

        <form wire:submit="login" class="space-y-4">
            <div>
                <label for="email" class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Email</label>
                <input
                    id="email"
                    type="email"
                    wire:model="email"
                    autocomplete="email"
                    autofocus
                    class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                >
                @error('email') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="block text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Password</label>
                <input
                    id="password"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    class="w-full rounded-md bg-gray-800 border border-gray-700 px-3 py-2 text-sm text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                >
                @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-400">
                <input type="checkbox" wire:model="remember" class="rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-900">
                Remember me
            </label>

            <button
                type="submit"
                wire:loading.attr="disabled"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="login">Sign In</span>
                <span wire:loading wire:target="login">Signing in…</span>
            </button>
        </form>
    </div>
</div>
