<x-master-layout>
    <x-slot:title>Onboard New Clinic - OralCares</x-slot:title>
    <x-slot:header>Onboard New Clinic</x-slot:header>

    <div class="max-w-2xl">
        <!-- Back Button -->
        <div class="mb-6">
            <a href="{{ route('master.clinics.index') }}" class="inline-flex items-center space-x-2 text-xs font-semibold text-gray-500 hover:text-gray-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                <span>Back to Clinics</span>
            </a>
        </div>

        @if($eligibleOwners->isEmpty())
            <!-- Empty state if no eligible owners exist -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-8 text-center">
                <div class="inline-flex p-3 bg-amber-100 text-amber-700 rounded-full mb-3">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <h4 class="text-sm font-bold text-gray-900 tracking-tight">No Eligible Clinic Users Found</h4>
                <p class="text-xs text-gray-500 max-w-md mx-auto mt-1">An eligible user of type <span class="font-mono bg-gray-100 px-1 rounded text-amber-700">clinic_user</span> must exist in the system before you can onboard a clinic. Please register or seed a clinic user account first.</p>
            </div>
        @else
            <!-- Onboarding Form -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <form method="POST" action="{{ route('master.clinics.store') }}" class="p-6 space-y-6">
                    @csrf

                    <!-- Domain Section -->
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4">Clinic Identity</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Name -->
                            <div>
                                <label for="clinic_name" class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1">Clinic Display Name</label>
                                <input type="text"
                                       name="clinic_name"
                                       id="clinic_name"
                                       value="{{ old('clinic_name') }}"
                                       required
                                       placeholder="e.g. Smile Dental Care"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                @error('clinic_name')
                                    <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Slug / Subdomain -->
                            <div>
                                <label for="clinic_slug" class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1">Clinic Slug (Subdomain)</label>
                                <div class="flex rounded-lg shadow-sm">
                                    <input type="text"
                                           name="clinic_slug"
                                           id="clinic_slug"
                                           value="{{ old('clinic_slug') }}"
                                           required
                                           placeholder="smiledental"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-l-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 border-r-0">
                                    <span class="inline-flex items-center px-3 rounded-r-lg border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-xs font-medium">
                                        .oralcares.in
                                    </span>
                                </div>
                                @error('clinic_slug')
                                    <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <hr class="border-gray-200">

                    <!-- Primary Owner Section -->
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4">Primary Owner Assignment</h3>
                        <div>
                            <label for="owner_id" class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1">Select Clinic Owner</label>
                            <select name="owner_id" id="owner_id" required class="w-full py-2 pl-3 pr-8 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                <option value="">-- Choose Eligible Clinic User --</option>
                                @foreach($eligibleOwners as $owner)
                                    <option value="{{ $owner->id }}" {{ old('owner_id') == $owner->id ? 'selected' : '' }}>
                                        {{ $owner->name }} ({{ $owner->email }})
                                    </option>
                                @endforeach
                            </select>
                            @error('owner_id')
                                <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                            @enderror
                            <p class="text-xs text-gray-500 mt-1.5">Only users registered with the <span class="font-semibold text-gray-700">clinic_user</span> type are eligible to own clinics.</p>
                        </div>
                    </div>

                    <hr class="border-gray-200">

                    <!-- Primary Branch Section -->
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4">Default Branch</h3>
                        <div>
                            <label for="primary_branch_name" class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1">Primary Branch Name</label>
                            <input type="text"
                                   name="primary_branch_name"
                                   id="primary_branch_name"
                                   value="{{ old('primary_branch_name') }}"
                                   required
                                   placeholder="e.g. Main Branch"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                            @error('primary_branch_name')
                                <p class="text-xs text-rose-600 mt-1 font-medium">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Submit Section -->
                    <div class="pt-4 border-t border-gray-200 flex justify-end space-x-3 bg-gray-50 -mx-6 -mb-6 p-6">
                        <a href="{{ route('master.clinics.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 transition duration-150">
                            Cancel
                        </a>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition duration-150">
                            Onboard Clinic
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-master-layout>
