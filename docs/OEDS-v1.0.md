# OralCares Enterprise Design System (OEDS) v1.0

> [!IMPORTANT]  
> **Permanent Design Authority**  
> This document is the permanent visual and UX authority for the OralCares platform. Every future page, module, and component—including CRM, Organizations, Finance, Reports, Support, Billing, Settings, Analytics, and all Healthcare modules—must strictly follow OEDS. Any new UI that does not comply with OEDS must be considered incomplete and refactored before merging into production.

---

## 1. Design Philosophy

- **Brand Vision:** OralCares is the definitive, flagship enterprise SaaS for healthcare networks. It must instantly communicate scale, security, and precision to investors, hospital owners, and clinicians.
- **Product Personality:** Clinical elegance. It is not playful, not generic, and not a standard "Tailwind template." It is sharp, deeply intelligent, and tactile.
- **UX Principles:** Premium Before Feature. Every visible pixel must feel intentional. The interface should explain the business (Enterprise Intelligence) rather than merely displaying data.
- **Visual Identity:** Absolute white surfaces floating on micro-textured slate backgrounds. Deep obsidian text for power; surgical indigo and crisp emerald for action and success. Zero harsh borders; ultra-diffused optical shadows.

---

## 2. Design Tokens

### Core Variables
- **Grid Base:** 4px
- **Border Radius:** `rounded-2xl` (1rem) for massive surfaces, `rounded-xl` (0.75rem) for interactive blocks, `rounded-md` (0.375rem) for micro-elements.
- **Borders:** Ultra-fine hairlines. `border-slate-900/5` or `border-black/5`. Never hard grays.
- **Shadows (Tactile Elevation):** 
  - *Base:* `shadow-[0_8px_40px_-12px_rgba(0,0,0,0.05)]`
  - *Hover:* `shadow-[0_20px_40px_-15px_rgba(0,0,0,0.06)]`
- **Animation Curves (Apple/Linear Standard):** 
  - *Easing:* `cubic-bezier(0.16, 1, 0.3, 1)`
  - *Duration:* `500ms` to `700ms` for massive structural changes, `300ms` for micro-interactions.

---

## 3. Typography System

| Role | Tailwind Classes | Usage |
| :--- | :--- | :--- |
| **Display** | `text-[2.75rem] font-black tracking-tight leading-none text-slate-950` | Hero Greetings, Massive singular page headers |
| **Heading** | `text-2xl font-bold tracking-tight text-slate-900` | Major section breaks |
| **Card Title**| `text-lg font-bold text-slate-900` | Standard card headers |
| **Micro-Label**| `text-[0.65rem] tracking-[0.2em] uppercase font-semibold text-slate-400` | Contextual tags above headers. Recedes into background on squint test. |
| **Metrics** | `text-5xl font-black tracking-tighter text-slate-950` | The Big 3 KPIs. Unignorable. |
| **Body** | `text-sm font-medium text-slate-600` | Standard descriptive text |
| **Action** | `text-sm font-semibold text-slate-700` | Button text, interactive links |
| **Helper / Error** | `text-xs font-medium text-slate-500` / `text-rose-500` | Form instructions or validation messages |

---

## 4. Color System

| Category | Tailwind Value | Purpose |
| :--- | :--- | :--- |
| **Surface** | `bg-white` | All foreground cards and interactive panels |
| **Background**| `bg-[#F8FAFC]` (slate-50) | The deep background canvas |
| **Obsidian (Neutral)** | `text-slate-950` | Power text, metrics, primary headers |
| **Surgical (Primary)** | `indigo-600` | Primary actions, branding, active states |
| **Success** | `emerald-500` | Positive trends, Won deals, Active orgs |
| **Danger** | `rose-500` / `rose-600` | Errors, Lost deals, Critical alerts |
| **Warning** | `amber-500` | Pending states, attention required |

---

## 5. Layout System

- **Container Width:** `max-w-[1600px] mx-auto` for Enterprise Dashboard. Fluid `w-full` for deep CRM tables.
- **Asymmetric Grid:** `lg:grid-cols-12`. Main content spans `lg:col-span-8` (70%), Command Center spans `lg:col-span-4` (30%).
- **Breathing Space:** Sections must use `gap-8` (32px). Cards must use `p-8` for massive layouts, `p-6` for dense metrics. Avoid cramped `p-4` unless inside a micro-component.

---

## 6. Card System

All cards must utilize the `signature-card` class structure:

