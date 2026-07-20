<x-guest-layout>
    <x-design-system.auth-layout>
        <x-slot:brandPanel>
            <div class="h-full flex flex-col justify-between">
                <!-- Top Brand logo -->
                <div class="flex items-center space-x-3">
                    <x-design-system.enterprise-logo size="lg" class="text-cyan-400" />
                    <span class="text-2xl font-bold tracking-tight text-white">Oral<span class="text-cyan-400">Cares</span></span>
                </div>

                <!-- Middle Description -->
                <div class="space-y-6 my-auto">
                    <h2 class="text-4xl font-extrabold tracking-tight leading-tight text-white">Dental SaaS Command Center.</h2>
                    <p class="text-slate-350 text-sm leading-relaxed">
                        Securely coordinate records, schedules, and clinical workflows across single clinics or multi-branch groups.
                    </p>
                </div>

                <!-- Bottom Trust Badges -->
                <div class="space-y-4 border-t border-[#123A5E]/40 pt-8">
                    <div class="flex items-center space-x-3 text-slate-300 text-xs">
                        <svg class="h-4 w-4 text-cyan-400 stroke-current fill-none stroke-2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.249-8.25-3.286zm0 13.036h.008v.008H12v-.008z" />
                        </svg>
                        <span>HIPAA Compliant Protocol</span>
                    </div>
                    <div class="flex items-center space-x-3 text-slate-300 text-xs">
                        <svg class="h-4 w-4 text-cyan-400 stroke-current fill-none stroke-2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.249-8.25-3.286zm0 13.036h.008v.008H12v-.008z" />
                        </svg>
                        <span>SOC 2 Type II Certified</span>
                    </div>
                    <div class="flex items-center space-x-3 text-slate-300 text-xs">
                        <svg class="h-4 w-4 text-cyan-400 stroke-current fill-none stroke-2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        <span>AES 256-bit Database Encryption</span>
                    </div>
                </div>
            </div>
        </x-slot:brandPanel>

        <!-- Right Login Card panel -->
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
                    subtitle="Enter your credentials to manage your dental cloud console." 
                />

                <!-- Alert Banners -->
                <div x-show="error" class="mb-6" style="display: none;">
                    <x-design-system.alert-banner variant="danger">
                        <span x-text="error"></span>
                    </x-design-system.alert-banner>
                </div>

                <!-- Form -->
                <form method="POST" action="{{ route('login') }}" x-ref="loginForm" @submit.prevent="submitForm()" class="space-y-5">
                    @csrf

                    <!-- Floating input for Email -->
                    <x-design-system.floating-input 
                        label="Email Address" 
                        id="email" 
                        name="email" 
                        type="email" 
                        x-model="email"
                        required 
                        autofocus
                    />

                    <!-- Password input -->
                    <x-design-system.password-input 
                        label="Password" 
                        id="password" 
                        name="password" 
                        x-model="password"
                        required
                    />

                    <!-- Remember me row -->
                    <div class="flex items-center justify-between">
                        <x-design-system.checkbox 
                            label="Remember me" 
                            id="remember_me" 
                            name="remember" 
                        />
                        @if (Route::has('password.request'))
                            <a class="text-xs text-[var(--brand-teal-600)] hover:text-[var(--brand-teal-400)] font-bold transition duration-150" href="{{ route('password.request') }}">
                                Forgot password?
                            </a>
                        @endif
                    </div>

                    <!-- Sign In Primary Button -->
                    <button type="submit" class="w-full inline-flex items-center justify-center font-bold px-4 py-3 bg-[var(--brand-navy-900)] text-white hover:bg-[var(--brand-navy-700)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-teal-600)] focus:ring-offset-2 rounded-md transition duration-150 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" :disabled="loading">
                        <x-design-system.loading-spinner class="-ml-1 mr-3 h-5 w-5 text-white" x-show="loading" style="display: none;" />
                        <span x-text="loading ? 'Signing in...' : 'Sign In'">Sign In</span>
                    </button>

                    <!-- Divider -->
                    <x-design-system.divider text="or continue with" />

                    <!-- SSO Secondary Button -->
                    <x-design-system.secondary-button type="button" @click="$dispatch('open-modal', { name: 'sso-modal' })">
                        <svg class="h-5 w-5 mr-2" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12.24 10.285V13.4h6.887c-.275 1.565-1.88 4.604-6.887 4.604-4.33 0-7.859-3.578-7.859-8s3.53-8 7.859-8c2.46 0 4.105 1.025 5.047 1.926l2.427-2.334C17.955 2.192 15.34 1 12.24 1 6.033 1 1 6.033 1 12.24s5.033 11.24 11.24 11.24c6.478 0 10.793-4.537 10.793-10.977 0-.74-.08-1.302-.177-1.857H12.24z"/>
                        </svg>
                        <span>Single Sign-On (SSO)</span>
                    </x-design-system.secondary-button>
                </form>
            </x-design-system.auth-card>

            <!-- Footer copyright -->
            <x-design-system.footer />
        </div>
    </x-design-system.auth-layout>
</x-guest-layout>
