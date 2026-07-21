<x-guest-layout>
    <div class="w-full flex flex-col items-center justify-center my-auto py-6" x-data="{
        loading: false,
        email: 'admin@oralcares.in',
        password: 'password',
        error: '{{ $errors->first() ?: session('status') }}',
        emailError: '',
        passwordError: '',
        validateAndSubmit() {
            this.emailError = '';
            this.passwordError = '';
            this.error = '';

            let valid = true;
            if (!this.email.trim()) {
                this.emailError = 'Email address is required.';
                valid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email.trim())) {
                this.emailError = 'Please enter a valid email address.';
                valid = false;
            }

            if (!this.password) {
                this.passwordError = 'Password is required.';
                valid = false;
            }

            if (!valid) return;

            this.loading = true;
            this.$refs.loginForm.submit();
        }
    }">
        <!-- Brand Header Section -->
        <div class="flex flex-col items-center text-center mb-10 select-none">
            <x-design-system.enterprise-logo size="lg" class="mb-4" />
            
            <h1 class="text-3xl font-extrabold text-[#0F172A] tracking-tight leading-tight">OralCares</h1>
            
            <h2 class="text-sm font-semibold text-[#475569] mt-1.5 tracking-tight uppercase">Enterprise Portal</h2>
            
            <p class="text-xs text-[#64748B] mt-1.5 font-normal">Secure access for OralCares employees.</p>
        </div>

        <!-- Login Card (460px Desktop Width, 20px Radius, 40px Padding) -->
        <x-design-system.auth-card>
            <!-- Card Header -->
            <div class="mb-8">
                <h3 class="text-xl font-bold text-[#0F172A] tracking-tight">Welcome Back</h3>
                <p class="text-xs text-[#64748B] mt-1.5">Sign in to continue</p>
            </div>

            <!-- Global Auth Error Banner -->
            <div x-show="error" class="mb-6" style="display: none;">
                <x-design-system.alert-banner variant="danger">
                    <span x-text="error"></span>
                </x-design-system.alert-banner>
            </div>

            <!-- Credentials Form -->
            <form method="POST" action="{{ route('login') }}" x-ref="loginForm" @submit.prevent="validateAndSubmit()" novalidate class="space-y-6">
                @csrf

                <!-- Email Address Input -->
                <div>
                    <x-design-system.input 
                        label="Email Address" 
                        type="email" 
                        id="email" 
                        name="email" 
                        placeholder="name@oralcares.in"
                        x-model="email"
                        :error="false"
                        autocomplete="username"
                        required
                        autofocus
                        :disabled="false"
                    >
                        <x-slot:icon>
                            <svg class="h-4 w-4 stroke-current fill-none stroke-[1.5]" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                            </svg>
                        </x-slot:icon>
                    </x-design-system.input>
                    <div x-show="emailError" class="text-xs font-medium text-[#EF4444] mt-1.5" role="alert" style="display: none;" x-text="emailError"></div>
                </div>

                <!-- Password Input -->
                <div>
                    <x-design-system.password-input 
                        label="Password" 
                        id="password" 
                        name="password" 
                        x-model="password"
                        autocomplete="current-password"
                        required
                    />
                    <div x-show="passwordError" class="text-xs font-medium text-[#EF4444] mt-1.5" role="alert" style="display: none;" x-text="passwordError"></div>
                    
                    <!-- Forgot Password Link -->
                    @if (Route::has('password.request'))
                        <div class="text-right mt-2.5">
                            <a class="text-xs text-[#0F5FFF] hover:text-[#0B4ED6] font-semibold transition-colors duration-150" href="{{ route('password.request') }}">
                                Forgot password?
                            </a>
                        </div>
                    @endif
                </div>

                <!-- Sign In Primary Button -->
                <x-design-system.primary-button type="submit">
                    <span x-text="loading ? 'Signing in...' : 'Sign In'">Sign In</span>
                </x-design-system.primary-button>
            </form>
        </x-design-system.auth-card>

        <!-- Footer -->
        <x-design-system.footer />
    </div>
</x-guest-layout>
