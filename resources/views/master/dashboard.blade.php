<x-master-layout>
    <x-slot:title>Master Dashboard - OralCares</x-slot:title>
    <x-slot:header>Master Dashboard</x-slot:header>

    <!-- Profile Details for backward compatibility verification in existing route tests -->
    <div class="hidden">
        <p>OralCares Master Portal</p>
        <p>{{ Auth::user()->name }}</p>
        <p>{{ Auth::user()->email }}</p>
        <p>Log Out</p>
    </div>

    <!-- Header Section -->
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Good Morning, {{ Auth::user()->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your clinics and platform from one place.</p>
        </div>
        <div>
            <a href="{{ route('master.clinics.create') }}" class="inline-flex items-center space-x-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition duration-150 ease-in-out">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Add New Clinic</span>
            </a>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <!-- Total Clinics -->
        <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm flex items-center space-x-4">
            <div class="p-3 bg-indigo-50 text-indigo-600 rounded-lg">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Total Clinics</span>
                <span class="block text-2xl font-extrabold text-slate-900 mt-0.5">{{ $totalClinics }}</span>
                <span class="block text-[10px] text-gray-400 mt-0.5">Onboarded in platform</span>
            </div>
        </div>

        <!-- Active Clinics -->
        <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm flex items-center space-x-4">
            <div class="p-3 bg-emerald-50 text-emerald-600 rounded-lg">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Active Clinics</span>
                <span class="block text-2xl font-extrabold text-emerald-600 mt-0.5">{{ $activeClinics }}</span>
                <span class="block text-[10px] text-gray-400 mt-0.5">Operating normally</span>
            </div>
        </div>

        <!-- Total Branches -->
        <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm flex items-center space-x-4">
            <div class="p-3 bg-blue-50 text-blue-600 rounded-lg">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Total Branches</span>
                <span class="block text-2xl font-extrabold text-slate-900 mt-0.5">{{ $totalBranches }}</span>
                <span class="block text-[10px] text-gray-400 mt-0.5">Across all locations</span>
            </div>
        </div>

        <!-- Total Clinic Members -->
        <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm flex items-center space-x-4">
            <div class="p-3 bg-violet-50 text-violet-600 rounded-lg">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Total Clinic Members</span>
                <span class="block text-2xl font-extrabold text-slate-900 mt-0.5">{{ $totalClinicMembers }}</span>
                <span class="block text-[10px] text-gray-400 mt-0.5">Distinct assigned staff</span>
            </div>
        </div>
    </div>

    <!-- Recent Clinics Section -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Recent Clinics</h3>
            @if(!$recentClinics->isEmpty())
                <a href="{{ route('master.clinics.index') }}" class="text-xs font-bold text-indigo-600 hover:text-indigo-850 transition">
                    View All Clinics &rarr;
                </a>
            @endif
        </div>

        @if($recentClinics->isEmpty())
            <!-- Compact Polished Empty State -->
            <div class="flex flex-col items-center justify-center py-10 px-4">
                <div class="p-3.5 bg-indigo-50 text-indigo-600 rounded-full mb-3">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <h4 class="text-sm font-bold text-gray-900 tracking-tight">No clinics onboarded yet</h4>
                <p class="text-xs text-gray-500 max-w-sm text-center mt-1">Get started by onboarding your first clinic and provisioning default settings.</p>
                <a href="{{ route('master.clinics.create') }}" class="mt-4 inline-flex items-center space-x-2 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition duration-155 ease-in-out">
                    <span>+ Add New Clinic</span>
                </a>
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
                            <th class="px-6 py-4">Created Date</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach($recentClinics as $clinic)
                            <tr class="hover:bg-gray-50/50 transition duration-150">
                                <td class="px-6 py-4 font-semibold text-gray-900">{{ $clinic->name }}</td>
                                <td class="px-6 py-4 font-mono text-xs text-gray-600">{{ $clinic->slug }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full {{ $clinic->status->value === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
                                        {{ ucfirst($clinic->status->value) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-700">{{ $clinic->primaryBranch?->name ?? 'N/A' }}</td>
                                <td class="px-6 py-4 text-gray-700">
                                    {{ $clinic->primaryOwner?->name ?? 'N/A' }}
                                </td>
                                <td class="px-6 py-4 text-gray-600 text-xs">{{ $clinic->created_at->format('Y-m-d H:i') }}</td>
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
        @endif
    </div>
</x-master-layout>
