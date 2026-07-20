<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OralCares Enterprise Design System Foundation</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[var(--color-bg-app)] text-[var(--color-text-main)] transition-colors duration-250 p-8">
  <div class="max-w-7xl mx-auto space-y-12">
    
    <!-- Title Section -->
    <header class="border-b border-[var(--color-border)] pb-6 flex justify-between items-center">
      <div>
        <h1 class="text-3xl font-bold tracking-tight text-[var(--color-text-main)]">OralCares Enterprise Design System</h1>
        <p class="text-sm text-[var(--color-text-muted)] mt-1">Reusable atomic tokens, accessible UI components, and theme-aware dashboard layouts.</p>
      </div>
      <div>
        <button id="theme-toggle" class="focus-ring bg-[var(--color-primary-50)] hover:bg-[var(--color-primary-100)] text-[var(--color-primary-600)] font-medium px-4 py-2 rounded-md cursor-pointer text-sm">
          Toggle Dark Mode
        </button>
      </div>
    </header>

    <!-- Design Tokens Section -->
    <section class="space-y-6">
      <h2 class="text-xl font-bold border-b border-[var(--color-border)] pb-2">1. Design Tokens</h2>
      
      <!-- Color Grid -->
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
        <div class="p-4 rounded-md bg-[var(--color-primary-500)] text-white font-medium text-sm shadow-sm flex flex-col justify-between h-24">
          <span>Primary Blue</span>
          <span class="text-xs opacity-90">#0ea5e9</span>
        </div>
        <div class="p-4 rounded-md bg-[var(--color-success-500)] text-white font-medium text-sm shadow-sm flex flex-col justify-between h-24">
          <span>Success Green</span>
          <span class="text-xs opacity-90">#22c55e</span>
        </div>
        <div class="p-4 rounded-md bg-[var(--color-warning-500)] text-white font-medium text-sm shadow-sm flex flex-col justify-between h-24">
          <span>Warning Amber</span>
          <span class="text-xs opacity-90">#f59e0b</span>
        </div>
        <div class="p-4 rounded-md bg-[var(--color-danger-500)] text-white font-medium text-sm shadow-sm flex flex-col justify-between h-24">
          <span>Danger Red</span>
          <span class="text-xs opacity-90">#ef4444</span>
        </div>
        <div class="p-4 rounded-md bg-[var(--color-neutral-700)] text-white font-medium text-sm shadow-sm flex flex-col justify-between h-24">
          <span>Neutral Dark</span>
          <span class="text-xs opacity-90">#334155</span>
        </div>
      </div>

      <!-- Spacing Scale Documentation -->
      <div class="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg p-6 space-y-3">
        <h3 class="text-base font-semibold">Spacing Scale & Border Radius</h3>
        <p class="text-xs text-[var(--color-text-muted)]">Uniform scale utilizing HSL variables and rounded styles for consistent structural layouts.</p>
        <div class="flex gap-2">
          <span class="px-2 py-1 text-xs bg-[var(--color-neutral-100)] rounded-[var(--radius-sm)] border border-[var(--color-border)]">Radius SM (0.375rem)</span>
          <span class="px-2 py-1 text-xs bg-[var(--color-neutral-100)] rounded-[var(--radius-md)] border border-[var(--color-border)]">Radius MD (0.5rem)</span>
          <span class="px-2 py-1 text-xs bg-[var(--color-neutral-100)] rounded-[var(--radius-lg)] border border-[var(--color-border)]">Radius LG (0.75rem)</span>
        </div>
      </div>
    </section>

    <!-- Component Showcase Section -->
    <section class="space-y-6">
      <h2 class="text-xl font-bold border-b border-[var(--color-border)] pb-2">2. Component Library Showcase</h2>

      <!-- Reusable Buttons -->
      <div class="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg p-6 space-y-4">
        <h3 class="text-base font-semibold">Buttons (Primary, Secondary, Success, Danger, Warning)</h3>
        <div class="flex flex-wrap gap-3">
          <x-design-system.button variant="primary">Primary Action</x-design-system.button>
          <x-design-system.button variant="secondary">Secondary Action</x-design-system.button>
          <x-design-system.button variant="success">Success Action</x-design-system.button>
          <x-design-system.button variant="danger">Danger Action</x-design-system.button>
          <x-design-system.button variant="warning">Warning Action</x-design-system.button>
        </div>
      </div>

      <!-- Form Inputs -->
      <div class="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg p-6 space-y-4">
        <h3 class="text-base font-semibold">Form Inputs, Labels & States</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <x-design-system.input label="Full Name" name="name" placeholder="John Doe" required="true" />
          <x-design-system.input label="Email Address" name="email" value="invalid-email" error="Please enter a valid email address." />
          
          <x-design-system.select label="Choose Plan">
            <option>Trial Plan</option>
            <option>Starter Plan</option>
            <option>Enterprise Plan</option>
          </x-design-system.select>
        </div>
        <div class="flex gap-6 mt-4">
          <x-design-system.checkbox label="I accept all terms of service" name="terms" checked="true" />
          <x-design-system.radio label="Receive daily digests" name="digest" value="daily" checked="true" />
        </div>
      </div>

      <!-- Reusable Tables & Badges -->
      <div class="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg p-6 space-y-4">
        <h3 class="text-base font-semibold">Tables & Status Badges</h3>
        <x-design-system.table :headers="['Invoice ID', 'Clinic Name', 'Total Amount', 'Status']">
          <tr>
            <td class="px-6 py-4 font-medium">INV-09123</td>
            <td class="px-6 py-4">Downtown OralCare</td>
            <td class="px-6 py-4 font-semibold">$590.00</td>
            <td class="px-6 py-4">
              <x-design-system.badge variant="success">Active</x-design-system.badge>
            </td>
          </tr>
          <tr>
            <td class="px-6 py-4 font-medium">INV-09124</td>
            <td class="px-6 py-4">Smile Dental Clinic</td>
            <td class="px-6 py-4 font-semibold">$120.00</td>
            <td class="px-6 py-4">
              <x-design-system.badge variant="danger">Overdue</x-design-system.badge>
            </td>
          </tr>
        </x-design-system.table>
      </div>

      <!-- Alerts and Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <x-design-system.card title="Billing Summary">
          <p class="mb-4">Your current billing cycle resets in 10 days. Total current usage count: 3 branches, 12 active users.</p>
          <x-slot:footer>
            <div class="flex justify-end gap-2">
              <x-design-system.button variant="secondary" size="sm">Download PDF</x-design-system.button>
              <x-design-system.button variant="primary" size="sm">Pay Now</x-design-system.button>
            </div>
          </x-slot:footer>
        </x-design-system.card>

        <div class="space-y-4">
          <x-design-system.alert variant="info" title="Scheduled Maintenance">
            The billing platform will undergo maintenance on July 25th at 02:00 UTC.
          </x-design-system.alert>
          <x-design-system.alert variant="success" title="Payment Recorded">
            Your payment was successfully received and applied to invoice #INV-09123.
          </x-design-system.alert>
        </div>
      </div>
    </section>

    <!-- Navigation & Dashboard Layout Structure -->
    <section class="space-y-6">
      <h2 class="text-xl font-bold border-b border-[var(--color-border)] pb-2">3. Layout & Dashboard Widgets</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="p-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg flex flex-col justify-between shadow-sm">
          <span class="text-xs text-[var(--color-text-muted)] font-medium uppercase tracking-wider">Active Subscriptions</span>
          <span class="text-3xl font-extrabold mt-2 text-[var(--color-primary-600)]">1,482</span>
          <span class="text-xs text-emerald-600 mt-2 font-semibold">↑ 12% vs last month</span>
        </div>
        <div class="p-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg flex flex-col justify-between shadow-sm">
          <span class="text-xs text-[var(--color-text-muted)] font-medium uppercase tracking-wider">Monthly Recurring Revenue</span>
          <span class="text-3xl font-extrabold mt-2 text-[var(--color-text-main)]">$142,500</span>
          <span class="text-xs text-emerald-600 mt-2 font-semibold">↑ 8% vs last month</span>
        </div>
        <div class="p-6 bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg flex flex-col justify-between shadow-sm">
          <span class="text-xs text-[var(--color-text-muted)] font-medium uppercase tracking-wider">Overdue Invoices</span>
          <span class="text-3xl font-extrabold mt-2 text-[var(--color-danger-500)]">18</span>
          <span class="text-xs text-[var(--color-text-muted)] mt-2">Requires manual intervention</span>
        </div>
      </div>
    </section>
  </div>

  <!-- Theme Toggle Script -->
  <script>
    const toggle = document.getElementById('theme-toggle');
    toggle.addEventListener('click', () => {
      const currentTheme = document.documentElement.getAttribute('data-theme');
      const newTheme = currentTheme === 'light' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', newTheme);
    });
  </script>
</body>
</html>
