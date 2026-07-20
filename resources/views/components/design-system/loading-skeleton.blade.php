@props([
    'variant' => 'line', // line, card, table
    'rows' => 3
])

<div class="space-y-4 w-full" aria-hidden="true">
    @if($variant === 'line')
        @for($i = 0; $i < $rows; $i++)
            <div class="h-4 bg-[var(--neutral-100)] rounded w-full animate-pulse"></div>
        @endfor
    @elseif($variant === 'card')
        <div class="border border-[var(--color-border)] rounded-lg p-6 space-y-4 bg-[var(--color-bg-card)]">
            <div class="h-4 bg-[var(--neutral-100)] rounded w-1/3 animate-pulse"></div>
            <div class="h-8 bg-[var(--neutral-100)] rounded w-2/3 animate-pulse"></div>
            <div class="h-3 bg-[var(--neutral-100)] rounded w-full animate-pulse"></div>
        </div>
    @elseif($variant === 'table')
        <div class="border border-[var(--color-border)] rounded-lg overflow-hidden">
            <div class="h-10 bg-[var(--neutral-50)] dark:bg-[var(--neutral-800)] border-b border-[var(--color-border)] animate-pulse"></div>
            <div class="p-4 space-y-3">
                @for($i = 0; $i < $rows; $i++)
                    <div class="h-8 bg-[var(--neutral-100)] rounded w-full animate-pulse"></div>
                @endfor
            </div>
        </div>
    @endif
</div>