```css
.signature-card {
    background: #FFFFFF;
    border: 1px solid rgba(15, 23, 42, 0.04);
    box-shadow: 0 10px 40px -10px rgba(0,0,0,0.03), 0 1px 3px rgba(0,0,0,0.02);
    border-radius: 1.25rem;
    transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}
.signature-card:hover {
    transform: scale(1.005) translateY(-2px);
    box-shadow: 0 20px 40px -15px rgba(0,0,0,0.06), 0 4px 6px rgba(0,0,0,0.02);
}
```

- **Empty State:** A muted slate icon (w-12 h-12) with a bold title and soft explanation text. Centered.
- **Loading State:** Subtle pulse of the entire card structure, not just gray boxes.

---

## 7. Button System & Actions

- **Primary:** `bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm rounded-lg font-semibold`.
- **Secondary (Pro SaaS):** `bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 hover:border-slate-300 rounded-lg font-semibold`.
- **Ghost:** `bg-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-900`.
- **Danger:** `bg-rose-500 text-white hover:bg-rose-600 shadow-sm rounded-lg`.
- **Command Actions:** Features macOS style `<kbd>` elements for keyboard shortcuts (e.g., `⌘N`). 

---

## 8. Forms & Inputs

- **Inputs:** `bg-white border-slate-200 rounded-lg text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all`.
- **Labels:** `text-xs font-bold text-slate-700 uppercase tracking-wide`.
- **Errors:** Soft red text (`text-rose-500 text-xs mt-1`) with a subtle `border-rose-300 ring-rose-500/20` on the input.
- **Toggles:** Apple-style smooth transitions. `bg-emerald-500` for active, `bg-slate-200` for inactive.

---

## 9. Tables

- **Density:** Must support high data density. `py-3 px-4` cells.
- **Sticky Headers:** `bg-slate-50/90 backdrop-blur-sm border-b border-slate-200 font-semibold text-slate-600 uppercase text-xs tracking-wider`.
- **Row Hover:** `hover:bg-slate-50/50 transition-colors`. No hard borders between rows, only a subtle `border-b border-slate-100`.
- **Bulk Actions:** Floating pill at the bottom of the screen when items are selected.

---

## 10. Navigation

- **Breadcrumbs:** `text-sm font-medium text-slate-500`. Current page is `text-slate-900 font-bold`.
- **Sidebar:** Deep, dark, and muted, OR stark white. Never a mid-tone gray. 
- **Command Palette:** `⌘ + K` triggers a global, glassmorphic search overlay.

---

## 11. Charts & Visualization

- **Continuous Pipelines:** No generic bar charts for funnels. Build continuous flowing SVG/HTML tracks with physical segment dividers.
- **Sparklines:** Must animate on load using `stroke-dasharray`. 
- **Tooltips:** Black (`bg-slate-950 text-white rounded-md text-xs py-1 px-2 shadow-xl`).

---

## 12. Feedback Components

- **Toast Notifications:** Floating at the bottom right. Glassmorphic (`bg-white/90 backdrop-blur-md border border-slate-200 shadow-xl rounded-xl`).
- **Attention Blocks:** If an action is required, intercept the UI with a bold block (`bg-rose-50 border-l-2 border-rose-500 p-4`) containing `AlertCircle` icons.

---

## 13. States

- **Loading:** Apple-tier skeleton screens. Staggered fade-ins. 
- **Empty:** Never leave a blank space. Always explain *why* it's empty and provide a primary action button to fix it.
- **Offline / Error:** Muted states with a clear "Retry" ghost button.

---

## 14. Motion System (Premium Animation)

- **Philosophy:** Fast. Smooth. Purposeful. 
- **Staggered Entry:** All modules must load in sequence (e.g., 0ms, 100ms, 200ms) rather than appearing instantly.
- **Hover:** Do not snap colors instantly. Use `transition-all duration-300 ease-out`. For structural hover (cards), use `duration-500`.

---

## 15. Icons (Lucide Standards)

- **Library:** `lucide-react`.
- **Stroke Width:** Maintain default `strokeWidth={2}` for balance.
- **Sizing:** `w-4 h-4` for inline text support. `w-5 h-5` for headers. `w-6 h-6` for massive accents.
- **ARIA:** Always include `aria-hidden="true"` unless the icon serves as the sole label.

---

## 16. Accessibility

- **Contrast:** Ensure all `text-slate-400` micro-labels are still legible against white. 
- **Keyboard Navigation:** All actions must have accessible `tabIndex` and visual `:focus-visible` rings.
- **Screen Readers:** Use `aria-label` on complex visualizations (e.g., `aria-label="Sales pipeline distribution"`).

