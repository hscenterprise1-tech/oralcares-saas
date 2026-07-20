@props([
    'title',
    'subtitle' => null
])
<div class="mb-8">
    <h1 class="text-2xl font-extrabold text-[var(--color-text-main)] tracking-tight">{{ $title }}</h1>
    @if($subtitle)
        <p class="text-sm text-[var(--color-text-muted)] mt-2">{{ $subtitle }}</p>
    @endif
</div>
