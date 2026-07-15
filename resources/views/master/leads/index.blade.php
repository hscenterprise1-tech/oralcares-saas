<x-master-layout>
    <x-slot:title>Leads Management - OralCares</x-slot:title>
    <x-slot:header>Leads Management</x-slot:header>

    <!-- Top Workspace Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Leads & Pipeline</h1>
            <p class="text-sm text-gray-500 mt-1">Acquire prospective clinics, schedule demos, and drive conversions.</p>
        </div>
        <div>
            <a href="{{ route('master.leads.create') }}" class="inline-flex items-center space-x-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition duration-150 ease-in-out">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Add Lead</span>
            </a>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-6">
        <form method="GET" action="{{ route('master.leads.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
            <!-- Search -->
            <div>
                <label for="search" class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Search</label>
                <input type="text"
                       name="search"
                       id="search"
                       value="{{ $search }}"
                       placeholder="Name, email, phone..."
                       class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm">
            </div>

            <!-- Status -->
            <div>
                <label for="status" class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Status</label>
                <select name="status" id="status" class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm">
                    <option value="">All Statuses</option>
                    @foreach(\App\LeadStatus::cases() as $case)
                        <option value="{{ $case->value }}" {{ $status === $case->value ? 'selected' : '' }}>
                            {{ ucfirst(str_replace('_', ' ', $case->value)) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Source -->
            <div>
                <label for="source" class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Source</label>
                <select name="source" id="source" class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm">
                    <option value="">All Sources</option>
                    @foreach(\App\Models\Lead::sources() as $key => $label)
                        <option value="{{ $key }}" {{ $source === $key ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Assignee -->
            <div>
                <label for="assignee" class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Assignee</label>
                <select name="assignee" id="assignee" class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm">
                    <option value="">All Assignees</option>
                    <option value="unassigned" {{ $assignee === 'unassigned' ? 'selected' : '' }}>Unassigned</option>
                    <optgroup label="Internal Staff">
                        @foreach($users as $user)
                            <option value="user_{{ $user->id }}" {{ $assignee === "user_{$user->id}" ? 'selected' : '' }}>
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Teams">
                        @foreach($teams as $team)
                            <option value="team_{{ $team->id }}" {{ $assignee === "team_{$team->id}" ? 'selected' : '' }}>
                                {{ $team->name }}
                            </option>
                        @endforeach
                    </optgroup>
                </select>
            </div>

            <!-- Actions -->
            <div class="flex space-x-2">
                <button type="submit" class="flex-1 text-sm bg-gray-950 hover:bg-gray-900 text-white font-semibold py-2 px-4 rounded-lg shadow-sm transition">
                    Filter
                </button>
                @if($search || $status || $source || $assignee)
                    <a href="{{ route('master.leads.index') }}" class="text-sm bg-gray-100 hover:bg-gray-250 text-gray-700 font-semibold py-2 px-4 rounded-lg shadow-sm border border-gray-200 transition text-center">
                        Clear
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Leads Listing -->
    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        @if($leads->isEmpty())
            <!-- Empty state -->
            <div class="flex flex-col items-center justify-center py-16 px-4">
                <div class="p-4 bg-indigo-50 text-indigo-600 rounded-full mb-4">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </div>
                <h4 class="text-base font-bold text-gray-900 tracking-tight">
                    {{ ($search || $status || $source || $assignee) ? 'No leads match your filters' : 'No prospective leads onboarded' }}
                </h4>
                <p class="text-sm text-gray-500 max-w-sm text-center mt-1">
                    {{ ($search || $status || $source || $assignee) ? 'Try expanding your keywords or selecting different status/source filters.' : 'Get started by creating your first sales lead to track opportunities.' }}
                </p>
                @if(!($search || $status || $source || $assignee))
                    <a href="{{ route('master.leads.create') }}" class="mt-4 inline-flex items-center space-x-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition">
                        <span>Add Your First Lead</span>
                    </a>
                @endif
            </div>
        @else
            <!-- Table layout -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-500">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4">Clinic / Company</th>
                            <th class="px-6 py-4">Contact</th>
                            <th class="px-6 py-4">Phone</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Source</th>
                            <th class="px-6 py-4">Assigned To</th>
                            <th class="px-6 py-4">Next Action</th>
                            <th class="px-6 py-4">Last Updated</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach($leads as $lead)
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="px-6 py-4 font-semibold text-gray-900">
                                    <div class="flex flex-col">
                                        <span>{{ $lead->company_name }}</span>
                                        <span class="text-xs text-gray-400 font-mono mt-0.5">{{ $lead->slug }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-gray-700 font-medium">{{ $lead->contact_name }}</td>
                                <td class="px-6 py-4 text-gray-600 font-mono text-xs">{{ $lead->phone }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full border
                                        {{ $lead->status->value === 'new' ? 'bg-slate-50 text-slate-700 border-slate-200' : '' }}
                                        {{ $lead->status->value === 'contacted' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : '' }}
                                        {{ $lead->status->value === 'demo_scheduled' ? 'bg-purple-50 text-purple-700 border-purple-200' : '' }}
                                        {{ $lead->status->value === 'demo_completed' ? 'bg-violet-50 text-violet-700 border-violet-200' : '' }}
                                        {{ $lead->status->value === 'proposal_sent' ? 'bg-pink-50 text-pink-700 border-pink-200' : '' }}
                                        {{ $lead->status->value === 'negotiation' ? 'bg-orange-50 text-orange-700 border-orange-200' : '' }}
                                        {{ $lead->status->value === 'won' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : '' }}
                                        {{ $lead->status->value === 'lost' ? 'bg-rose-50 text-rose-700 border-rose-200' : '' }}
                                    ">
                                        {{ ucfirst(str_replace('_', ' ', $lead->status->value)) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-600 text-xs">
                                    {{ ucfirst(str_replace('_', ' ', $lead->source)) }}
                                </td>
                                <td class="px-6 py-4 text-gray-700 text-xs">
                                    @if($lead->assignedUser)
                                        <span class="inline-flex items-center space-x-1">
                                            <span class="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                                            <span>{{ $lead->assignedUser->name }}</span>
                                        </span>
                                    @elseif($lead->assignedTeam)
                                        <span class="inline-flex items-center space-x-1">
                                            <span class="w-1.5 h-1.5 bg-violet-500 rounded-full"></span>
                                            <span>{{ $lead->assignedTeam->name }}</span>
                                        </span>
                                    @else
                                        <span class="text-gray-400 italic">Unassigned</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-xs font-mono">
                                    @if($lead->next_action_at)
                                        @php
                                            $nextDate = \Carbon\Carbon::parse($lead->next_action_at);
                                            $isOverdue = $nextDate->isPast();
                                        @endphp
                                        @if($isOverdue)
                                            <span class="inline-flex items-center space-x-1 text-rose-600 font-extrabold">
                                                <span class="px-1.5 py-0.5 bg-rose-100 text-rose-800 text-[10px] font-bold rounded uppercase tracking-wider">Overdue</span>
                                                <span>{{ $nextDate->format('Y-m-d H:i') }}</span>
                                            </span>
                                        @else
                                            <span class="text-indigo-600 font-semibold">
                                                {{ $nextDate->format('Y-m-d H:i') }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-gray-400 italic">No Action Scheduled</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-gray-600 text-xs">{{ $lead->updated_at->format('Y-m-d H:i') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end space-x-2">
                                        <a href="{{ route('master.leads.show', $lead) }}" class="inline-flex px-2.5 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-lg shadow-sm transition">
                                            View
                                        </a>
                                        <a href="{{ route('master.leads.edit', $lead) }}" class="inline-flex px-2.5 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-600 text-xs font-semibold rounded-lg border border-gray-200 shadow-sm transition">
                                            Edit
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                {{ $leads->links() }}
            </div>
        @endif
    </div>
</x-master-layout>
