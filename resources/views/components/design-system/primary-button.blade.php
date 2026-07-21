@props([
    'type' => 'button'
])
<button 
    type="{{ $type }}" 
    x-bind:disabled="loading"
    {{ $attributes->merge(['class' => 'btn-primary-enterprise w-full h-[56px] inline-flex items-center justify-center font-semibold text-base rounded-xl cursor-pointer disabled:bg-[#E2E8F0] disabled:text-[#94A3B8] disabled:cursor-not-allowed disabled:transform-none disabled:shadow-none select-none']) }}
>
    <template x-if="loading">
        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white shrink-0" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
    </template>
    {{ $slot }}
</button>
