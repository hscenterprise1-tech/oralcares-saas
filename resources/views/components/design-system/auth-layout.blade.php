@props([
    'brandPanel' => null
])
<div class="min-h-screen flex flex-col lg:flex-row relative bg-[var(--color-bg-app)]">
    <!-- Left Brand Panel (40% width on Desktop, hidden on Mobile) -->
    <div class="hidden lg:flex lg:w-2/5 bg-[var(--brand-navy-900)] text-white flex-col justify-between p-12 border-r border-[var(--color-border)] select-none">
        {{ $brandPanel }}
    </div>

    <!-- Right Login Panel (60% width on Desktop, full width on Mobile) -->
    <div class="flex-1 flex flex-col justify-center items-center px-6 py-12 bg-[var(--color-bg-app)]">
        {{ $slot }}
    </div>
</div>
