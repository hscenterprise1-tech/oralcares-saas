<x-guest-layout>
    <x-design-system.auth-layout>
        <!-- Left Brand Panel Slot (40% width) -->
        <x-slot:brandPanel>
            <div class="h-full flex flex-col justify-between relative">
                <!-- Top Brand logo -->
                <div class="flex items-center space-x-3">
                    <x-design-system.enterprise-logo size="lg" />
                </div>

                <!-- Middle Vector Core Illustration & Brand Copy -->
                <div class="my-auto space-y-10 py-6">
                    <!-- Central Emblem Representation of Logo -->
                    <div class="relative w-64 h-64 mx-auto flex items-center justify-center">
                        <div class="absolute inset-0 border border-[var(--brand-teal-600)]/15 rounded-full animate-[spin_60s_linear_infinite]"></div>
                        <div class="absolute inset-6 border border-dashed border-[var(--brand-teal-600)]/25 rounded-full animate-[spin_30s_linear_infinite_reverse]"></div>
                        
                        <div class="relative z-10 w-44 h-44 bg-[var(--brand-navy-900)]/90 border border-[#1B344E] rounded-3xl flex items-center justify-center shadow-2xl backdrop-blur-sm">
                            <!-- High Resolution OC Tooth Emblem -->
                            <svg viewBox="0 0 100 100" class="w-28 h-28 fill-none">
                                <path d="M 52,18 C 38,12 14,20 14,46 C 14,76 32,90 40,90 C 48,90 50,72 60,72 C 70,72 72,90 80,90 C 85,90 88,82 88,72" 
                                      stroke="#FFFFFF" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M 48,18 C 58,22 72,12 86,22 C 92,30 92,50 88,70" 
                                      stroke="#00A8B5" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
                                <circle cx="40" cy="46" r="11" stroke="#00A8B5" stroke-width="5" />
                                <path d="M 69,38 A 11,11 0 1,0 69,54" stroke="#FFFFFF" stroke-width="5" stroke-linecap="round" />
                            </svg>
                        </div>
                    </div>

                    <div class="space-y-3.5 max-w-sm mx-auto text-center">
                        <h2 class="text-3xl font-extrabold tracking-tight text-white leading-tight">Institutional Trust. Quiet Precision.</h2>
                        <p class="text-slate-300 text-sm leading-relaxed">
                            Centralized clinical operations, schedules, and analytics for enterprise oral healthcare groups.
                        </p>
                    </div>
                </div>

                <!-- Bottom Trust Certification Cards -->
                <div class="grid grid-cols-3 gap-3 border-t border-[#1B344E] pt-6">
                    <div class="p-2.5 bg-[#0D2137]/80 border border-[#1B344E] rounded-lg text-center">
                        <p class="text-[11px] font-bold text-white uppercase tracking-wider">HIPAA</p>
                        <p class="text-[9px] text-cyan-400 mt-0.5 font-medium">Aligned</p>
                    </div>
                    <div class="p-2.5 bg-[#0D2137]/80 border border-[#1B344E] rounded-lg text-center">
                        <p class="text-[11px] font-bold text-white uppercase tracking-wider">SOC 2</p>
                        <p class="text-[9px] text-cyan-400 mt-0.5 font-medium">Certified</p>
                    </div>
                    <div class="p-2.5 bg-[#0D2137]/80 border border-[#1B344E] rounded-lg text-center">
                        <p class="text-[11px] font-bold text-white uppercase tracking-wider">AES-256</p>
                        <p class="text-[9px] text-cyan-400 mt-0.5 font-medium">Encryption</p>
                    </div>
                </div>
            </div>
        </x-slot:brandPanel>

        <!-- Top-Right Fixed Theme Switcher Button -->
        <div class="absolute top-6 right-6 z-30" x-data="{ theme: localStorage.getItem('theme') || 'light' }">
            <button @click="
                theme = theme === 'light' ? 'dark' : 'light';
                localStorage.setItem('theme', theme);
                document.documentElement.setAttribute('data-theme', theme);
            " class="focus-ring-oralcares p-2.5 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-[var(--radius-md)] cursor-pointer hover:border-[var(--brand-teal-600)] text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] transition-colors duration-150" aria-label="Toggle visual theme">
                <svg x-show="theme === 'light'" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
                <svg x-show="theme === 'dark'" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24" style="display: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m0 13.5V21M4.95 4.95l1.59 1.59m10.91 10.91l1.59 1.59M3 12h2.25m13.5 0H21m-16.05 6.05l1.59-1.59m10.91-10.91l1.59 1.59M12 7.25a4.75 4.75 0 110 9.5 4.75 4.75 0 010-9.5z" />
                </svg>
            </button>
        </div>

        <!-- Mobile Brand Logo Header -->
        <div class="lg:hidden flex flex-col items-center mb-8 text-center">
            <x-design-system.enterprise-logo size="lg" />
            <p class="text-xs text-[var(--color-text-muted)] mt-1 uppercase tracking-wider font-bold">Dental Control Center Login</p>
        </div>

        <!-- Right Workspace Panel (60% width) -->
        <div x-data="{
            loading: false,
            email: 'admin@oralcares.com',
            password: 'password',
            error: '{{ $errors->first() ?: session('status') }}',
            submitForm() {
                if (!this.email || !this.password) {
                    this.error = 'Please fill in all credentials.';
                    return;
                }
                this.error = '';
                this.loading = true;
                this.$refs.loginForm.submit();
            }
        }" class="w-full flex flex-col items-center">
            
            <x-design-system.auth-card>
                <!-- Section Header -->
                <x-design-system.section-header 
                    title="Sign in to OralCares" 
                    subtitle="Access your unified SaaS command console." 
                />

                <!-- Alert Banners for Validation Errors -->
                <div x-show="error" class="mb-6" style="display: none;">
                    <x-design-system.alert-banner variant="danger">
                        <span x-text="error"></span>
                    </x-design-system.alert-banner>
                </div>

                <!-- Credentials Form -->
                <form method="POST" action="{{ route('login') }}" x-ref="loginForm" @submit.prevent="submitForm()" class="space-y-6">
                    @csrf

                    <!-- Email Address Floating Input -->
                    <x-design-system.floating-input 
                        label="Email Address" 
                        id="email" 
                        name="email" 
                        type="email" 
                        x-model="email"
                        required 
                        autofocus
                    />

                    <!-- Password Floating Input -->
                    <x-design-system.password-input 
                        label="Password" 
                        id="password" 
                        name="password" 
                        x-model="password"
                        required
                    />

                    <!-- Remember Me & Forgot Password Row -->
                    <div class="flex items-center justify-between pt-1">
                        <x-design-system.checkbox 
                            label="Remember me" 
                            id="remember_me" 
                            name="remember" 
                        />
                        @if (Route::has('password.request'))
                            <a class="text-xs text-[var(--brand-teal-600)] hover:text-[var(--brand-teal-700)] dark:hover:text-[var(--brand-teal-400)] font-bold transition-colors duration-150" href="{{ route('password.request') }}">
                                Forgot password?
                            </a>
                        @endif
                    </div>

                    <!-- Primary Sign In CTA Button -->
                    <x-design-system.primary-button type="submit">
                        <span x-text="loading ? 'Authenticating...' : 'Sign In'">Sign In</span>
                    </x-design-system.primary-button>

                    <!-- Divider -->
                    <x-design-system.divider text="or continue with" />

                    <!-- Secondary SSO Action Button -->
                    <x-design-system.secondary-button type="button" @click="$dispatch('open-modal', { name: 'sso-modal' })">
                        <svg class="h-5 w-5 mr-2.5 text-[var(--brand-teal-600)]" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12.24 10.285V13.4h6.887c-.275 1.565-1.88 4.604-6.887 4.604-4.33 0-7.859-3.578-7.859-8s3.53-8 7.859-8c2.46 0 4.105 1.025 5.047 1.926l2.427-2.334C17.955 2.192 15.34 1 12.24 1 6.033 1 1 6.033 1 12.24s5.033 11.24 11.24 11.24c6.478 0 10.793-4.537 10.793-10.977 0-.74-.08-1.302-.177-1.857H12.24z"/>
                        </svg>
                        <span>Single Sign-On (SSO)</span>
                    </x-design-system.secondary-button>
                </form>
            </x-design-system.auth-card>

            <!-- Copyright Footer -->
            <x-design-system.footer />
        </div>
    </x-design-system.auth-layout>
</x-guest-layout>
