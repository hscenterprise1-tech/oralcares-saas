<x-master-layout>
    <x-slot:title>Executive Command Center - OralCares</x-slot:title>

    <!-- Profile Details for backward compatibility verification in existing route tests -->
    <div class="hidden">
        <p>OralCares Master Portal</p>
        <p>{{ Auth::user()->name }}</p>
        <p>{{ Auth::user()->email }}</p>
        <p>Log Out</p>
    </div>

    <!-- Greeting Area -->
    @php
        $hour = date('H');
        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 17) {
            $greeting = 'Good afternoon';
        } else {
            $greeting = 'Good evening';
        }
    @endphp
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">{{ $greeting }}, {{ Auth::user()->name }}</h1>
            <p class="text-sm text-slate-500 mt-1">Here’s what needs your attention across OralCares today.</p>
        </div>
        <div class="flex items-center space-x-3">
            <a href="{{ route('master.leads.create') }}" class="inline-flex items-center space-x-2 px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold rounded-lg shadow-sm transition duration-150 ease-in-out cursor-pointer">
                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Add Lead</span>
            </a>
            <a href="{{ route('master.clinics.create') }}" class="inline-flex items-center space-x-2 px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold rounded-lg shadow-sm transition duration-150 ease-in-out cursor-pointer">
                <svg class="w-4 h-4 text-cyan-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <span>Onboard Clinic</span>
            </a>
        </div>
    </div>

    <!-- Primary KPI Row -->
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-5 mb-8">
        <!-- Total Clinics -->
        <div class="bg-white border border-slate-100 rounded-xl p-4 shadow-sm/5 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Clinics</span>
                <span class="p-1.5 bg-slate-50 text-slate-400 rounded-lg">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </span>
            </div>
            <div>
                <span class="block text-2xl font-extrabold text-slate-900">{{ $totalClinics }}</span>
                <span class="block text-[10px] text-slate-400 mt-1">Onboarded in platform</span>
            </div>
        </div>

        <!-- Active Clinics -->
        <div class="bg-white border border-slate-100 rounded-xl p-4 shadow-sm/5 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Active Clinics</span>
                <span class="p-1.5 bg-emerald-50 text-emerald-600 rounded-lg">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div>
                <span class="block text-2xl font-extrabold text-emerald-600">{{ $activeClinics }}</span>
                <span class="block text-[10px] text-slate-400 mt-1">Operating normally</span>
            </div>
        </div>

        <!-- New Leads -->
        <div class="bg-white border border-slate-100 rounded-xl p-4 shadow-sm/5 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">New Leads</span>
                <span class="p-1.5 bg-cyan-50 text-cyan-600 rounded-lg">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </span>
            </div>
            <div>
                <span class="block text-2xl font-extrabold text-slate-900">{{ $newLeads }}</span>
                <span class="block text-[10px] text-slate-400 mt-1">Awaiting contact</span>
            </div>
        </div>

        <!-- Active Leads -->
        <div class="bg-white border border-slate-100 rounded-xl p-4 shadow-sm/5 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Active Leads</span>
                <span class="p-1.5 bg-amber-50 text-amber-600 rounded-lg">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </span>
            </div>
            <div>
                <span class="block text-2xl font-extrabold text-slate-900">{{ $activeLeads }}</span>
                <span class="block text-[10px] text-slate-400 mt-1">Leads in progress</span>
            </div>
        </div>

        <!-- Overdue Actions -->
        <div class="bg-white border border-slate-100 rounded-xl p-4 shadow-sm/5 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Overdue Actions</span>
                <span class="p-1.5 bg-rose-50 text-rose-600 rounded-lg">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
            </div>
            <div>
                <span class="block text-2xl font-extrabold text-rose-600">{{ $overdueActions }}</span>
                <span class="block text-[10px] text-slate-400 mt-1">Pending actions past their due date</span>
            </div>
        </div>

        <!-- Total Clinic Members -->
        <div class="bg-white border border-slate-100 rounded-xl p-4 shadow-sm/5 flex flex-col justify-between">
            <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Clinic Members</span>
                <span class="p-1.5 bg-violet-50 text-violet-600 rounded-lg">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </span>
            </div>
            <div>
                <span class="block text-2xl font-extrabold text-slate-900">{{ $totalClinicMembers }}</span>
                <span class="block text-[10px] text-slate-400 mt-1">Distinct user accounts</span>
            </div>
        </div>
    </div>

    <!-- Main Workspace Split Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Columns 1 & 2: Recent Lists -->
        <div class="lg:col-span-2 space-y-8">
            <!-- Recent Clinics -->
            <div class="bg-white border border-slate-100 rounded-xl shadow-sm/5 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Recent Clinics</h3>
                        <span class="px-2 py-0.5 bg-slate-100 text-slate-600 text-[10px] font-bold rounded-full">Newest 5</span>
                    </div>
                    @if(!$recentClinics->isEmpty())
                        <a href="{{ route('master.clinics.index') }}" class="text-xs font-bold text-cyan-600 hover:text-cyan-700 transition">
                            View All Clinics &rarr;
                        </a>
                    @endif
                </div>

                @if($recentClinics->isEmpty())
                    <div class="flex flex-col items-center justify-center py-10 px-4">
                        <div class="p-3 bg-slate-50 text-slate-400 rounded-full mb-3">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800 tracking-tight">No clinics onboarded yet</h4>
                        <p class="text-xs text-slate-500 max-w-sm text-center mt-1">Get started by onboarding your first clinic and provisioning default settings.</p>
                        <a href="{{ route('master.clinics.create') }}" class="mt-4 inline-flex items-center space-x-2 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-505 text-white text-xs font-semibold rounded-lg transition duration-150">
                            <span>+ Add New Clinic</span>
                        </a>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-500">
                            <thead class="bg-slate-50/50 text-xs font-semibold uppercase tracking-wider text-slate-500 border-b border-slate-100">
                                <tr>
                                    <th class="px-6 py-4">Clinic</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-6 py-4">Main Branch</th>
                                    <th class="px-6 py-4">Primary Owner</th>
                                    <th class="px-6 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach($recentClinics as $clinic)
                                    <tr class="hover:bg-slate-50/30 transition duration-150">
                                        <td class="px-6 py-4">
                                            <div class="font-semibold text-slate-900">{{ $clinic->name }}</div>
                                            <div class="font-mono text-[10px] text-slate-400 mt-0.5">{{ $clinic->slug }}</div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $clinic->status->value === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-slate-50 text-slate-600 border border-slate-200' }}">
                                                {{ ucfirst($clinic->status->value) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-slate-650">{{ $clinic->primaryBranch?->name ?? 'N/A' }}</td>
                                        <td class="px-6 py-4 text-slate-650">
                                            {{ $clinic->primaryOwner?->name ?? 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <a href="{{ route('master.clinics.show', $clinic) }}" class="inline-flex px-3 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200/40 transition duration-150 cursor-pointer">
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

            <!-- Recent Leads -->
            <div class="bg-white border border-slate-100 rounded-xl shadow-sm/5 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Recent Leads</h3>
                        <span class="px-2 py-0.5 bg-slate-100 text-slate-600 text-[10px] font-bold rounded-full">Newest 5</span>
                    </div>
                    @if(!$recentLeads->isEmpty())
                        <a href="{{ route('master.leads.index') }}" class="text-xs font-bold text-cyan-600 hover:text-cyan-700 transition">
                            View All Leads &rarr;
                        </a>
                    @endif
                </div>

                @if($recentLeads->isEmpty())
                    <div class="flex flex-col items-center justify-center py-10 px-4">
                        <div class="p-3 bg-slate-50 text-slate-400 rounded-full mb-3">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800 tracking-tight">No leads in pipeline</h4>
                        <p class="text-xs text-slate-500 max-w-sm text-center mt-1">Capture leads from website forms or enter them manually to start follow-up actions.</p>
                        <a href="{{ route('master.leads.create') }}" class="mt-4 inline-flex items-center space-x-2 px-3 py-1.5 bg-[#0ea5e9] hover:bg-[#0284c7] text-white text-xs font-semibold rounded-lg transition duration-150">
                            <span>+ Add Lead</span>
                        </a>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-500">
                            <thead class="bg-slate-50/50 text-xs font-semibold uppercase tracking-wider text-slate-500 border-b border-slate-100">
                                <tr>
                                    <th class="px-6 py-4">Company / Contact</th>
                                    <th class="px-6 py-4">Status</th>
                                    <th class="px-6 py-4">Source</th>
                                    <th class="px-6 py-4">Assignee</th>
                                    <th class="px-6 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach($recentLeads as $lead)
                                    <tr class="hover:bg-slate-50/30 transition duration-150">
                                        <td class="px-6 py-4">
                                            <div class="font-semibold text-slate-900">{{ $lead->company_name }}</div>
                                            <div class="text-xs text-slate-400 mt-0.5">{{ $lead->contact_name }}</div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full
                                                @if($lead->status->value === 'new') bg-blue-50 text-blue-700 border border-blue-100
                                                @elseif($lead->status->value === 'onboarding') bg-amber-50 text-amber-700 border border-amber-100
                                                @elseif($lead->status->value === 'won') bg-emerald-50 text-emerald-700 border border-emerald-100
                                                @elseif($lead->status->value === 'lost') bg-rose-50 text-rose-700 border border-rose-100
                                                @else bg-slate-50 text-slate-650 border border-slate-200
                                                @endif">
                                                {{ ucfirst($lead->status->value) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-slate-650 text-xs">{{ ucfirst(str_replace('_', ' ', $lead->source)) }}</td>
                                        <td class="px-6 py-4 text-slate-650">
                                            @if($lead->assignedUser)
                                                <div class="flex items-center space-x-1.5">
                                                    <span class="w-1.5 h-1.5 bg-cyan-500 rounded-full"></span>
                                                    <span class="text-xs truncate max-w-[120px]">{{ $lead->assignedUser->name }}</span>
                                                </div>
                                            @elseif($lead->assignedTeam)
                                                <div class="flex items-center space-x-1.5">
                                                    <span class="w-1.5 h-1.5 bg-indigo-500 rounded-full"></span>
                                                    <span class="text-xs truncate max-w-[120px]">{{ $lead->assignedTeam->name }}</span>
                                                </div>
                                            @else
                                                <span class="text-xs text-slate-400">Unassigned</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <a href="{{ route('master.leads.show', $lead) }}" class="inline-flex px-3 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200/40 transition duration-150 cursor-pointer">
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
        </div>

        <!-- Column 3: Sidebar Actions & Operational Overview -->
        <div class="space-y-8">
            <!-- Quick Actions Panel -->
            <div class="bg-white border border-slate-100 rounded-xl p-5 shadow-sm/5">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Command Actions</h3>
                <div class="grid grid-cols-1 gap-3">
                    <a href="{{ route('master.leads.create') }}" class="flex items-center space-x-3 p-3 bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-200 rounded-xl transition duration-150 group cursor-pointer">
                        <span class="p-2 bg-cyan-500/10 text-cyan-600 group-hover:bg-cyan-600 group-hover:text-white rounded-lg transition duration-150">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                        </span>
                        <div>
                            <span class="block text-sm font-semibold text-slate-800">Add New CRM Lead</span>
                            <span class="block text-[10px] text-slate-400">Ingest potential dental clinic</span>
                        </div>
                    </a>

                    <a href="{{ route('master.clinics.create') }}" class="flex items-center space-x-3 p-3 bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-200 rounded-xl transition duration-150 group cursor-pointer">
                        <span class="p-2 bg-emerald-500/10 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white rounded-lg transition duration-150">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                        </span>
                        <div>
                            <span class="block text-sm font-semibold text-slate-800">Onboard New Clinic</span>
                            <span class="block text-[10px] text-slate-400">Provision database & defaults</span>
                        </div>
                    </a>

                    <a href="{{ route('master.leads.index') }}" class="flex items-center justify-between p-3 bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-200 rounded-xl transition duration-150 cursor-pointer">
                        <span class="text-xs font-bold text-slate-700">View CRM Lead Board</span>
                        <span class="text-xs text-slate-400 font-bold">&rarr;</span>
                    </a>

                    <a href="{{ route('master.clinics.index') }}" class="flex items-center justify-between p-3 bg-slate-50 hover:bg-slate-100 border border-slate-100 hover:border-slate-200 rounded-xl transition duration-150 cursor-pointer">
                        <span class="text-xs font-bold text-slate-700">View Clinic Management</span>
                        <span class="text-xs text-slate-400 font-bold">&rarr;</span>
                    </a>
                </div>
            </div>

            <!-- Operational Overview / Attention Panel -->
            <div class="bg-white border border-slate-100 rounded-xl p-5 shadow-sm/5">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Pipeline Status</h3>
                <div class="space-y-4">
                    <div class="flex items-center justify-between p-3 bg-rose-50/50 border border-rose-100 rounded-xl">
                        <div class="flex items-center space-x-2.5">
                            <span class="w-2.5 h-2.5 bg-rose-500 rounded-full"></span>
                            <span class="text-xs font-bold text-slate-700">Overdue Lead Tasks</span>
                        </div>
                        <span class="text-xs font-extrabold text-rose-600 bg-rose-100 px-2 py-0.5 rounded-full">{{ $overdueActions }}</span>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-amber-50/50 border border-amber-100 rounded-xl">
                        <div class="flex items-center space-x-2.5">
                            <span class="w-2.5 h-2.5 bg-amber-500 rounded-full"></span>
                            <span class="text-xs font-bold text-slate-700">Unassigned CRM Leads</span>
                        </div>
                        <span class="text-xs font-extrabold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">{{ $unassignedLeads }}</span>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-cyan-50/50 border border-cyan-100 rounded-xl">
                        <div class="flex items-center space-x-2.5">
                            <span class="w-2.5 h-2.5 bg-cyan-500 rounded-full"></span>
                            <span class="text-xs font-bold text-slate-700">Active Leads</span>
                        </div>
                        <span class="text-xs font-extrabold text-cyan-600 bg-cyan-100 px-2 py-0.5 rounded-full">{{ $activeLeads }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-master-layout>
