<x-master-layout>
    <x-slot:title>Clinics Management - OralCares</x-slot:title>
    <x-slot:header>Clinics Management</x-slot:header>

    <!-- Control Bar -->
    <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <!-- Search and Filter Form -->
        <form method="GET" action="{{ route('master.clinics.index') }}" class="flex flex-wrap items-center gap-3">
            <!-- Search Input -->
            <div class="relative">
                <input type="text"
                       name="search"
                       value="{{ $search }}"
                       placeholder="Search name or slug..."
                       class="w-64 pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
            </div>

            <!-- Status Dropdown -->
            <select name="status" class="py-2 pl-3 pr-8 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                <option value="">All Statuses</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                <option value="suspended" {{ $status === 'suspended' ? 'selected' : '' }}>Suspended</option>
            </select>

            <!-- Buttons -->
            <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-lg transition duration-150">
                Filter
            </button>

            @if($search || $status)
                <a href="{{ route('master.clinics.index') }}" class="text-xs font-semibold text-gray-500 hover:text-gray-700 transition">
                    Clear Filters
                </a>
            @endif
        </form>

        <!-- Create Button -->
        <a href="{{ route('master.clinics.create') }}" class="inline-flex items-center space-x-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition duration-150 ease-in-out">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
            </svg>
            <span>Create Clinic</span>
        </a>
    </div>

    <!-- Clinics Content -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        @if($clinics->isEmpty())
            <!-- Empty State -->
            <div class="flex flex-col items-center justify-center py-16 px-4">
                <div class="p-4 bg-indigo-50 text-indigo-600 rounded-full mb-4">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <h4 class="text-sm font-bold text-gray-900 tracking-tight">No Clinics Found</h4>
                <p class="text-xs text-gray-500 max-w-sm text-center mt-1">
                    @if($search || $status)
                        Try adjusting your search query or status filter.
                    @else
                        Create your first clinic to manage patient records and branches.
                    @endif
                </p>
                @if(!$search && !$status)
                    <a href="{{ route('master.clinics.create') }}" class="mt-4 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition duration-150 ease-in-out">
                        Create Clinic
                    </a>
                @endif
            </div>
        @else
            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-500">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4">Clinic</th>
                            <th class="px-6 py-4">Slug</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Main Branch</th>
                            <th class="px-6 py-4">Primary Owner</th>
                            <th class="px-6 py-4">Branches</th>
                            <th class="px-6 py-4">Created</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach($clinics as $clinic)
                            <tr class="hover:bg-gray-50/50 transition duration-150">
                                <td class="px-6 py-4 font-semibold text-gray-900">{{ $clinic->name }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-gray-600">{{ $clinic->slug }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full {{ $clinic->status->value === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
                                        {{ ucfirst($clinic->status->value) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-700">{{ $clinic->primaryBranch?->name ?? 'N/A' }}</td>
                                <td class="px-6 py-4 text-gray-700">{{ $clinic->primaryOwner?->name ?? 'N/A' }}</td>
                                <td class="px-6 py-4 text-gray-700 font-medium">{{ $clinic->branches_count }}</td>
                                <td class="px-6 py-4 text-gray-600 text-xs">{{ $clinic->created_at->format('Y-m-d') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('master.clinics.show', $clinic) }}" class="inline-flex px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-lg transition duration-150">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination Footer -->
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                {{ $clinics->links() }}
            </div>
        @endif
    </div>
</x-master-layout>
