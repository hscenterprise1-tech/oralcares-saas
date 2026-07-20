@props([
    'name',
    'show' => false,
    'title' => null
])

<div x-data="{ show: @js($show) }"
     x-show="show"
     x-on:open-modal.window="if ($event.detail.name === '{{ $name }}') show = true"
     x-on:close-modal.window="if ($event.detail.name === '{{ $name }}') show = false"
     x-on:keydown.escape.window="show = false"
     class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0 flex items-center justify-center bg-slate-900/40 backdrop-blur-sm"
     style="display: none;"
     x-transition>

    <div class="bg-[var(--color-bg-overlay)] border border-[var(--color-border)] rounded-[var(--radius-lg)] shadow-[var(--shadow-xl)] overflow-hidden w-full max-w-lg transform transition-all"
         @click.away="show = false">
        
        @if($title)
            <div class="h-16 px-6 border-b border-[var(--color-border)] flex items-center justify-between">
                <h3 class="text-base font-bold text-[var(--color-text-main)]">{{ $title }}</h3>
                <button @click="show = false" class="text-slate-400 hover:text-[var(--color-text-main)] cursor-pointer focus-ring rounded p-1" aria-label="Close modal">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        @endif

        <div class="p-6 text-sm text-[var(--color-text-main)]">
            {{ $slot }}
        </div>
    </div>
</div>
