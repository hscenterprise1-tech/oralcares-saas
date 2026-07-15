<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email Address')" class="text-slate-700 text-xs font-bold uppercase tracking-wider" />
            <x-text-input id="email" class="block mt-2 w-full bg-white border border-slate-200 text-slate-800 placeholder-slate-400 focus:border-cyan-500 focus:ring-cyan-500 focus:ring-offset-0 rounded-xl text-sm py-2.5 px-3.5 shadow-sm" type="email" name="email" :value="old('email')" placeholder="admin@oralcares.com" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2 text-rose-600 text-xs" />
        </div>

        <!-- Password -->
        <div class="mt-5">
            <x-input-label for="password" :value="__('Password')" class="text-slate-700 text-xs font-bold uppercase tracking-wider" />

            <x-text-input id="password" class="block mt-2 w-full bg-white border border-slate-200 text-slate-800 placeholder-slate-400 focus:border-cyan-500 focus:ring-cyan-500 focus:ring-offset-0 rounded-xl text-sm py-2.5 px-3.5 shadow-sm"
                            type="password"
                            name="password"
                            placeholder="••••••••"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2 text-rose-600 text-xs" />
        </div>

        <!-- Remember Me & Forgot Password -->
        <div class="block mt-5 flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center cursor-pointer">
                <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500 h-4 w-4" name="remember">
                <span class="ms-2 text-xs text-slate-555 font-bold uppercase tracking-wider">{{ __('Remember me') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-xs text-cyan-600 hover:text-cyan-550 font-bold transition" href="{{ route('password.request') }}">
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <div class="mt-6">
            <x-primary-button class="w-full justify-center py-3 bg-cyan-600 hover:bg-cyan-500 active:bg-cyan-700 text-xs font-bold uppercase tracking-wider rounded-xl transition duration-150 cursor-pointer">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
