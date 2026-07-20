@props([
    'title',
    'subtitle' => null
])
<div class="mb-8">
    <h1 class="text-2xl font-extrabold text-[var(--color-text-main)] tracking-tight">{{ $title }}</h1>
    <div class="w-10 h-1 bg-[var(--brand-teal-600)] rounded-full mt-2.5"></div>
    @if($subtitle)
        <p class="text-sm text-[var(--color-text-muted)] mt-2.5 leading-relaxed">{{ $subtitle }}</p>
    @endif
</div>
