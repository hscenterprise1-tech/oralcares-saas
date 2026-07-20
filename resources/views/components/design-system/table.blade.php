@props([
    'headers' => []
])

<div class="overflow-x-auto w-full border border-[var(--color-border)] rounded-lg shadow-sm">
    <table class="min-w-full divide-y divide-[var(--color-border)] text-left text-sm text-[var(--color-text-main)]">
        <thead class="bg-gray-50 dark:bg-[var(--color-neutral-800)] text-[var(--color-text-muted)] font-medium text-xs uppercase tracking-wider">
            <tr>
                @foreach($headers as $header)
                    <th scope="col" class="px-6 py-3">
                        {{ $header }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-border)] bg-white dark:bg-[var(--color-bg-card)]">
            {{ $slot }}
        </tbody>
    </table>
</div>
