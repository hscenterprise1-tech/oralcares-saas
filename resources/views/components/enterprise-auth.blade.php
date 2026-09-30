<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'OralCares Enterprise Portal') }}</title>

    <!-- Inter Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased font-sans text-[#475569] bg-[#F8FAFC] selection:bg-[#00B8D9]/30 relative min-h-screen flex flex-col justify-between overflow-x-hidden lg:overflow-hidden">
    
    <!-- Background Curved Blobs & Soft Blue Gradients -->
    <div class="fixed inset-0 z-0 pointer-events-none overflow-hidden">
        <!-- Giant Soft Blue Curved Radial Blob -->
        <div class="absolute -top-[20%] -left-[10%] w-[900px] h-[900px] bg-gradient-to-br from-[#00B8D9]/10 via-[#38BDF8]/5 to-transparent rounded-full blur-[120px]"></div>
        <div class="absolute bottom-0 left-[20%] w-[600px] h-[600px] bg-[#0F4C81]/5 rounded-full blur-[100px]"></div>
        
        <!-- Abstract Curved Arc (Matching Reference Composition) -->
        <div class="absolute -top-[150px] left-1/3 w-[800px] h-[800px] border-[60px] border-[#38BDF8]/10 rounded-full blur-[40px]"></div>
    </div>

    <!-- Main Viewport Wrapper (100vh locked on desktop) -->
    <main class="relative z-10 w-full flex-1 flex flex-col lg:flex-row items-center justify-between px-6 lg:px-12 xl:px-16 py-6 lg:py-8 max-w-[1600px] mx-auto min-h-[calc(100vh-40px)]" x-data="{ show: false }" x-init="setTimeout(() => show = true, 50)">
        
        <!-- LEFT PANEL (Branding, Content & 3D Mascot) -->
        <div class="w-full lg:w-[58%] xl:w-[60%] flex flex-col justify-between h-full py-2 transition-all duration-700 ease-out transform" :class="show ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-8'">
            
            <!-- Top Branding Header -->
            <div>
                <img src="{{ asset('images/logo.png') }}" alt="OralCares Enterprise" class="h-11 sm:h-12 w-auto object-contain">
            </div>

            <!-- Middle Main Hero & Visual Split -->
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-center my-6 lg:my-0">
                
                <!-- Hero Content (Text + 4 Trust Cards) -->
                <div class="xl:col-span-6 flex flex-col justify-center">
                    <h1 class="text-[38px] lg:text-[44px] xl:text-[48px] font-extrabold text-[#0F172A] leading-[1.1] tracking-tight">
                        Smart Software <br>
                        <span class="text-[#0F4C81]">for Smarter</span> <br>
                        <span class="text-[#00B8D9]">Dental Care</span>
                    </h1>
                    
                    <p class="mt-4 text-[15px] xl:text-[16px] text-[#64748B] font-normal leading-relaxed max-w-[420px]">
                        Manage clinics, patients, billing, treatments, staff and operations from one secure platform.
                    </p>

                    <!-- 4 Trust Feature Cards -->
                    <div class="mt-6 space-y-2.5 max-w-[400px]">
                        <!-- Card 1 -->
                        <div class="flex items-center space-x-3 bg-white/80 backdrop-blur-sm rounded-xl p-2.5 px-3.5 border border-[#E2E8F0] shadow-sm hover:border-[#38BDF8]/40 transition-colors">
                            <div class="h-9 w-9 rounded-lg bg-[#0F4C81]/10 text-[#0F4C81] flex items-center justify-center shrink-0">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-[13px] font-bold text-[#0F172A] leading-tight">Enterprise Security</h4>
                                <p class="text-[11px] font-medium text-[#64748B]">HIPAA compliant & bank-level encryption</p>
                            </div>
                        </div>

                        <!-- Card 2 -->
                        <div class="flex items-center space-x-3 bg-white/80 backdrop-blur-sm rounded-xl p-2.5 px-3.5 border border-[#E2E8F0] shadow-sm hover:border-[#38BDF8]/40 transition-colors">
                            <div class="h-9 w-9 rounded-lg bg-[#00B8D9]/10 text-[#00B8D9] flex items-center justify-center shrink-0">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-[13px] font-bold text-[#0F172A] leading-tight">Cloud Based</h4>
                                <p class="text-[11px] font-medium text-[#64748B]">Access anywhere, anytime securely</p>
                            </div>
                        </div>

                        <!-- Card 3 -->
                        <div class="flex items-center space-x-3 bg-white/80 backdrop-blur-sm rounded-xl p-2.5 px-3.5 border border-[#E2E8F0] shadow-sm hover:border-[#38BDF8]/40 transition-colors">
                            <div class="h-9 w-9 rounded-lg bg-[#38BDF8]/10 text-[#38BDF8] flex items-center justify-center shrink-0">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h5m-5 0V11m0 0h-2m2 0h2" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-[13px] font-bold text-[#0F172A] leading-tight">Multi Clinic Management</h4>
                                <p class="text-[11px] font-medium text-[#64748B]">Unified multi-location operations</p>
                            </div>
                        </div>

                        <!-- Card 4 -->
                        <div class="flex items-center space-x-3 bg-white/80 backdrop-blur-sm rounded-xl p-2.5 px-3.5 border border-[#E2E8F0] shadow-sm hover:border-[#38BDF8]/40 transition-colors">
                            <div class="h-9 w-9 rounded-lg bg-[#10B981]/10 text-[#10B981] flex items-center justify-center shrink-0">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-[13px] font-bold text-[#0F172A] leading-tight">Real-Time Patient Management</h4>
                                <p class="text-[11px] font-medium text-[#64748B]">Instant appointment & clinical sync</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3D Mascot Render -->
                <div class="hidden xl:flex xl:col-span-6 justify-center items-center relative">
                    <img src="{{ asset('images/hero-3d.png') }}" alt="Pixar 3D Dental Mascot" class="w-full max-w-[380px] xl:max-w-[440px] h-auto object-contain drop-shadow-[0_15px_30px_rgba(0,184,217,0.15)] hover:scale-[1.02] transition-transform duration-500">
                </div>
            </div>

            <!-- Bottom Statistics Section -->
            <div class="hidden lg:flex items-center space-x-6 bg-white/60 backdrop-blur-sm rounded-2xl p-3.5 px-6 border border-[#E2E8F0] max-w-[560px] shadow-sm mt-2">
                <div class="flex items-center space-x-3">
                    <div class="text-[20px] font-extrabold text-[#0F4C81]">2.4M+</div>
                    <div class="text-[11px] font-semibold text-[#64748B] leading-tight">Patients<br>Managed</div>
                </div>
                <div class="h-8 w-[1px] bg-[#E2E8F0]"></div>
                <div class="flex items-center space-x-3">
                    <div class="text-[20px] font-extrabold text-[#00B8D9]">12K+</div>
                    <div class="text-[11px] font-semibold text-[#64748B] leading-tight">Clinics<br>Using Platform</div>
                </div>
                <div class="h-8 w-[1px] bg-[#E2E8F0]"></div>
                <div class="flex items-center space-x-3">
                    <div class="text-[20px] font-extrabold text-[#10B981]">99.9%</div>
                    <div class="text-[11px] font-semibold text-[#64748B] leading-tight">System<br>Uptime SLA</div>
                </div>
            </div>

        </div>

        <!-- RIGHT PANEL (Floating Login Card) -->
        <div class="w-full lg:w-[40%] xl:w-[38%] flex flex-col items-center justify-center relative transition-all duration-700 ease-out transform" :class="show ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-8'">
            {{ $slot }}
        </div>
        
    </main>

    <!-- Global Footer -->
    <footer class="relative z-10 w-full py-3 text-center text-[12px] font-medium text-[#64748B] flex flex-col sm:flex-row items-center justify-center space-y-1 sm:space-y-0 sm:space-x-6 border-t border-[#E2E8F0]/50 bg-white/40 backdrop-blur-xs">
        <span>&copy; 2026 OralCares by HSC Enterprise. All rights reserved.</span>
        <div class="flex items-center space-x-4">
            <a href="#" class="hover:text-[#0F4C81] transition-colors">Privacy</a>
            <span>&middot;</span>
            <a href="#" class="hover:text-[#0F4C81] transition-colors">Terms</a>
            <span>&middot;</span>
            <a href="#" class="hover:text-[#0F4C81] transition-colors">Support</a>
        </div>
    </footer>

</body>
</html>