---

## 17. Responsive Design

- **Grid Breakpoint:** The asymmetric `8/4` layout must trigger at `lg` (1024px) for optimal laptop viewing. `md` and below must stack vertically.
- **Padding Reduction:** `p-8` on desktop scales down to `p-4` on mobile to preserve screen real estate.

---

## 18. Component Library Architecture

Every reusable UI piece must live in `resources/js/components/ui`.
Do not duplicate code. Build `<SignatureCard>`, `<Kbd>`, `<Badge>`, and pass props.

---

## 19. Page Templates

- **Dashboards:** Asymmetric (`lg:grid-cols-12` -> `col-span-8` / `col-span-4`).
- **Data Grids (CRM):** Full width container, filtering sidebar on the left (`col-span-3`), massive table on the right (`col-span-9`).
- **Settings:** Two-column standard layout. Navigation on left, detailed forms on right.

---

## 20. Coding Standards

- **React/TSX:** Strict typing for all component props.
- **Tailwind:** Avoid inline styles. Group classes logically (Layout -> Spacing -> Typography -> Color -> Animation).
- **Performance:** Avoid heavy chart libraries (Chart.js, Recharts) if a simple SVG sparkline or HTML progress bar will suffice.

---

## 21. Data Visualization Standards

- **KPI Cards:** Used for top-level, critical singular metrics. Must include the absolute number, a trend indicator, and a micro-label context.
- **Trend Cards:** Used for tracking a single metric over time. Requires an animated sparkline.
- **Dashboard Analytics:** Visualized as continuous pipelines or clean area charts. Avoid complex multi-axis charts.
- **Comparison Widgets:** Use side-by-side pill comparisons or stark typography contrasting Current vs. Previous.
- **Forecast Widgets:** Render projected paths as dashed lines in area charts, using muted primary colors.

---

## 22. Status System

Every module must use identical status badges. Do not reinvent statuses.

- **Active / Paid / Completed:** Emerald (`bg-emerald-50 text-emerald-700 ring-emerald-500/20`).
- **Inactive / Archived / Draft:** Slate (`bg-slate-50 text-slate-700 ring-slate-500/20`).
- **Pending / Trial:** Amber (`bg-amber-50 text-amber-700 ring-amber-500/20`).
- **Expired / Suspended / Overdue / Failed / Cancelled:** Rose (`bg-rose-50 text-rose-700 ring-rose-500/20`).

---

## 23. Badge System

Badges strictly adhere to the Status System colors but define the visual treatment.

- **Soft (Default):** Colored background (10% opacity) with colored text (700 weight).
- **Outline:** Transparent background with colored border and text.
- **Filled:** Solid colored background (500 weight) with white text.
- **Interactive:** Soft badge that transforms to Filled on hover.

---

## 24. Modal System

- **Standard Modal:** Centered on screen, max-width `md`. Used for simple forms or configurations.
- **Confirmation Modal:** Centered, narrow (`sm`). Red action button for destructive acts.
- **Drawer / Side Panel:** Slides from the right. Used for deep data entry (e.g., Creating a new Organization).
- **Animation Rules:** Modals use `animate-in fade-in zoom-in-95 duration-200`. Drawers use `slide-in-from-right duration-300`.

---

## 25. Search Experience

- **Global Search:** Invoked via `⌘+K`. A glassmorphic overlay over the entire application that categorizes results by Orgs, Patients, and Actions.
- **Module Search:** A standard input with a `Search` icon on the left, placed at the top-right of CRM/Data tables.
- **Saved Filters:** Displayed as soft pill buttons above data tables, allowing users to switch views instantly.

---

## 26. Notification System

- **Notification Bell:** Located in the top header. Displays a solid red dot (`w-2 h-2`) when unread alerts exist.
- **Notification Center:** A dropdown panel. Items with unread status have a subtle blue background (`bg-indigo-50/50`).
- **Priority Levels:** System alerts (gray), Action Required (amber), Critical (rose).

---

## 27. Dashboard Widget Standards

Every widget must adhere to these structural rules:
- **Purpose:** Must answer a specific executive question (e.g., "Are we collecting cash efficiently?").
- **Refresh Strategy:** Polled data (inertia intervals) or Websockets for real-time.
- **Empty State:** Must never be blank. Show a soft icon and "No data available."
- **Actions:** View details or configure widget via a top-right context menu.

---

## 28. AI Component Standards

