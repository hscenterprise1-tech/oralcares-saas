@props([
    'name',
    'show' => false,
    'title' => 'Details'
])

<div x-data="{ show: @js($show) }"
     x-show="show"
     x-on:open-drawer.window="if ($event.detail.name === '{{ $name }}') show = true"
     x-on:close-drawer.window="if ($event.detail.name === '{{ $name }}') show = false"
     x-on:keydown.escape.window="show = false"
     class="fixed inset-0 z-40 overflow-hidden"
     style="display: none;"
     x-transition>

    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" @click="show = false"></div>
    
    <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
      <div class="w-screen max-w-md transform bg-[var(--color-bg-overlay)] shadow-[var(--shadow-xl)] border-l border-[var(--color-border)] flex flex-col justify-between"
           @click.away="show = false">
        
        <div class="h-16 px-6 border-b border-[var(--color-border)] flex items-center justify-between">
          <h2 class="text-base font-bold text-[var(--color-text-main)]">{{ $title }}</h2>
          <button @click="show = false" class="text-slate-400 hover:text-[var(--color-text-main)] cursor-pointer focus-ring rounded p-1" aria-label="Close drawer">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="flex-1 p-6 overflow-y-auto text-sm text-[var(--color-text-main)]">
            {{ $slot }}
        </div>
      </div>
    </div>
</div>
