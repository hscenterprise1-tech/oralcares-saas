<div x-data="notificationBell()" class="relative inline-block text-left" @click.away="open = false">
    <button @click="toggle()" type="button" class="relative p-2 text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 rounded-full transition-colors">
        <span class="sr-only">View notifications</span>
        <!-- Heroicon name: outline/bell -->
        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        <span x-show="unreadCount > 0" x-text="unreadCount" class="absolute top-0 right-0 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white transform translate-x-1/4 -translate-y-1/4 bg-red-600 rounded-full" style="display: none;"></span>
    </button>

    <!-- Dropdown menu -->
    <div x-show="open" 
         x-transition:enter="transition ease-out duration-100" 
         x-transition:enter-start="transform opacity-0 scale-95" 
         x-transition:enter-end="transform opacity-100 scale-100" 
         x-transition:leave="transition ease-in duration-75" 
         x-transition:leave-start="transform opacity-100 scale-100" 
         x-transition:leave-end="transform opacity-0 scale-95" 
         class="origin-top-right absolute right-0 mt-2 w-96 rounded-lg shadow-xl bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-50 divide-y divide-gray-100" 
         role="menu" 
         aria-orientation="vertical" 
         aria-labelledby="menu-button" 
         tabindex="-1"
         style="display: none;">
        
        <div class="px-4 py-3 bg-gray-50 rounded-t-lg flex justify-between items-center">
            <p class="text-sm font-semibold text-gray-700">Notifications</p>
            <div class="flex space-x-2">
                <button @click="markAllAsRead()" class="text-xs text-blue-600 hover:text-blue-800 font-medium">Mark all read</button>
                <a href="{{ route('notifications.index') }}" class="text-xs text-gray-500 hover:text-gray-700">View all</a>
            </div>
        </div>

        <div class="max-h-96 overflow-y-auto" @scroll="checkScroll">
            <template x-if="loading && notifications.length === 0">
                <div class="p-4 text-center text-gray-500 text-sm">
                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-gray-500 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading...
                </div>
            </template>

            <template x-if="!loading && notifications.length === 0">
                <div class="p-8 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">No new notifications</p>
                </div>
            </template>

            <template x-for="notification in notifications" :key="notification.id">
                <div :class="{'bg-blue-50/50': !notification.read_at, 'hover:bg-gray-50': true}" class="p-4 border-b border-gray-100 cursor-pointer transition-colors" @click="handleNotificationClick(notification)">
                    <div class="flex space-x-3">
                        <div class="flex-shrink-0">
                            <!-- Dynamic Icon based on category -->
                            <span class="inline-flex items-center justify-center h-8 w-8 rounded-full" :class="getCategoryBgColor(notification.category)">
                                <i :class="getCategoryIcon(notification.category)" class="text-white text-sm"></i>
                            </span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900" x-text="notification.data.title"></p>
                            <p class="text-sm text-gray-500 truncate" x-text="notification.data.message"></p>
                            <p class="text-xs text-gray-400 mt-1" x-text="formatTime(notification.created_at)"></p>
                        </div>
                        <div class="flex-shrink-0 flex flex-col items-end space-y-2">
                            <template x-if="!notification.read_at">
                                <span class="h-2 w-2 bg-blue-600 rounded-full"></span>
                            </template>
                            <button @click.stop="archive(notification.id)" class="text-gray-400 hover:text-gray-600 focus:outline-none" title="Archive">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
            
            <template x-if="loadingMore">
                <div class="p-4 text-center">
                    <svg class="animate-spin h-5 w-5 text-gray-400 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('notificationBell', () => ({
        open: false,
        unreadCount: 0,
        notifications: [],
        page: 1,
        loading: false,
        loadingMore: false,
        hasMore: true,

        init() {
            this.fetchUnreadCount();
            
            // Listen for global events to refresh count (e.g. from Echo)
            window.addEventListener('notification-received', () => {
                this.unreadCount++;
                this.fetchNotifications(true); // reload list
            });

            // Reverb / Echo Listener (Placeholder logic)
            if (window.Echo) {
                const userId = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
                if (userId) {
                    window.Echo.private(`App.Models.User.${userId}`)
                        .notification((notification) => {
                            this.unreadCount++;
                            this.notifications.unshift(notification);
                            // Flash toaster here if needed
                        });
                }
            }
        },

        toggle() {
            this.open = !this.open;
            if (this.open && this.notifications.length === 0) {
                this.fetchNotifications();
            }
        },

        fetchUnreadCount() {
            fetch('/api/notifications/unread-count')
                .then(res => res.json())
                .then(data => {
                    this.unreadCount = data.count;
                });
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

            fetch(`/api/notifications?page=${this.page}`)
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

        checkScroll(e) {
            const bottom = e.target.scrollHeight - e.target.scrollTop === e.target.clientHeight;
            if (bottom && this.hasMore && !this.loadingMore) {
                this.fetchNotifications();
            }
        },

        markAllAsRead() {
            fetch('/api/notifications/mark-all-read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            }).then(() => {
                this.unreadCount = 0;
                this.notifications.forEach(n => n.read_at = new Date().toISOString());
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

        handleNotificationClick(notification) {
            if (!notification.read_at) {
                fetch(`/api/notifications/${notification.id}/read`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                }).then(() => {
                    notification.read_at = new Date().toISOString();
                    this.unreadCount = Math.max(0, this.unreadCount - 1);
                });
            }

            if (notification.data.actions && notification.data.actions.length > 0) {
                // Navigate to the first action URL
                window.location.href = notification.data.actions[0].url;
            }
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
