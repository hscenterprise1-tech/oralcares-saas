<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" id="app-html" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>{{ $title ?? 'OralCares Master Portal' }}</title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Scripts -->
  @vite(['resources/css/app.css', 'resources/js/app.js'])

  <script>
      (function() {
          const storedTheme = localStorage.getItem('theme') || 'light';
          document.documentElement.setAttribute('data-theme', storedTheme);
      })();
  </script>
</head>
<body class="bg-[var(--color-bg-app)] text-[var(--color-text-main)] antialiased transition-colors duration-250 min-h-screen"
      x-data="{
          sidebarExpanded: true,
          theme: localStorage.getItem('theme') || 'light',
          commandPaletteOpen: false,
          notificationsOpen: false,
          profileMenuOpen: false,
          orgSwitcherOpen: false,
          searchQuery: '',
          toastMessage: '',
          toastType: 'info',
          drawerOpen: false,
          drawerTitle: '',
          toggleTheme() {
              this.theme = this.theme === 'light' ? 'dark' : 'light';
              localStorage.setItem('theme', this.theme);
              document.documentElement.setAttribute('data-theme', this.theme);
          },
          showToast(msg, type = 'info') {
              this.toastMessage = msg;
              this.toastType = type;
              setTimeout(() => { this.toastMessage = ''; }, 3000);
          },
          openDrawer(title) {
              this.drawerTitle = title;
              this.drawerOpen = true;
          }
      }"
      @keydown.window.prevent.ctrl.k="commandPaletteOpen = !commandPaletteOpen"
      @keydown.window.prevent.meta.k="commandPaletteOpen = !commandPaletteOpen"
      @keydown.window.escape="commandPaletteOpen = false; drawerOpen = false;">

  <!-- Main Grid Wrapper -->
  <div class="flex min-h-screen">

    <!-- Collapsible Sidebar (Fixed Navy) -->
    <aside class="bg-[#0A2540] text-slate-300 flex flex-col justify-between border-r border-[#1B2E45] shrink-0 transition-all duration-200"
           :class="sidebarExpanded ? 'w-64' : 'w-20'">
      
      <div>
        <!-- Brand Indicator area -->
        <div class="h-16 px-5 flex items-center justify-between bg-[#08111C] border-b border-[#1B2E45] overflow-hidden">
          <a href="{{ route('company.dashboard') }}" class="flex items-center space-x-2.5 min-w-0">
            <svg class="h-7 w-7 text-cyan-400 fill-none stroke-current stroke-[1.5] shrink-0" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
            </svg>
            <span class="text-white text-lg font-bold tracking-tight truncate" x-show="sidebarExpanded">Oral<span class="text-cyan-400">Cares</span></span>
          </a>
          <button @click="sidebarExpanded = !sidebarExpanded" class="text-slate-400 hover:text-white cursor-pointer focus-ring rounded p-1 hidden md:block">
            <svg class="h-5 w-5 fill-none stroke-current stroke-2" viewBox="0 0 24 24" :class="sidebarExpanded ? 'rotate-0' : 'rotate-180'">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
            </svg>
          </button>
        </div>

        <!-- Navigation items list -->
        <nav class="p-4 space-y-2">
          <!-- Dashboard link -->
          <a href="{{ route('company.dashboard') }}"
             class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition duration-150 ease-in-out"
             :class="sidebarExpanded ? '' : 'justify-center'"
             class-active="bg-cyan-500/10 text-cyan-400 font-bold border-l-2 border-cyan-500"
             :class="request()->routeIs('company.dashboard') ? 'bg-cyan-500/10 text-cyan-400 font-bold border-l-2 border-cyan-500 pl-2.5' : 'hover:bg-slate-800/50 hover:text-white'">
            <svg class="w-5 h-5 opacity-85 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2v-4z" />
            </svg>
            <span x-show="sidebarExpanded">Dashboard</span>
          </a>

          <!-- Clinics link -->
          <a href="{{ route('master.clinics.index') }}"
             class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition duration-150 ease-in-out"
             :class="sidebarExpanded ? '' : 'justify-center'"
             :class="request()->routeIs('master.clinics.*') ? 'bg-cyan-500/10 text-cyan-400 font-bold border-l-2 border-cyan-500 pl-2.5' : 'hover:bg-slate-800/50 hover:text-white'">
            <svg class="w-5 h-5 opacity-85 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            <span x-show="sidebarExpanded">Clinics</span>
          </a>

          <!-- Leads link -->
          <a href="{{ route('company.crm.leads.index') }}"
             class="flex items-center space-x-3 px-3 py-2.5 rounded-md text-sm font-medium transition duration-150 ease-in-out"
             :class="sidebarExpanded ? '' : 'justify-center'"
             :class="request()->routeIs('company.crm.leads.*') ? 'bg-cyan-500/10 text-cyan-400 font-bold border-l-2 border-cyan-500 pl-2.5' : 'hover:bg-slate-800/50 hover:text-white'">
            <svg class="w-5 h-5 opacity-85 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
            <span x-show="sidebarExpanded">Leads</span>
          </a>
        </nav>
      </div>

      <!-- Action Footer with current user profile -->
      <div class="p-4 border-t border-[#1B2E45] bg-[#08111C]">
        <div class="flex flex-col space-y-3">
          <div class="flex items-center space-x-3" :class="sidebarExpanded ? '' : 'justify-center'">
            @php
              $names = explode(' ', Auth::user()->name);
              $initials = strtoupper(substr($names[0] ?? '', 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
            @endphp
            <div class="w-9 h-9 rounded-full bg-cyan-500/20 text-cyan-400 flex items-center justify-center text-xs font-extrabold uppercase shrink-0 border border-cyan-500/30">
              {{ $initials }}
            </div>
            <div class="min-w-0" x-show="sidebarExpanded">
              <div class="text-sm font-semibold text-white truncate">{{ Auth::user()->name }}</div>
              <div class="text-[10px] text-cyan-400 font-bold uppercase tracking-wider mt-0.5">Master Admin</div>
            </div>
          </div>
          <form method="POST" action="{{ route('logout') }}" x-show="sidebarExpanded">
            @csrf
            <button type="submit" class="w-full flex items-center justify-center space-x-2 px-3 py-2 bg-rose-600/10 hover:bg-rose-600/20 text-rose-400 hover:text-rose-300 text-xs font-semibold rounded-md border border-rose-500/20 transition duration-150 cursor-pointer">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
              <span>Log Out</span>
            </button>
          </form>
        </div>
      </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-h-screen">

      <!-- Sticky Header -->
      <header class="sticky top-0 z-30 h-16 bg-[var(--color-bg-header)] border-b border-[var(--color-border)] px-6 flex items-center justify-between shrink-0 shadow-[var(--shadow-sm)]">
        
        <!-- Left headers: breadcrumbs or titles -->
        <div class="flex items-center space-x-4">
          <!-- Dynamic Breadcrumbs -->
          <nav class="flex text-sm font-medium text-[var(--color-text-muted)]" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 md:space-x-2">
              <li class="inline-flex items-center">
                <a href="{{ route('company.dashboard') }}" class="hover:text-[var(--color-text-main)] transition-colors duration-150">OralCares Admin</a>
              </li>
              <li>
                <div class="flex items-center">
                  <svg class="w-3.5 h-3.5 text-slate-400 mx-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                  </svg>
                  <span class="text-[var(--color-text-main)] font-semibold">{{ $header ?? 'Portal' }}</span>
                </div>
              </li>
            </ol>
          </nav>
        </div>

        <!-- Center area: global search trigger -->
        <div class="hidden md:flex flex-1 max-w-md mx-8">
            <x-search-bar />
        </div>

        <!-- Right actions -->
        <div class="flex items-center space-x-4">
          <!-- Organization switcher dropdown -->
          <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" class="flex items-center space-x-1.5 px-3 py-1.5 bg-[var(--color-bg-app)] border border-[var(--color-border)] rounded-md text-xs font-semibold uppercase tracking-wider text-[var(--color-text-main)] cursor-pointer">
              <span>All Clinics</span>
              <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div x-show="open" @click.away="open = false" class="absolute right-0 mt-1 w-48 bg-[var(--color-bg-overlay)] border border-[var(--color-border)] rounded-md shadow-[var(--shadow-md)] py-1 z-50 text-sm" style="display: none;">
              <a href="#" class="block px-4 py-2 hover:bg-[var(--neutral-50)] text-[var(--color-text-main)]">All Clinics (Default)</a>
              <a href="#" class="block px-4 py-2 hover:bg-[var(--neutral-50)] text-[var(--color-text-main)]">Apex Dental Care</a>
              <a href="#" class="block px-4 py-2 hover:bg-[var(--neutral-50)] text-[var(--color-text-main)]">Smile Dental Clinic</a>
            </div>
          </div>

          <!-- Theme Toggle Switch -->
          <button @click="toggleTheme()" class="focus-ring p-1.5 bg-[var(--color-bg-app)] border border-[var(--color-border)] rounded-md cursor-pointer hover:bg-[var(--neutral-100)] text-[var(--color-text-muted)] transition-colors duration-150" aria-label="Toggle theme mode">
            <svg x-show="theme === 'light'" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
            </svg>
            <svg x-show="theme === 'dark'" class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24" style="display: none;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m0 13.5V21M4.95 4.95l1.59 1.59m10.91 10.91l1.59 1.59M3 12h2.25m13.5 0H21m-16.05 6.05l1.59-1.59m10.91-10.91l1.59 1.59M12 7.25a4.75 4.75 0 110 9.5 4.75 4.75 0 010-9.5z" />
            </svg>
          </button>

          <!-- Notification Bell -->
          <div class="relative">
            <button @click="notificationsOpen = !notificationsOpen" class="focus-ring p-1.5 bg-[var(--color-bg-app)] border border-[var(--color-border)] rounded-md cursor-pointer text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] transition duration-150" aria-label="View notifications">
              <svg class="h-5 w-5 fill-none stroke-current stroke-[1.5]" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
              </svg>
            </button>
            <div x-show="notificationsOpen" @click.away="notificationsOpen = false" class="absolute right-0 mt-1 w-80 bg-[var(--color-bg-overlay)] border border-[var(--color-border)] rounded-md shadow-[var(--shadow-lg)] py-2 z-50 text-sm" style="display: none;">
              <div class="px-4 py-2 border-b border-[var(--color-border)] font-semibold">Notifications</div>
              <div class="max-h-60 overflow-y-auto divide-y divide-[var(--color-border)]">
                <a href="#" class="block px-4 py-3 hover:bg-[var(--neutral-50)] text-xs text-[var(--color-text-main)]">
                  <p class="font-medium">New subscription registered</p>
                  <p class="text-[var(--color-text-muted)] mt-0.5">Apex Dental activated professional plan.</p>
                </a>
                <a href="#" class="block px-4 py-3 hover:bg-[var(--neutral-50)] text-xs text-[var(--color-text-main)]">
                  <p class="font-medium">System maintenance alert</p>
                  <p class="text-[var(--color-text-muted)] mt-0.5">Scheduled update on July 25th at 02:00 UTC.</p>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- Main Layout Content area -->
      <main class="flex-1 p-8 bg-[var(--color-bg-app)] relative">
        <!-- Toast Notification component -->
        <div x-show="toastMessage" class="fixed bottom-6 right-6 z-50 p-4 rounded-md shadow-[var(--shadow-md)] flex items-center space-x-3 text-white border transition-all duration-200"
             :class="toastType === 'success' ? 'bg-[var(--color-success)] border-emerald-500' : (toastType === 'error' ? 'bg-[var(--color-danger)] border-rose-500' : 'bg-[var(--brand-teal-600)] border-sky-500')"
             style="display: none;" x-transition>
          <span x-text="toastMessage"></span>
        </div>

        <!-- Session Flash Messages -->
        @if (session('success'))
            <div class="mb-6">
                <x-design-system.alert variant="success" title="Success">
                    {{ session('success') }}
                </x-design-system.alert>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6">
                <x-design-system.alert variant="danger" title="Error">
                    {{ session('error') }}
                </x-design-system.alert>
            </div>
        @endif

        {{ $slot }}
      </main>
    </div>
  </div>

  <!-- Search Modal has been moved into the x-search-bar component -->

  <!-- Reusable Drawer Drawer/Record Preview template -->
  <div x-show="drawerOpen" class="fixed inset-0 z-40 overflow-hidden" style="display: none;" x-transition>
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" @click="drawerOpen = false"></div>
    <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
      <div class="w-screen max-w-md transform bg-[var(--color-bg-overlay)] shadow-[var(--shadow-xl)] border-l border-[var(--color-border)] flex flex-col justify-between" x-transition>
        <div class="h-16 px-6 border-b border-[var(--color-border)] flex items-center justify-between">
          <h2 class="text-base font-bold text-[var(--color-text-main)]" x-text="drawerTitle">Record Preview</h2>
          <button @click="drawerOpen = false" class="text-slate-400 hover:text-[var(--color-text-main)] cursor-pointer focus-ring rounded p-1">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="flex-1 p-6 overflow-y-auto text-sm text-[var(--color-text-main)]">
          <!-- Dynamic Content placeholder -->
          <div class="space-y-4">
            <div class="h-4 bg-[var(--neutral-100)] rounded w-3/4 animate-pulse"></div>
            <div class="h-4 bg-[var(--neutral-100)] rounded w-1/2 animate-pulse"></div>
            <div class="h-32 bg-[var(--neutral-100)] rounded w-full animate-pulse"></div>
          </div>
        </div>
        <div class="p-6 border-t border-[var(--color-border)] bg-[var(--neutral-50)] dark:bg-[var(--neutral-950)]/40 flex justify-end space-x-3">
          <button @click="drawerOpen = false" class="px-4 py-2 border border-[var(--color-border)] rounded-md text-xs font-semibold uppercase tracking-wider hover:bg-[var(--neutral-50)] cursor-pointer">Cancel</button>
          <button @click="showToast('Action confirmed!', 'success'); drawerOpen = false" class="px-4 py-2 bg-[var(--brand-teal-600)] text-white hover:bg-[var(--brand-teal-400)] rounded-md text-xs font-semibold uppercase tracking-wider cursor-pointer">Save Changes</button>
        </div>
      </div>
    </div>
  </div>

</body>
</html>
