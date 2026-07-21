<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Notification Preferences') }}
        </h2>
    </x-slot>

    <div class="py-12" x-data="notificationPreferences()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="p-6 text-gray-900 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Communication Channels</h3>
                    <p class="text-sm text-gray-500 mb-6">Choose how you want to receive notifications. In-app notifications cannot be disabled.</p>
                    
                    <div class="space-y-4">
                        <template x-for="channel in availableChannels" :key="channel.id">
                            <div class="flex items-start">
                                <div class="flex h-5 items-center">
                                    <input :id="'channel-'+channel.id" type="checkbox" 
                                           :checked="!preferences.disabled_channels.includes(channel.id)"
                                           @change="toggleChannel(channel.id)"
                                           class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                </div>
                                <div class="ml-3 text-sm">
                                    <label :for="'channel-'+channel.id" class="font-medium text-gray-700" x-text="channel.name"></label>
                                    <p class="text-gray-500" x-text="channel.description"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="p-6 text-gray-900 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Notification Categories</h3>
                    <p class="text-sm text-gray-500 mb-6">Select which types of events you want to be notified about.</p>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <template x-for="category in availableCategories" :key="category.id">
                            <div class="flex items-start">
                                <div class="flex h-5 items-center">
                                    <input :id="'category-'+category.id" type="checkbox" 
                                           :checked="!preferences.disabled_categories.includes(category.id)"
                                           @change="toggleCategory(category.id)"
                                           class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                </div>
                                <div class="ml-3 text-sm">
                                    <label :for="'category-'+category.id" class="font-medium text-gray-700 capitalize" x-text="category.name"></label>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Quiet Hours</h3>
                    <p class="text-sm text-gray-500 mb-6">Mute notifications during these hours. Urgent notifications will still bypass this.</p>
                    
                    <div class="flex space-x-4 items-center">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Start Time</label>
                            <input type="time" x-model="preferences.quiet_hours_start" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        </div>
                        <span class="mt-5 text-gray-500">to</span>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">End Time</label>
                            <input type="time" x-model="preferences.quiet_hours_end" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Timezone</label>
                            <select x-model="preferences.timezone" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                                <option value="UTC">UTC</option>
                                <option value="America/New_York">Eastern Time</option>
                                <option value="America/Los_Angeles">Pacific Time</option>
                                <option value="Asia/Kolkata">IST</option>
                                <!-- add more -->
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 flex justify-end">
                    <button @click="savePreferences()" class="inline-flex justify-center rounded-md border border-transparent bg-blue-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Save Preferences
                    </button>
                    <span x-show="saved" class="ml-3 inline-flex items-center text-sm text-green-600" x-transition.duration.500ms>
                        <i class="fas fa-check mr-1"></i> Saved
                    </span>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('notificationPreferences', () => ({
                preferences: {
                    disabled_channels: [],
                    disabled_categories: [],
                    quiet_hours_start: '',
                    quiet_hours_end: '',
                    timezone: 'UTC'
                },
                availableChannels: [
                    { id: 'email', name: 'Email', description: 'Receive an email for notifications.' },
                    { id: 'whatsapp', name: 'WhatsApp', description: 'Receive messages on WhatsApp.' }
                ],
                availableCategories: [
                    { id: 'system', name: 'System Alerts' },
                    { id: 'patient', name: 'Patient Events' },
                    { id: 'billing', name: 'Billing & Invoices' },
                    { id: 'workflow', name: 'Workflow Approvals' },
                    { id: 'appointment', name: 'Appointments' }
                ],
                saved: false,

                init() {
                    fetch('/api/notifications/preferences')
                        .then(res => res.json())
                        .then(data => {
                            if (data && data.id) {
                                this.preferences = {
                                    disabled_channels: data.disabled_channels || [],
                                    disabled_categories: data.disabled_categories || [],
                                    quiet_hours_start: data.quiet_hours_start ? data.quiet_hours_start.substring(0, 5) : '',
                                    quiet_hours_end: data.quiet_hours_end ? data.quiet_hours_end.substring(0, 5) : '',
                                    timezone: data.timezone || 'UTC'
                                };
                            }
                        });
                },

                toggleChannel(id) {
                    const idx = this.preferences.disabled_channels.indexOf(id);
                    if (idx > -1) {
                        this.preferences.disabled_channels.splice(idx, 1);
                    } else {
                        this.preferences.disabled_channels.push(id);
                    }
                },

                toggleCategory(id) {
                    const idx = this.preferences.disabled_categories.indexOf(id);
                    if (idx > -1) {
                        this.preferences.disabled_categories.splice(idx, 1);
                    } else {
                        this.preferences.disabled_categories.push(id);
                    }
                },

                savePreferences() {
                    fetch('/api/notifications/preferences', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: JSON.stringify(this.preferences)
                    }).then(res => {
                        if (res.ok) {
                            this.saved = true;
                            setTimeout(() => this.saved = false, 3000);
                        }
                    });
                }
            }));
        });
    </script>
    @endpush
</x-app-layout>
