@props([
    'text' => null
])
<div class="relative flex py-5 items-center">
    <div class="flex-grow border-t border-[var(--color-border)]"></div>
    @if($text)
        <span class="flex-shrink mx-4 text-xs font-bold uppercase tracking-wider text-[var(--color-text-muted)]">{{ $text }}</span>
    @endif
    <div class="flex-grow border-t border-[var(--color-border)]"></div>
</div>
