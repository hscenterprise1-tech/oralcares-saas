@props([
    'label' => 'Password',
    'id' => 'password',
    'name' => 'password'
])
<div class="relative w-full" x-data="{ show: false }">
    <input 
        :type="show ? 'text' : 'password'" 
        id="{{ $id }}" 
        name="{{ $name }}" 
        placeholder=" " 
        {{ $attributes->merge(['class' => 'peer block w-full pl-3.5 pr-12 pb-2.5 pt-6 text-sm text-[var(--color-text-main)] bg-white dark:bg-[var(--color-neutral-800)] border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-1 focus:ring-[var(--brand-teal-600)] focus:border-[var(--brand-teal-600)] transition-colors duration-150']) }} 
    />
    <label for="{{ $id }}" class="absolute text-sm text-[var(--color-text-muted)] duration-150 transform -translate-y-3.5 scale-75 top-4.5 z-10 origin-[0] left-3.5 peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-3.5 cursor-text">
        {{ $label }}
    </label>
    <button type="button" @click="show = !show" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] cursor-pointer" :aria-pressed="show.toString()">
        <svg x-show="!show" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
        <svg x-show="show" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24" style="display: none;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.815 7.815L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
    </button>
</div>
