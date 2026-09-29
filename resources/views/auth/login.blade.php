@php
    $emailValue = old('email', 'admin@oralcares.in');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>OralCares Admin Login</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-[#003DA3] antialiased">
    <main class="min-h-screen overflow-hidden bg-white lg:grid lg:grid-cols-[0.88fr_1fr]">
        <section class="relative flex min-h-screen flex-col justify-between overflow-hidden bg-[#eef9fc] px-6 py-7 sm:px-9 lg:px-12 lg:py-9">
            <div class="pointer-events-none absolute -right-24 -top-28 h-[420px] w-[420px] rounded-full border border-white/[0.55] bg-white/20"></div>
            <div class="pointer-events-none absolute right-9 top-14 grid grid-cols-4 gap-3 opacity-45">
                @for ($i = 0; $i < 28; $i++)
                    <span class="h-1.5 w-1.5 rounded-full bg-[#9dccff]"></span>
                @endfor
            </div>
            <div class="pointer-events-none absolute -bottom-36 -left-28 h-[440px] w-[440px] rounded-full bg-white/40"></div>

            <div class="relative z-10">
                <div class="flex items-center gap-4">
                    <img src="{{ asset('images/oralcares-logo-mark.png') }}" alt="OralCares logo" class="h-12 w-12 object-contain">
                    <div>
                        <p class="text-[30px] font-bold leading-none tracking-normal">
                            <span class="text-[#003DA3]">Oral</span><span class="text-[#009DC7]">Cares</span>
                        </p>
                        <p class="mt-1.5 text-right text-sm font-medium text-[#003DA3]">by HSC Enterprise</p>
                    </div>
                </div>

                <div class="mt-9 max-w-xl">
                    <h1 class="text-[32px] font-bold leading-tight tracking-normal text-[#003DA3] sm:text-[36px]">
                        The Platform Behind Every Dental Practice
                    </h1>
                    <div class="mt-5 h-1 w-16 rounded-full bg-[#009DC7]"></div>
                    <p class="mt-6 max-w-[31rem] text-base leading-7 text-[#204d86]">
                        Manage your clinics, subscriptions, CRM, billing, renewals, support, and business operations from one secure platform.
                    </p>
                </div>
            </div>

            <div class="relative z-10 mx-auto mt-6 w-full max-w-[560px]">
                <div class="relative min-h-[238px]">
                    <div class="absolute bottom-0 left-12 h-28 w-12 rounded-t-[42px] bg-gradient-to-b from-[#7bbf95] to-[#4f966f] shadow-xl"></div>
                    <div class="absolute bottom-0 left-3 h-20 w-10 rounded-t-[34px] bg-gradient-to-b from-[#9bd3ad] to-[#5ea17d] shadow-lg"></div>
                    <div class="absolute bottom-0 left-2 h-16 w-28 rounded-b-3xl rounded-t-[18px] bg-white shadow-[0_18px_34px_rgba(37,71,118,0.18)]"></div>

                    <div class="absolute bottom-4 left-[72px] h-[200px] w-[410px] rounded-[16px] border-[7px] border-[#16253b] bg-[#f8fbff] shadow-[0_28px_45px_rgba(26,55,94,0.20)]">
                        <div class="grid h-full grid-cols-[62px_1fr] overflow-hidden rounded-[8px]">
                            <div class="bg-[#003DA3] p-4 text-white">
                                <div class="mb-6 h-7 w-7 rounded-full border-2 border-white/90"></div>
                                @foreach (['Dashboard', 'Clinics', 'Billing', 'Support', 'Reports'] as $item)
                                    <div class="mb-3 h-2 rounded-full bg-white/70"></div>
                                @endforeach
                            </div>
                            <div class="p-4">
                                <div class="mb-4 flex items-center justify-between">
                                    <span class="text-xs font-bold text-[#003DA3]">Dashboard</span>
                                    <span class="h-5 w-5 rounded-full bg-[#009DC7]"></span>
                                </div>
                                <div class="grid grid-cols-4 gap-3">
                                    @foreach (['1,248', '1,048', '120', '24.85L'] as $metric)
                                        <div class="rounded-md bg-white p-2.5 shadow-sm">
                                            <span class="block h-1.5 w-10 rounded-full bg-[#d9e5f5]"></span>
                                            <span class="mt-2 block text-xs font-bold text-[#003DA3]">{{ $metric }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-4 grid grid-cols-[1.1fr_0.9fr] gap-3">
                                    <div class="rounded-md bg-white p-3 shadow-sm">
                                        <span class="block text-xs font-bold text-[#204d86]">Revenue Overview</span>
                                        <div class="mt-4 h-20 rounded bg-[linear-gradient(135deg,transparent_0_48%,#009DC7_49%_51%,transparent_52%),linear-gradient(#e7f4f8_1px,transparent_1px)] bg-[length:100%_100%,100%_22px]"></div>
                                    </div>
                                    <div class="rounded-md bg-white p-3 shadow-sm">
                                        <span class="block text-xs font-bold text-[#204d86]">Recent Activities</span>
                                        @foreach ([1, 2, 3, 4] as $activity)
                                            <span class="mt-3 block h-2.5 rounded-full bg-[#e8f1fb]"></span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="absolute bottom-2 right-1 h-[180px] w-[92px] rounded-[24px] border-[6px] border-[#142033] bg-white shadow-[0_24px_38px_rgba(26,55,94,0.22)]">
                        <div class="mx-auto mt-2.5 h-1.5 w-7 rounded-full bg-[#142033]"></div>
                        <div class="p-2.5">
                            <div class="mb-4 flex items-center justify-between">
                                <span class="text-xs font-bold text-[#009DC7]">OralCares</span>
                                <span class="h-3 w-3 rounded-full border border-[#8aa5c6]"></span>
                            </div>
                            <span class="text-[10px] font-bold text-[#204d86]">Dashboard</span>
                            <div class="mx-auto mt-3 h-14 w-14 rounded-full bg-[conic-gradient(#009DC7_0_68%,#dbeff5_68%_100%)] p-2.5">
                                <div class="h-full w-full rounded-full bg-white"></div>
                            </div>
                            <div class="mt-4 rounded-md bg-[#f3f8ff] p-2">
                                <span class="block h-1.5 w-12 rounded-full bg-[#c9d9ee]"></span>
                                <span class="mt-2 block text-xs font-bold text-[#003DA3]">1,048</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-auth.trust-card icon="shield-check" title="Multi-Tenant" subtitle="SaaS Platform" />
                    <x-auth.trust-card icon="lock" title="Enterprise" subtitle="Security" />
                    <x-auth.trust-card icon="cloud" title="99.9%" subtitle="Uptime" />
                </div>
            </div>
        </section>

        <section class="flex min-h-screen flex-col justify-center bg-white px-6 py-10 sm:px-10 lg:px-20">
            <div class="mx-auto w-full max-w-[560px]">
                <div class="rounded-[20px] border border-[#e4ebf4] bg-white px-6 py-8 shadow-[0_18px_55px_rgba(20,45,82,0.10)] sm:px-10 lg:px-12 lg:py-10">
                    <div class="text-center">
                        <h2 class="text-[30px] font-bold leading-tight text-[#003DA3]">Welcome Back</h2>
                        <p class="mt-2 text-lg text-[#405278]">Sign in to OralCares Admin</p>
                    </div>

                    <form method="POST" action="{{ route('login') }}" class="mt-8" x-data="{ showPassword: false, loading: false }" x-on:submit="loading = true">
                        @csrf

                        <x-auth.login-input
                            id="email"
                            name="email"
                            label="Email Address"
                            type="email"
                            icon="mail"
                            autocomplete="email"
                            :value="$emailValue"
                            required
                            autofocus
                            :error="$errors->first('email')"
                        />

                        <div class="mt-5">
                            <x-auth.login-input
                                id="password"
                                name="password"
                                label="Password"
                                type="password"
                                icon="lock"
                                autocomplete="current-password"
                                required
                                password-toggle
                                :error="$errors->first('password')"
                            />
                        </div>

                        <div class="mt-5 flex items-center justify-between gap-4">
                            <label for="remember" class="flex cursor-pointer items-center gap-3 text-sm text-[#003DA3]">
                                <input id="remember" name="remember" type="checkbox" class="h-5 w-5 rounded border-[#003DA3] text-[#009DC7] shadow-sm focus:ring-2 focus:ring-[#009DC7] focus:ring-offset-2">
                                <span>Remember me</span>
                            </label>

                            @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-sm font-medium text-[#003DA3] transition hover:text-[#009DC7] focus:outline-none focus:ring-2 focus:ring-[#009DC7] focus:ring-offset-4">
                                    Forgot Password?
                                </a>
                            @endif
                        </div>

                        <button type="submit" class="mt-6 flex h-14 w-full items-center justify-center gap-3 rounded-xl bg-[#003DA3] text-lg font-bold text-white shadow-[0_14px_26px_rgba(0,61,163,0.24)] transition duration-200 hover:-translate-y-0.5 hover:bg-[#009DC7] focus:outline-none focus:ring-4 focus:ring-[#bdebf4] disabled:cursor-wait disabled:opacity-75" x-bind:disabled="loading">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M7 10V8a5 5 0 0 1 10 0v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/>
                            </svg>
                            <span x-text="loading ? 'Signing In...' : 'Sign In'">Sign In</span>
                        </button>
                    </form>

                    <div class="mt-8 flex items-center gap-5 text-[#405278]">
                        <span class="h-px flex-1 bg-[#d5dfeb]"></span>
                        <span class="flex items-center gap-3 text-sm">
                            <svg class="h-6 w-6 text-[#16c29a]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 3l7 3v5c0 4.6-2.9 8.4-7 10-4.1-1.6-7-5.4-7-10V6l7-3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                <path d="m9 12 2 2 4-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Secured with enterprise-grade security
                        </span>
                        <span class="h-px flex-1 bg-[#d5dfeb]"></span>
                    </div>
                </div>

                <footer class="mt-6 text-center text-sm leading-6 text-[#405278]">
                    <p>&copy; 2026 OralCares by HSC Enterprise</p>
                    <p>All rights reserved.</p>
                </footer>
            </div>
        </section>
    </main>
</body>
</html>