- **AI Recommendations:** Highlighted with a subtle purple gradient (`bg-gradient-to-r from-purple-50 to-indigo-50`).
- **AI Action Buttons:** Accompanied by a spark/magic wand icon.
- **AI Summary Cards:** Prepend generated text with "AI Summary:" and allow user feedback (thumbs up/down).

---

## 29. Enterprise Security UI

- **Role Indicators:** Super Admins display a unique shield icon next to their profile name.
- **Permission Denied:** Clean, muted empty state indicating "Insufficient Privileges" rather than a harsh 403 error page.
- **Audit Trail:** Displayed in a vertical timeline exactly like the "Live Activity" feed, strictly immutable.

---

## 30. Multi-Tenant Rules

- **Organization Context:** The current active Organization name MUST always be visible in the top navigation header.
- **Context Switcher:** A prominent dropdown in the sidebar to switch between Clinic/Branch contexts.
- **Visual Segregation:** If viewing cross-company data, visually group by tenant using distinct header dividers.

---

## 31. Healthcare Standards

- **Patient Cards:** Must immediately prioritize Name, DOB, Age, and Blood Group.
- **Medical Timeline:** Vertical timelines must differentiate clinical events (blue) from administrative events (gray).
- **Vitals & Lab Results:** Ensure ultra-high contrast for out-of-bounds metrics (highlighted in rose).
- **Prescription Layout:** Mimic physical Rx pads for familiarity. Clean, printable structure.

---

## 32. Internationalization

- **Currency:** Must utilize `Intl.NumberFormat`. Currency symbols align based on locale (e.g., ₹ before the number).
- **Date & Time:** Always store in UTC. Display in the user's localized timezone using `date-fns` formatting.

---

## 33. Printing Standards

- **Print Stylesheets:** All enterprise tables and medical records must include `@media print` CSS.
- **Optimization:** Hide sidebars, top navigation, and shadow artifacts. Enforce 100% width and black text on white backgrounds.

---

## 34. Performance Budget

- **Animation Limits:** CSS-only animations for UI states. Framer Motion is reserved strictly for complex route transitions.
- **Lazy Loading:** All charts and heavy widgets below the fold must be dynamically imported.
- **Bundle Size:** Maintain strict vigilance. Do not import heavy libraries like `moment.js` (use `date-fns`).

---

## 35. Testing Standards

- **Component Testing:** All reusable `<SignatureCard>` or `<Badge>` components must be tested for correct prop application.
- **Accessibility Testing:** Automated WCAG checks to ensure contrast ratios on micro-labels remain valid.

---

## 36. Design Review Checklist

Before any PR is merged into production, it must pass:
1. **OEDS Compliance:** Does it look like OralCares, or does it look like generic Tailwind?
2. **Visual Quality:** Squint test applied. Are focal points obvious?
3. **Information Hierarchy:** Are numbers powerful and labels subtle?
4. **Animation:** Are hover states using `duration-300` and tactile scaling?
5. **Component Reuse:** Were OEDS primitives used, or did the developer write custom CSS?
6. **Enterprise Readiness:** Would an investor or hospital owner be impressed?

---

## 37. OEDS GOVERNANCE

> [!CAUTION]
> **OEDS is the official design authority. OEDS has higher priority than individual developer preference.**

No new UI pattern may be introduced without updating OEDS.

Every Pull Request must include:
- ✓ OEDS Components Used
- ✓ New Components Introduced
- ✓ OEDS Sections Referenced

### Submitting New Components
If a developer creates a new component that does not exist inside OEDS, they must strictly follow this procedure:
1. **Propose it:** Outline the necessity of the component.
2. **Document it:** Define its purpose, props, states, and accessibility rules.
3. **Add it to OEDS:** Update this document.
4. **Receive approval:** The Lead Product Designer / Architect must explicitly approve it.

Only then can the component be implemented.

### Non-Negotiable Rules
- **No page may merge with duplicated UI.**
- **No page may bypass reusable components.**
- **Component reuse is mandatory.**

---

## 38. VERSIONING

**Current Version:** OEDS v1.0

### Version Rules
- **Major Version:** Breaking design changes.
- **Minor Version:** New Components introduced.
- **Patch Version:** Bug fixes and accessibility improvements.

### Declaration Requirement
Every page component must explicitly declare the version of OEDS it was built against within its documentation or component header comment:
> **Built using OEDS v1.x**

### Deprecation Policy
Deprecated components remain fully supported for exactly **one major release** before being permanently removed from the system. 

### Changelog
A precise changelog must be maintained for every version bump to track UI evolution over time.
