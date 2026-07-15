<x-master-layout>
    <x-slot:title>Clinic Details - {{ $clinic->name }}</x-slot:title>
    <x-slot:header>Clinic Details: {{ $clinic->name }}</x-slot:header>

    <!-- Navigation Header -->
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('master.clinics.index') }}" class="inline-flex items-center space-x-2 text-xs font-semibold text-gray-500 hover:text-gray-700 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            <span>Back to Clinics</span>
        </a>
    </div>

    <!-- Clinic Details Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Left: Clinic Overview & Main Branch -->
        <div class="lg:col-span-2 space-y-8">
            <!-- Clinic Overview Card -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-55">
                    <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Clinic Overview</h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Clinic Name</span>
                        <span class="font-semibold text-gray-900">{{ $clinic->name }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Subdomain Slug</span>
                        <span class="font-mono text-gray-700 bg-gray-100 px-2 py-0.5 rounded text-xs">{{ $clinic->slug }}.oralcares.in</span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Status</span>
                        <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                            {{ ucfirst($clinic->status->value) }}
                        </span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Created Date</span>
                        <span class="text-gray-700">{{ $clinic->created_at->format('Y-m-d H:i') }}</span>
                    </div>
                </div>
            </div>

            <!-- Main Branch Card -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Main Branch Identity</h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Branch Name</span>
                        <span class="font-semibold text-gray-900">{{ $clinic->primaryBranch?->name ?? 'N/A' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Branch Slug</span>
                        <span class="font-mono text-gray-700 bg-gray-100 px-2 py-0.5 rounded text-xs">{{ $clinic->primaryBranch?->slug ?? 'N/A' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Branch Status</span>
                        @if($clinic->primaryBranch)
                            <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                {{ ucfirst($clinic->primaryBranch->status->value) }}
                            </span>
                        @else
                            <span class="text-gray-400">N/A</span>
                        @endif
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Role Scope</span>
                        <span class="text-gray-700">Clinic-wide primary/default branch</span>
                    </div>
                </div>
            </div>

            <!-- Cloned Role Templates Card -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Provisioned Role Settings</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-500">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3">Role Name</th>
                                <th class="px-6 py-3">Role Key</th>
                                <th class="px-6 py-3">Cloned Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach($clinic->roles as $role)
                                <tr>
                                    <td class="px-6 py-3.5 font-medium text-gray-900">{{ $role->name }}</td>
                                    <td class="px-6 py-3.5 font-mono text-xs text-gray-600">{{ $role->key }}</td>
                                    <td class="px-6 py-3.5">
                                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $role->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                            {{ $role->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right: Primary Owner Details -->
        <div class="space-y-8">
            <!-- Owner Card -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-slate-900 text-white">
                    <h3 class="text-sm font-bold uppercase tracking-wider">Primary Owner</h3>
                </div>
                <div class="p-6 space-y-4 text-sm">
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Full Name</span>
                        <span class="font-bold text-gray-900 text-base">{{ $clinic->primaryOwner?->name ?? 'N/A' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Email Address</span>
                        <span class="text-gray-700">{{ $clinic->primaryOwner?->email ?? 'N/A' }}</span>
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Membership Status</span>
                        @if($clinic->primaryOwnerMembership)
                            <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                {{ ucfirst($clinic->primaryOwnerMembership->status->value) }}
                            </span>
                        @else
                            <span class="text-gray-400">N/A</span>
                        @endif
                    </div>
                    <div>
                        <span class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Branch Access Scope</span>
                        @if($clinic->primaryOwnerMembership?->has_all_branches_access)
                            <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">
                                All Branches Access
                            </span>
                        @else
                            <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full bg-slate-50 text-slate-700 border border-slate-200">
                                Restricted Scope
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-master-layout>
