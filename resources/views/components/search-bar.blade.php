<div 
    x-data="globalSearch()" 
    @keydown.window.prevent.cmd.k="openSearch"
    @keydown.window.prevent.ctrl.k="openSearch"
    class="relative w-full max-w-md"
>
    <!-- Search Trigger (Button in Top Nav) -->
    <button @click="openSearch" class="flex items-center w-full px-4 py-2 text-sm text-gray-500 bg-gray-100 rounded-lg hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
        <svg class="w-5 h-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <span>Search anything...</span>
        <span class="ml-auto flex items-center space-x-1 text-xs">
            <kbd class="px-1.5 py-0.5 rounded-md bg-white border border-gray-300 font-sans shadow-sm">⌘</kbd>
            <kbd class="px-1.5 py-0.5 rounded-md bg-white border border-gray-300 font-sans shadow-sm">K</kbd>
        </span>
    </button>

    <!-- Search Modal Overlay -->
    <div x-show="isOpen" 
         x-transition.opacity.duration.200ms
         class="fixed inset-0 z-50 flex items-start justify-center pt-16 sm:pt-24 bg-gray-900/50 backdrop-blur-sm"
         style="display: none;">
        
        <!-- Search Modal Box -->
        <div @click.away="closeSearch" 
             x-show="isOpen"
             x-transition:enter="ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="relative w-full max-w-2xl bg-white rounded-xl shadow-2xl overflow-hidden ring-1 ring-black/5 mx-4">
            
            <!-- Input Area -->
            <div class="relative flex items-center px-4 py-4 border-b border-gray-100">
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input x-ref="searchInput" 
                       x-model="query" 
                       @input.debounce.300ms="performSearch"
                       @keydown.escape="closeSearch"
                       @keydown.down.prevent="selectNext"
                       @keydown.up.prevent="selectPrev"
                       @keydown.enter.prevent="executeAction"
                       type="text" 
                       class="w-full px-4 text-lg bg-transparent border-0 focus:ring-0 text-gray-900 placeholder-gray-400" 
                       placeholder="Search patients, invoices, modules..."
                       autocomplete="off">
                
                <div x-show="isLoading" class="absolute right-4" style="display: none;">
                    <svg class="w-5 h-5 text-blue-500 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
            </div>

            <!-- Results Area -->
            <div class="max-h-[60vh] overflow-y-auto p-2">
                <!-- Empty State -->
                <div x-show="query.length > 0 && Object.keys(results).length === 0 && !isLoading" class="px-6 py-14 text-center sm:px-14" style="display: none;">
                    <svg class="mx-auto h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <p class="mt-4 text-sm text-gray-900">No results found for "<span x-text="query" class="font-semibold"></span>".</p>
                </div>

                <!-- Grouped Results -->
                <template x-for="(items, module) in results" :key="module">
                    <div class="mb-4">
                        <h3 class="px-3 py-1.5 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 rounded-md" x-text="module"></h3>
                        <ul class="mt-1 space-y-1">
                            <template x-for="(item, index) in items" :key="item.model_id">
                                <li>
                                    <a :href="item.url" 
                                       class="block px-3 py-2 rounded-lg hover:bg-blue-50 focus:bg-blue-50 focus:outline-none transition-colors group cursor-pointer"
                                       :class="{'bg-blue-50': isSelected(module, index)}">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h4 class="text-sm font-medium text-gray-900 group-hover:text-blue-700" x-text="item.title"></h4>
                                                <p class="text-xs text-gray-500 truncate mt-0.5" x-text="item.subtitle"></p>
                                            </div>
                                            <svg class="w-4 h-4 text-gray-400 group-hover:text-blue-500 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </div>
                                    </a>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
            
            <!-- Footer -->
            <div class="px-4 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
                <div class="flex space-x-4 text-xs text-gray-500">
                    <span class="flex items-center"><kbd class="px-1 py-0.5 rounded bg-white border mr-1 font-sans">↑↓</kbd> to navigate</span>
                    <span class="flex items-center"><kbd class="px-1 py-0.5 rounded bg-white border mr-1 font-sans">↵</kbd> to select</span>
                    <span class="flex items-center"><kbd class="px-1 py-0.5 rounded bg-white border mr-1 font-sans">esc</kbd> to close</span>
                </div>
                <!-- Link to advanced search view -->
                <a href="/search/results" x-show="query.length > 0" class="text-xs text-blue-600 hover:text-blue-800 font-medium">View all results &rarr;</a>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('globalSearch', () => ({
            isOpen: false,
            query: '',
            results: {},
            isLoading: false,
            selectedIndex: -1,
            flattenedResults: [],

            openSearch() {
                this.isOpen = true;
                this.$nextTick(() => {
                    this.$refs.searchInput.focus();
                });
            },
            
            closeSearch() {
                this.isOpen = false;
                this.query = '';
                this.results = {};
            },

            async performSearch() {
                if (this.query.length < 2) {
                    this.results = {};
                    this.flattenedResults = [];
                    return;
                }

                this.isLoading = true;
                
                try {
                    const response = await fetch(`/api/search?q=${encodeURIComponent(this.query)}`);
                    const json = await response.json();
                    this.results = json.data;
                    
                    // Flatten for keyboard navigation
                    this.flattenedResults = [];
                    Object.keys(this.results).forEach(module => {
                        this.results[module].forEach((item, index) => {
                            this.flattenedResults.push({ module, index, url: item.url });
                        });
                    });
                    
                    this.selectedIndex = -1;
                } catch (error) {
                    console.error('Search failed:', error);
                } finally {
                    this.isLoading = false;
                }
            },
            
            selectNext() {
                if (this.selectedIndex < this.flattenedResults.length - 1) {
                    this.selectedIndex++;
                }
            },
            
            selectPrev() {
                if (this.selectedIndex > 0) {
                    this.selectedIndex--;
                }
            },
            
            isSelected(module, index) {
                if (this.selectedIndex === -1) return false;
                const current = this.flattenedResults[this.selectedIndex];
                return current.module === module && current.index === index;
            },
            
            executeAction() {
                if (this.selectedIndex !== -1) {
                    window.location.href = this.flattenedResults[this.selectedIndex].url;
                } else if (this.query.length > 0) {
                    window.location.href = `/search/results?q=${encodeURIComponent(this.query)}`;
                }
            }
        }));
    });
</script>
