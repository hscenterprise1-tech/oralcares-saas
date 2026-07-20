<x-guest-layout>
    <div class="min-h-screen flex flex-col lg:flex-row relative bg-[var(--color-bg-app)]" x-data="{
        theme: localStorage.getItem('theme') || 'light',
        showPassword: false,
        step: 1,
        loading: false,
        email: 'admin@oralcares.com',
        password: 'password',
        code: ['', '', '', '', '', ''],
        error: '{{ $errors->first() ?: session('status') }}',
        toggleTheme() {
            this.theme = this.theme === 'light' ? 'dark' : 'light';
            localStorage.setItem('theme', this.theme);
            document.documentElement.setAttribute('data-theme', this.theme);
        },
        submitCredentials() {
            if (!this.email || !this.password) {
                this.error = 'Please fill in all credentials.';
                return;
            }
            this.error = '';
            this.loading = true;
            setTimeout(() => {
                this.loading = false;
                this.step = 2;
                this.$nextTick(() => {
                    this.$refs.code0.focus();
                });
            }, 1000);
        },
        handle2FAInput(index, event) {
            const val = event.target.value;
            if (val.length > 0) {
                if (index < 5) {
                    this.$refs['code' + (index + 1)].focus();
                } else {
                    this.submitLogin();
                }
            }
        },
        handle2FAKeyDown(index, event) {
            if (event.key === 'Backspace' && !this.code[index] && index > 0) {
                this.$refs['code' + (index - 1)].focus();
            }
        },
        handlePaste(event) {
            const pasteData = event.clipboardData.getData('text').trim();
            if (/^\d{6}$/.test(pasteData)) {
                for (let i = 0; i < 6; i++) {
                    this.code[i] = pasteData[i];
                }
                this.submitLogin();
            }
        },
        submitLogin() {
            const fullCode = this.code.join('');
            if (fullCode.length < 6) {
                this.error = 'Please enter the complete 6-digit code.';
                return;
            }
            this.error = '';
            this.loading = true;
            this.$refs.loginForm.submit();
        }
    }">
        <!-- Absolute Top-Right Controls -->
        <div class="absolute top-6 right-6 z-50 flex items-center space-x-4">
            <button @click="toggleTheme()" class="focus-ring p-2.5 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg cursor-pointer hover:bg-[var(--neutral-100)] text-[var(--color-text-muted)] transition duration-150" aria-label="Toggle theme mode">
                <svg x-show="theme === 'light'" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
                <svg x-show="theme === 'dark'" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24" style="display: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m0 13.5V21M4.95 4.95l1.59 1.59m10.91 10.91l1.59 1.59M3 12h2.25m13.5 0H21m-16.05 6.05l1.59-1.59m10.91-10.91l1.59 1.59M12 7.25a4.75 4.75 0 110 9.5 4.75 4.75 0 010-9.5z" />
                </svg>
            </button>
        </div>

        <!-- Left Brand Panel (60% Desktop, hidden on mobile) -->
        <div class="hidden lg:flex lg:w-3/5 bg-[#0A2540] relative overflow-hidden flex-col justify-between p-16 text-white border-r border-[#1B2E45]">
            <!-- Subtle Grid Pattern Background -->
            <div class="absolute inset-0 bg-[linear-gradient(rgba(18,58,94,0.1)_1.5px,transparent_1.5px),linear-gradient(90deg,rgba(18,58,94,0.1)_1.5px,transparent_1.5px)] bg-[size:32px_32px] pointer-events-none opacity-40"></div>

            <!-- Monogram Watermark -->
            <div class="absolute inset-0 pointer-events-none flex items-center justify-center opacity-[0.03]">
                <svg viewBox="0 0 200 200" class="w-[800px] h-[800px] text-cyan-400 stroke-current fill-none stroke-[1] animate-[pulse_8s_infinite_ease-in-out]">
                    <path d="M 60,30 C 50,45 35,55 35,80 C 35,130 65,170 100,170 C 135,170 165,130 165,80 C 165,55 150,45 140,30" />
                </svg>
            </div>

            <!-- Top Header Logo -->
            <div class="z-10 flex items-center space-x-3">
                <svg class="h-9 w-9 text-cyan-400 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                </svg>
                <span class="text-2xl font-bold tracking-tight">Oral<span class="text-cyan-400">Cares</span></span>
            </div>

            <!-- Middle Illustration and Trust Area -->
            <div class="z-10 space-y-12 my-auto">
                <!-- Visual Core Illustration -->
                <div class="relative w-80 h-80 mx-auto flex items-center justify-center">
                    <!-- Outer Decorative Rings -->
                    <div class="absolute inset-0 border border-cyan-500/10 rounded-full animate-[spin_40s_linear_infinite]"></div>
                    <div class="absolute inset-8 border border-dashed border-cyan-500/20 rounded-full animate-[spin_20s_linear_infinite_reverse]"></div>
                    
                    <!-- Center Logo Silhouette with Gradient Core -->
                    <div class="relative z-10 w-48 h-48 bg-[#0F1E30]/80 border border-[#1B2E45] rounded-3xl flex items-center justify-center shadow-2xl backdrop-blur-md">
                        <svg viewBox="0 0 100 100" class="w-32 h-32 text-cyan-400 fill-none stroke-current stroke-[1.5]">
                            <defs>
                                <linearGradient id="brand-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#0E7490" />
                                    <stop offset="60%" stop-color="#14B8A6" />
                                    <stop offset="100%" stop-color="#5EEAD4" />
                                </linearGradient>
                            </defs>
                            <!-- Tooth outer shape -->
                            <path d="M 30,15 C 25,22 18,28 18,40 C 18,65 32,85 50,85 C 68,85 82,65 82,40 C 82,28 75,22 70,15" />
                            <!-- Inner Ring using Brand Gradient -->
                            <circle cx="50" cy="48" r="16" stroke="url(#brand-grad)" stroke-width="3" />
                            <!-- Inner C-Arc -->
                            <path d="M 42,48 A 8,8 0 1,1 50,56" />
                        </svg>
                    </div>
                </div>

                <div class="max-w-xl space-y-4">
                    <h2 class="text-4xl font-extrabold tracking-tight leading-tight">Institutional Trust. Precision Clinical Intelligence.</h2>
                    <p class="text-slate-300 text-sm leading-relaxed">
                        OralCares coordinates complex dental groups, custom SaaS schedules, and workflows inside a single enterprise platform.
                    </p>
                </div>
            </div>

            <!-- Bottom trust certification badges -->
            <div class="z-10 grid grid-cols-3 gap-6 border-t border-[#123A5E]/40 pt-8">
                <div class="flex items-center space-x-3 text-slate-300 text-xs">
                    <div class="p-2 bg-cyan-500/10 text-cyan-400 rounded-lg">
                        <svg class="h-4 w-4 stroke-current fill-none stroke-2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.249-8.25-3.286zm0 13.036h.008v.008H12v-.008z" />
                        </svg>
                    </div>
                    <span>HIPAA Aligned</span>
                </div>
                <div class="flex items-center space-x-3 text-slate-300 text-xs">
                    <div class="p-2 bg-cyan-500/10 text-cyan-400 rounded-lg">
                        <svg class="h-4 w-4 stroke-current fill-none stroke-2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.249-8.25-3.286zm0 13.036h.008v.008H12v-.008z" />
                        </svg>
                    </div>
                    <span>SOC 2 Certified</span>
                </div>
                <div class="flex items-center space-x-3 text-slate-300 text-xs">
                    <div class="p-2 bg-cyan-500/10 text-cyan-400 rounded-lg">
                        <svg class="h-4 w-4 stroke-current fill-none stroke-2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <span>AES-256 Shield</span>
                </div>
            </div>
        </div>

        <!-- Right Login Panel (40% Desktop, Full Mobile) -->
        <div class="flex-1 flex flex-col justify-center items-center px-6 py-16 bg-[var(--color-bg-app)]">
            
            <!-- Mobile Brand Area -->
            <div class="lg:hidden flex flex-col items-center mb-8 text-center">
                <svg class="h-10 w-10 text-cyan-500 fill-none stroke-current stroke-[1.5] mb-2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                </svg>
                <span class="text-2xl font-extrabold tracking-tight">Oral<span class="text-cyan-500">Cares</span></span>
                <p class="text-xs text-[var(--color-text-muted)] mt-1 uppercase tracking-wider font-bold">Dental Control Center Login</p>
            </div>

            <!-- Centered Login Card (max-width 520px) -->
            <div class="w-full max-w-[520px] bg-[var(--color-bg-card)] border border-[var(--color-border)] p-10 shadow-[var(--shadow-xl)] rounded-[var(--radius-lg)] transition-all duration-250">
                
                <!-- Card Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-extrabold text-[var(--color-text-main)] tracking-tight" x-text="step === 1 ? 'Sign in to OralCares' : 'Two-Factor Verification'">Sign in to OralCares</h1>
                    <p class="text-sm text-[var(--color-text-muted)] mt-2" x-text="step === 1 ? 'Enter your credentials to manage your dental cloud console.' : 'Please type the 6-digit confirmation code from your authenticator app.'">Enter your credentials to manage your dental cloud console.</p>
                </div>

                <!-- Error Messages Alerts -->
                <div x-show="error" class="mb-6 p-4 bg-rose-50 dark:bg-rose-950/20 border-l-4 border-[var(--color-danger)] text-[var(--color-danger)] rounded-r-lg text-xs font-semibold" role="alert" style="display: none;">
                    <span x-text="error"></span>
                </div>

                <!-- Form container -->
                <form method="POST" action="{{ route('login') }}" x-ref="loginForm" @submit.prevent class="space-y-6">
                    @csrf

                    <!-- STEP 1: Credentials Step -->
                    <div x-show="step === 1" class="space-y-6">
                        <!-- Email Input -->
                        <div class="space-y-2">
                            <label for="email" class="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-main)]">
                                Email Address <span class="text-[var(--color-danger)]" aria-hidden="true">*</span>
                            </label>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                x-model="email"
                                placeholder="admin@oralcares.com"
                                required
                                class="w-full px-4 py-3 bg-white dark:bg-[var(--neutral-950)] border border-[var(--color-border)] rounded-[var(--radius-md)] text-sm text-[var(--color-text-main)] placeholder-[var(--neutral-300)] focus-ring transition-all duration-150"
                            />
                        </div>

                        <!-- Password Input -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[var(--color-text-main)]">
                                    Password <span class="text-[var(--color-danger)]" aria-hidden="true">*</span>
                                </label>
                            </div>
                            <div class="relative">
                                <input 
                                    :type="showPassword ? 'text' : 'password'" 
                                    id="password" 
                                    name="password" 
                                    x-model="password"
                                    placeholder="••••••••"
                                    required
                                    class="w-full pl-4 pr-12 py-3 bg-white dark:bg-[var(--neutral-950)] border border-[var(--color-border)] rounded-[var(--radius-md)] text-sm text-[var(--color-text-main)] placeholder-[var(--neutral-300)] focus-ring transition-all duration-150"
                                />
                                <button type="button" @click="showPassword = !showPassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] cursor-pointer" :aria-pressed="showPassword.toString()">
                                    <!-- Eye Open -->
                                    <svg x-show="!showPassword" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <!-- Eye Closed -->
                                    <svg x-show="showPassword" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24" style="display: none;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.815 7.815L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Remember Me & Forgot Password Links -->
                        <div class="flex items-center justify-between mt-6">
                            <label for="remember_me" class="inline-flex items-center cursor-pointer select-none">
                                <input id="remember_me" type="checkbox" name="remember" class="rounded border-[var(--color-border)] text-[var(--brand-teal-600)] focus:ring-[var(--brand-teal-600)] h-4 w-4">
                                <span class="ms-2.5 text-xs font-bold uppercase tracking-wider text-[var(--color-text-main)]">Remember me</span>
                            </label>
                            @if (Route::has('password.request'))
                                <a class="text-xs text-[var(--brand-teal-600)] hover:text-[var(--brand-teal-400)] font-bold transition duration-150" href="{{ route('password.request') }}">
                                    Forgot password?
                                </a>
                            @endif
                        </div>

                        <!-- Primary Sign In button -->
                        <button type="button" @click="submitCredentials()" class="w-full inline-flex items-center justify-center font-bold rounded-[var(--radius-md)] px-5 py-3.5 bg-[#0A2540] hover:bg-gradient-to-r hover:from-[#0E7490] hover:to-[#14B8A6] text-white focus-ring transition-all duration-200 cursor-pointer shadow-[var(--shadow-md)] disabled:opacity-50" :disabled="loading">
                            <svg x-show="loading" class="animate-spin -ml-1 mr-3 h-5 w-5 text-cyan-400" fill="none" viewBox="0 0 24 24" style="display: none;">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span x-text="loading ? 'Validating credentials...' : 'Sign In'">Sign In</span>
                        </button>
                    </div>

                    <!-- STEP 2: 2FA Authentication Placeholder Step -->
                    <div x-show="step === 2" class="space-y-6" style="display: none;" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="transform translate-x-8 opacity-0" x-transition:enter-end="transform translate-x-0 opacity-100">
                        <div class="flex justify-between gap-2.5" @paste="handlePaste($event)">
                            <template x-for="(val, i) in code" :key="i">
                                <input 
                                    type="text" 
                                    maxlength="1" 
                                    x-model="code[i]"
                                    :x-ref="'code' + i"
                                    @input="handle2FAInput(i, $event)"
                                    @keydown="handle2FAKeyDown(i, $event)"
                                    class="w-14 h-14 text-center bg-white dark:bg-[var(--neutral-950)] border border-[var(--color-border)] rounded-[var(--radius-md)] text-xl font-bold text-[var(--color-text-main)] focus-ring transition-colors duration-150"
                                />
                            </template>
                        </div>

                        <div class="flex items-center justify-between gap-6 pt-4">
                            <button type="button" @click="step = 1" class="text-xs font-bold uppercase tracking-wider text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] transition cursor-pointer">
                                Back to Sign In
                            </button>
                            <button type="button" @click="submitLogin()" class="flex-1 inline-flex items-center justify-center font-bold rounded-[var(--radius-md)] px-4 py-3 bg-[var(--brand-teal-600)] hover:bg-[var(--brand-teal-400)] text-white focus-ring transition-all duration-150 cursor-pointer disabled:opacity-50" :disabled="loading">
                                <svg x-show="loading" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" style="display: none;">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Verify & Sign In</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Footer copyright area -->
            <div class="mt-12 text-center">
                <p class="text-xs text-[var(--color-text-muted)] font-semibold">&copy; {{ date('Y') }} OralCares. All rights reserved.</p>
            </div>
        </div>
    </div>
</x-guest-layout>
