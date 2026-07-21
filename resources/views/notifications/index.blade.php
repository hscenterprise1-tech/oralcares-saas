<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Notification Center') }}
        </h2>
    </x-slot>

    <div class="py-12" x-data="notificationCenter()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <!-- Controls -->
            <div class="bg-white p-4 rounded-lg shadow-sm mb-6 flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="flex items-center space-x-4 w-full sm:w-auto">
                    <select x-model="filters.category" @change="fetchNotifications(true)" class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                        <option value="">All Categories</option>
                        <option value="system">System</option>
                        <option value="patient">Patient</option>
                        <option value="billing">Billing</option>
                        <option value="workflow">Workflow</option>
                    </select>

                    <select x-model="filters.is_read" @change="fetchNotifications(true)" class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                        <option value="">All Status</option>
                        <option value="0">Unread</option>
                        <option value="1">Read</option>
                    </select>
                </div>
                
                <div class="flex items-center space-x-3 w-full sm:w-auto">
                    <button @click="markAllAsRead()" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
                        Mark All Read
                    </button>
                    <a href="{{ route('notifications.preferences.show') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Preferences
                    </a>
                </div>
            </div>

            <!-- List -->
            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden relative">
                
                <div x-show="loading && page === 1" class="absolute inset-0 bg-white/70 flex items-center justify-center z-10">
                    <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <ul role="list" class="divide-y divide-gray-200">
                    <template x-for="notification in notifications" :key="notification.id">
                        <li :class="{'bg-blue-50/30': !notification.read_at, 'hover:bg-gray-50': true}" class="p-6 transition duration-150 ease-in-out cursor-default">
                            <div class="flex items-center space-x-4">
                                <div class="flex-shrink-0">
                                    <span class="inline-flex items-center justify-center h-10 w-10 rounded-full" :class="getCategoryBgColor(notification.category)">
                                        <i :class="getCategoryIcon(notification.category)" class="text-white text-lg"></i>
                                    </span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900" x-text="notification.data.title"></p>
                                    <p class="text-sm text-gray-500 mt-1" x-text="notification.data.message"></p>
                                    
                                    <template x-if="notification.data.actions && notification.data.actions.length > 0">
                                        <div class="mt-3 flex gap-2">
                                            <template x-for="action in notification.data.actions" :key="action.label">
                                                <a :href="action.url" 
                                                   class="inline-flex items-center rounded bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                   x-text="action.label"></a>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                                <div class="flex-shrink-0 flex flex-col items-end space-y-3">
                                    <p class="text-xs text-gray-400" x-text="formatTime(notification.created_at)"></p>
                                    <div class="flex gap-2">
                                        <button x-show="!notification.read_at" @click="markAsRead(notification)" class="text-xs text-blue-600 hover:text-blue-800">Mark Read</button>
                                        <button @click="archive(notification.id)" class="text-xs text-gray-500 hover:text-gray-700">Archive</button>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </template>

                    <template x-if="!loading && notifications.length === 0">
                        <li class="p-12 text-center text-gray-500">
                            <i class="fas fa-bell-slash text-4xl mb-3 text-gray-300"></i>
                            <p>No notifications found matching your filters.</p>
                        </li>
                    </template>
                </ul>

                <div class="p-4 border-t border-gray-200" x-show="hasMore">
                    <button @click="fetchNotifications()" :disabled="loadingMore" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <span x-show="!loadingMore">Load More</span>
                        <svg x-show="loadingMore" class="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('notificationCenter', () => ({
                notifications: [],
                page: 1,
                loading: false,
                loadingMore: false,
                hasMore: true,
                filters: {
                    category: '',
                    is_read: ''
                },

                init() {
                    this.fetchNotifications(true);
                },

                fetchNotifications(reset = false) {
                    if (reset) {
                        this.page = 1;
                        this.notifications = [];
                        this.hasMore = true;
                        this.loading = true;
                    } else {
                        this.loadingMore = true;
                    }

                    const params = new URLSearchParams({
                        page: this.page,
                        ...this.filters
                    });

                    fetch(`/api/notifications?${params.toString()}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.data.length > 0) {
                                this.notifications = reset ? data.data : [...this.notifications, ...data.data];
                                this.page++;
                            } else {
                                this.hasMore = false;
                            }
                        })
                        .finally(() => {
                            this.loading = false;
                            this.loadingMore = false;
                        });
                },

                markAsRead(notification) {
                    fetch(`/api/notifications/${notification.id}/read`, {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    }).then(() => {
                        notification.read_at = new Date().toISOString();
                        // Dispatch global event to update bell icon
                        window.dispatchEvent(new CustomEvent('notification-read'));
                    });
                },

                markAllAsRead() {
                    fetch('/api/notifications/mark-all-read', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    }).then(() => {
                        this.notifications.forEach(n => n.read_at = new Date().toISOString());
                        window.dispatchEvent(new CustomEvent('notification-read-all'));
                    });
                },

                archive(id) {
                    fetch(`/api/notifications/${id}/archive`, {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    }).then(() => {
                        this.notifications = this.notifications.filter(n => n.id !== id);
                    });
                },

                formatTime(dateString) {
                    const date = new Date(dateString);
                    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                },

                getCategoryBgColor(category) {
                    const colors = {
                        'system': 'bg-gray-500',
                        'security': 'bg-red-500',
                        'success': 'bg-green-500',
                        'warning': 'bg-yellow-500',
                        'patient': 'bg-blue-500',
                        'billing': 'bg-green-600',
                        'workflow': 'bg-purple-500'
                    };
                    return colors[category] || 'bg-blue-500';
                },

                getCategoryIcon(category) {
                    const icons = {
                        'system': 'fas fa-cog',
                        'security': 'fas fa-shield-alt',
                        'success': 'fas fa-check',
                        'warning': 'fas fa-exclamation-triangle',
                        'patient': 'fas fa-user',
                        'billing': 'fas fa-file-invoice-dollar',
                        'workflow': 'fas fa-project-diagram'
                    };
                    return icons[category] || 'fas fa-bell';
                }
            }));
        });
    </script>
    @endpush
</x-app-layout>
