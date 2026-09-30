<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Search Results') }}
        </h2>
    </x-slot>

    <div class="py-12" x-data="advancedSearch()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 flex flex-col md:flex-row gap-6">
            
            <!-- Sidebar Filters -->
            <div class="w-full md:w-64 shrink-0">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Filters</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Module</label>
                            <select x-model="filters.module" @change="fetchResults" class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-blue-500 focus:outline-none focus:ring-blue-500 sm:text-sm">
                                <option value="">All Modules</option>
                                <template x-for="module in modules" :key="module">
                                    <option :value="module" x-text="module"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Results Area -->
            <div class="flex-1">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-center justify-between mb-6">
                        <div class="w-full max-w-xl">
                            <input type="text" x-model="query" @input.debounce.500ms="fetchResults" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-lg p-3" placeholder="Search...">
                        </div>
                        <div class="text-sm text-gray-500" x-text="`${totalResults} results found`"></div>
                    </div>

                    <div class="space-y-4" x-show="!isLoading">
                        <template x-for="item in flattenedResults" :key="item.model_id">
                            <div class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10 uppercase" x-text="item.module"></span>
                                        </div>
                                        <a :href="item.url" class="text-lg font-medium text-blue-600 hover:text-blue-800" x-text="item.title"></a>
                                        <p class="text-sm text-gray-600 mt-1" x-text="item.subtitle"></p>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <!-- Actions -->
                                        <a :href="item.url" class="text-gray-400 hover:text-gray-600">View &rarr;</a>
                                    </div>
                                </div>
                            </div>
                        </template>
                        
                        <div x-show="flattenedResults.length === 0" class="text-center py-12 text-gray-500">
                            No results found. Try adjusting your search or filters.
                        </div>
                    </div>

                    <div x-show="isLoading" class="flex justify-center py-12">
                        <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>
            </div>
            
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('advancedSearch', () => ({
                query: new URLSearchParams(window.location.search).get('q') || '',
                filters: {
                    module: ''
                },
                modules: ['patient', 'billing', 'inventory', 'crm'],
                results: {},
                flattenedResults: [],
                totalResults: 0,
                isLoading: false,

                init() {
                    if (this.query) {
                        this.fetchResults();
                    }
                },

                async fetchResults() {
                    this.isLoading = true;
                    try {
                        let url = `/api/search?q=${encodeURIComponent(this.query)}`;
                        if (this.filters.module) {
                            url += `&module=${encodeURIComponent(this.filters.module)}`;
                        }
                        
                        const response = await fetch(url);
                        const json = await response.json();
                        
                        this.results = json.data;
                        this.totalResults = json.meta.total;
                        
                        this.flattenedResults = [];
                        Object.keys(this.results).forEach(module => {
                            this.results[module].forEach(item => {
                                this.flattenedResults.push(item);
                            });
                        });
                        
                        // Update URL without reload
                        window.history.replaceState({}, '', `/search/results?q=${encodeURIComponent(this.query)}`);
                    } catch (error) {
                        console.error('Search failed:', error);
                    } finally {
                        this.isLoading = false;
                    }
                }
            }));
        });
    </script>
</x-app-layout>
