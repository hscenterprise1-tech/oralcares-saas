<x-master-layout>
    <x-slot:title>Lead Details - {{ $lead->company_name }}</x-slot:title>
    <x-slot:header>Lead Details</x-slot:header>

    <!-- Navigation Header -->
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('master.leads.index') }}" class="inline-flex items-center text-sm font-semibold text-gray-500 hover:text-gray-700 transition">
            &larr; Back to Leads Listing
        </a>
        <a href="{{ route('master.leads.edit', $lead) }}" class="inline-flex items-center space-x-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
            </svg>
            <span>Edit Lead</span>
        </a>
    </div>

    <!-- Main Workspace Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left Panel: Profile Metadata Card -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6 space-y-6">
                <!-- Clinic Title & Subtitle -->
                <div>
                    <h2 class="text-xl font-bold text-gray-900 tracking-tight">{{ $lead->company_name }}</h2>
                    <span class="text-xs text-gray-400 font-mono mt-1 block">Slug: {{ $lead->slug }}</span>
                </div>

                <!-- Status & Source Badges -->
                <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100">
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
                    <span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full bg-gray-50 border border-gray-200 text-gray-600">
                        Source: {{ ucfirst(str_replace('_', ' ', $lead->source)) }}
                    </span>
                </div>

                <!-- Profile Data Fields -->
                <div class="space-y-4 pt-4 border-t border-gray-100 text-sm">
                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Contact Person</span>
                        <span class="block font-medium text-gray-900 mt-1">{{ $lead->contact_name }}</span>
                    </div>

                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Phone Number</span>
                        <span class="block font-mono text-gray-800 mt-1">{{ $lead->phone }}</span>
                    </div>

                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Email Address</span>
                        <span class="block font-medium text-gray-800 mt-1">{{ $lead->email ?? 'N/A' }}</span>
                    </div>

                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400">Assigned To</span>
                        <span class="block font-medium text-gray-900 mt-1">
                            @if($lead->assignedUser)
                                <span class="text-blue-600 font-semibold">{{ $lead->assignedUser->name }}</span>
                            @elseif($lead->assignedTeam)
                                <span class="text-violet-600 font-semibold">{{ $lead->assignedTeam->name }} (Team)</span>
                            @else
                                <span class="text-gray-400 italic">Unassigned</span>
                            @endif
                        </span>
                    </div>
                </div>

                <!-- Operational dates -->
                <div class="pt-4 border-t border-gray-100 text-xs text-gray-500 space-y-1">
                    <div>Created: {{ $lead->created_at->format('Y-m-d H:i e') }}</div>
                    <div>Last Updated: {{ $lead->updated_at->format('Y-m-d H:i e') }}</div>
                </div>
            </div>
        </div>

        <!-- Right Panel: Internal Activity Feed & Note Submissions -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Quick Note Form Card -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4">Add Internal Note</h3>
                <form method="POST" action="{{ route('master.leads.notes.store', $lead) }}" class="space-y-4">
                    @csrf
                    <div>
                        <textarea name="note"
                                  id="note"
                                  rows="3"
                                  class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm placeholder-gray-400"
                                  placeholder="Type an internal note regarding follow-up, clinic requirements, or sales logs..."
                                  required></textarea>
                        @error('note')
                            <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex items-center justify-end">
                        <button type="submit" class="text-xs bg-gray-950 hover:bg-gray-900 text-white font-semibold py-2 px-4 rounded-lg shadow-sm transition">
                            Submit Note
                        </button>
                    </div>
                </form>
            </div>

            <!-- Activity Timeline Card -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-6">Activity Timeline</h3>

                @if($activities->isEmpty())
                    <p class="text-sm text-gray-400 italic">No timeline events logged yet.</p>
                @else
                    <!-- Timeline Wrapper -->
                    <div class="flow-root">
                        <ul role="list" class="-mb-8">
                            @foreach($activities as $activity)
                                <li>
                                    <div class="relative pb-8">
                                        <!-- Line Connector -->
                                        @if(!$loop->last)
                                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                        @endif

                                        <div class="relative flex space-x-3">
                                            <!-- Circle Badge Indicator -->
                                            <div>
                                                <span class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white
                                                    {{ $activity->type->value === 'lead_created' ? 'bg-blue-100 text-blue-600' : '' }}
                                                    {{ $activity->type->value === 'status_changed' ? 'bg-indigo-100 text-indigo-600' : '' }}
                                                    {{ $activity->type->value === 'assignment_changed' ? 'bg-purple-100 text-purple-600' : '' }}
                                                    {{ $activity->type->value === 'note_added' ? 'bg-amber-100 text-amber-600' : '' }}
                                                    {{ $activity->type->value === 'lead_won' ? 'bg-emerald-100 text-emerald-600' : '' }}
                                                    {{ $activity->type->value === 'lead_lost' ? 'bg-rose-100 text-rose-600' : '' }}
                                                ">
                                                    @if($activity->type->value === 'note_added')
                                                        <!-- Speech Balloon -->
                                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                                        </svg>
                                                    @elseif($activity->type->value === 'lead_created')
                                                        <!-- Document Add -->
                                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                        </svg>
                                                    @else
                                                        <!-- Activity check -->
                                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                                        </svg>
                                                    @endif
                                                </span>
                                            </div>

                                            <!-- Timeline Text Content -->
                                            <div class="flex-1 min-w-0 pt-1.5 flex justify-between space-x-4">
                                                <div>
                                                    <p class="text-sm text-gray-800">
                                                        <span class="font-bold text-gray-900">{{ ucfirst(str_replace('_', ' ', $activity->type->value)) }}:</span>
                                                        {{ $activity->description }}
                                                    </p>
                                                    @if($activity->user)
                                                        <p class="text-xs text-gray-400 mt-1">Operator: {{ $activity->user->name }}</p>
                                                    @else
                                                        <p class="text-xs text-gray-400 mt-1">Operator: System</p>
                                                    @endif
                                                </div>
                                                <div class="text-right text-xs text-gray-500 whitespace-nowrap">
                                                    {{ $activity->created_at->format('Y-m-d H:i') }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-6 border-t border-gray-150 pt-4">
                        {{ $activities->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-master-layout>
