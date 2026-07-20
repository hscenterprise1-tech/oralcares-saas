@props([
    'message' => '',
    'type' => 'info' // info, success, error
])

<div x-data="{ show: true }" 
     x-show="show" 
     x-init="setTimeout(() => show = false, 3000)" 
     x-transition
     {{ $attributes->merge(['class' => 'fixed bottom-6 right-6 z-50 p-4 rounded-md shadow-md flex items-center space-x-3 text-white border transition-all duration-200']) }}
     :class="type === 'success' ? 'bg-[var(--color-success)] border-emerald-500' : (type === 'error' ? 'bg-[var(--color-danger)] border-rose-500' : 'bg-[var(--brand-teal-600)] border-sky-500')"
     style="display: none;">
    <span>{{ $message }}</span>
    <button @click="show = false" class="ml-auto text-white hover:text-gray-200 cursor-pointer" aria-label="Close message">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </button>
</div>
