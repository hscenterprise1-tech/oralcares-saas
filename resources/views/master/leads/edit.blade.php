<x-master-layout>
    <x-slot:title>Edit Lead - {{ $lead->company_name }}</x-slot:title>
    <x-slot:header>Edit Lead</x-slot:header>

    <div class="max-w-3xl mx-auto">
        <!-- Back Link -->
        <div class="mb-6">
            <a href="{{ route('master.leads.show', $lead) }}" class="inline-flex items-center text-sm font-semibold text-gray-500 hover:text-gray-700 transition">
                &larr; Back to Lead Details
            </a>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <h3 class="text-base font-bold text-gray-900 tracking-tight">Edit Lead Details</h3>
                <p class="text-xs text-gray-500 mt-1">Modify profile parameters, update pipeline status, or change employee assignment.</p>
            </div>

            <!-- Form -->
            <form method="POST" action="{{ route('master.leads.update', $lead) }}" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Clinic / Company Name -->
                    <div>
                        <label for="company_name" class="block text-sm font-semibold text-gray-700 mb-2">Clinic / Company Name *</label>
                        <input type="text"
                               name="company_name"
                               id="company_name"
                               value="{{ old('company_name', $lead->company_name) }}"
                               class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                               required>
                        @error('company_name')
                            <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Contact Name -->
                    <div>
                        <label for="contact_name" class="block text-sm font-semibold text-gray-700 mb-2">Contact Person Name *</label>
                        <input type="text"
                               name="contact_name"
                               id="contact_name"
                               value="{{ old('contact_name', $lead->contact_name) }}"
                               class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                               required>
                        @error('contact_name')
                            <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Phone -->
                    <div>
                        <label for="phone" class="block text-sm font-semibold text-gray-700 mb-2">Phone Number *</label>
                        <input type="text"
                               name="phone"
                               id="phone"
                               value="{{ old('phone', $lead->phone) }}"
                               class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                               required>
                        @error('phone')
                            <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address (Optional)</label>
                        <input type="email"
                               name="email"
                               id="email"
                               value="{{ old('email', $lead->email) }}"
                               class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm">
                        @error('email')
                            <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Lead Source -->
                    <div>
                        <label for="source" class="block text-sm font-semibold text-gray-700 mb-2">Lead Source *</label>
                        <select name="source" id="source" class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm" required>
                            @foreach(\App\Models\Lead::sources() as $key => $label)
                                <option value="{{ $key }}" {{ old('source', $lead->source) === $key ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('source')
                            <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="block text-sm font-semibold text-gray-700 mb-2">Lead Status *</label>
                        <select name="status" id="status" class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm" required>
                            @foreach(\App\LeadStatus::cases() as $case)
                                <option value="{{ $case->value }}" {{ old('status', $lead->status->value) === $case->value ? 'selected' : '' }}>
                                    {{ ucfirst(str_replace('_', ' ', $case->value)) }}
                                </option>
                            @endforeach
                        </select>
                        @error('status')
                            <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Assignment Section -->
                <div class="border-t border-gray-200 pt-6">
                    <h4 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4">Workflow Assignment</h4>
                    <p class="text-xs text-gray-500 mb-4">A lead can be unassigned, assigned to one internal user, or one team queue. Never assign both simultaneously.</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Assigned User -->
                        <div>
                            <label for="assigned_user_id" class="block text-sm font-semibold text-gray-700 mb-2">Assigned User</label>
                            <select name="assigned_user_id" id="assigned_user_id" class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm">
                                <option value="">Unassigned</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" {{ old('assigned_user_id', $lead->assigned_user_id) == $user->id ? 'selected' : '' }}>
                                        {{ $user->name }} ({{ str_replace('_', ' ', $user->user_type->value) }})
                                    </option>
                                @endforeach
                            </select>
                            @error('assigned_user_id')
                                <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Assigned Team -->
                        <div>
                            <label for="assigned_team_id" class="block text-sm font-semibold text-gray-700 mb-2">Assigned Team</label>
                            <select name="assigned_team_id" id="assigned_team_id" class="w-full text-sm border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm">
                                <option value="">Unassigned</option>
                                @foreach($teams as $team)
                                    <option value="{{ $team->id }}" {{ old('assigned_team_id', $lead->assigned_team_id) == $team->id ? 'selected' : '' }}>
                                        {{ $team->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('assigned_team_id')
                                <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Submission Actions -->
                <div class="border-t border-gray-200 pt-6 flex items-center justify-end space-x-3">
                    <a href="{{ route('master.leads.show', $lead) }}" class="text-sm bg-white hover:bg-gray-50 text-gray-700 font-semibold py-2 px-4 rounded-lg border border-gray-200 transition">
                        Cancel
                    </a>
                    <button type="submit" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded-lg shadow-sm transition">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-master-layout>
