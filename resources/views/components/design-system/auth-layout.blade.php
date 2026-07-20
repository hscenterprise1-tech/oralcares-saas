@props([
    'brandPanel' => null
])
<div class="min-h-screen flex flex-col lg:flex-row relative bg-[var(--color-bg-app)]">
    <!-- Left Brand Panel (40% width on Desktop, hidden on Mobile) -->
    <div class="hidden lg:flex lg:w-2/5 bg-[var(--brand-navy-950)] text-white flex-col justify-between p-12 border-r border-[#1B344E] relative overflow-hidden select-none">
        
        <!-- Ambient Background Radial Glow -->
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-[var(--brand-teal-600)]/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-[var(--brand-navy-700)]/30 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Geometric Hairline Grid Overlay -->
        <div class="absolute inset-0 bg-[linear-gradient(rgba(27,52,78,0.2)_1px,transparent_1px),linear-gradient(90deg,rgba(27,52,78,0.2)_1px,transparent_1px)] bg-[size:40px_40px] pointer-events-none"></div>

        <!-- Brand Panel Content -->
        <div class="relative z-10 h-full flex flex-col justify-between">
            {{ $brandPanel }}
        </div>
    </div>

    <!-- Right Login Workspace (60% width on Desktop, full width on Mobile) -->
    <div class="flex-1 flex flex-col justify-center items-center px-6 py-12 bg-[var(--color-bg-app)] relative">
        {{ $slot }}
    </div>
</div>
