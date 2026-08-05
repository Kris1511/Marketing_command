# 04-UI-UX-DESIGN.md - UI/UX Design Specifications

## Digital Marketing Dashboard - Design System

**Version:** 1.0  
**Last Updated:** August 2026  
**Status:** PRESERVED - No Breaking Changes

---

## 1. Design Philosophy

The Digital Marketing Dashboard follows a **professional, data-focused** design system optimized for efficiency and clarity.

### Core Principles
1. **Information Hierarchy**: Most important data prominent, secondary details subtle
2. **Scanability**: Visual grouping, consistent spacing, clear typography
3. **Accessibility**: WCAG 2.1 AA compliance, keyboard navigation, ARIA labels
4. **Consistency**: Unified component library, predictable patterns
5. **Efficiency**: Minimal clicks to accomplish tasks, quick actions visible
6. **Trust**: Professional appearance, clear UI states, transparent processes

---

## 2. Design Tokens (CSS Variables)

### 2.1 Color Palette

#### Primary Colors
```css
--primary: #2457e6;           /* Main action color - Vibrant Blue */
--primary-dark: #173aa3;      /* Darker shade for hover states */
--primary-soft: #eaf0ff;      /* Light tint for badges/backgrounds */
```

#### Neutral Colors
```css
--bg: #f4f7fb;               /* Page background */
--surface: #ffffff;          /* Card/panel background */
--surface-2: #f8fafc;        /* Subtle background variants */
--text: #14213d;             /* Primary text color */
--muted: #64748b;            /* Secondary/muted text */
--border: #e2e8f0;           /* Border & divider color */
```

#### Semantic Colors
```css
--success: #11875d;          /* Positive/completion state */
--success-soft: #e7f7f0;     /* Light success background */
--warning: #b66a00;          /* Caution/attention state */
--warning-soft: #fff4db;     /* Light warning background */
--danger: #c23a4b;           /* Error/destructive action */
--danger-soft: #fdecef;      /* Light danger background */
--info: #0477a8;             /* Informational state */
--info-soft: #e8f6fb;        /* Light info background */
```

#### Sidebar
```css
--sidebar: #0f1f3d;          /* Dark sidebar background */
--sidebar-2: #172b52;        /* Sidebar hover/active state */
```

#### Shadow
```css
--shadow: 0 12px 30px rgba(15, 31, 61, .08);
```

---

### 2.2 Spacing Scale

```css
4px   - Micro spacing (internal padding)
8px   - Base unit (most spacing)
12px  - Small gap
16px  - Standard gap (buttons, form fields)
20px  - Medium gap
24px  - Large gap (section spacing)
26px  - Content padding (horizontal)
28px  - Topbar/sidebar padding
46px  - Bottom content padding
```

---

### 2.3 Border Radius

```css
8px   - Small radius (form inputs, buttons small)
9px   - Form elements
10px  - Buttons, icon buttons
11px  - Nav buttons
14px  - Modals, large cards
16px  - Large components, hero sections
20px  - Welcome panel
50%   - Circles (avatars)
999px - Pill shapes
```

---

### 2.4 Typography

#### Font Family
```css
font-family: Inter, ui-sans-serif, system-ui, -apple-system, 
             BlinkMacSystemFont, "Segoe UI", sans-serif;
```

#### Font Sizes & Weights
```
Scale:
  11px  - Uppercase labels, pill text
  12px  - Small text, helper text
  13px  - Button text (small), secondary labels
  14px  - Default body, form text
  15px  - Secondary headings, nav labels
  16px  - Form input text
  18px  - Page heading (strong)
  22px  - Panel title / welcome heading
  28px  - (reserved for future)

Weights:
  400   - Regular body text
  500   - Normal weight (form labels)
  600   - Semi-bold (button text)
  650   - Medium-bold (headings)
  700   - Bold (important labels)
  900   - Extra bold (brand mark)
```

#### Line Heights
```css
1.4   - Standard text (1.4 * font-size)
1.45  - Help text, secondary text
1.5   - Body paragraphs
1.6   - Large text
```

---

### 2.5 Motion & Transitions

```css
--quick: .18s ease         /* Button hover, state changes */
--medium: .3s ease         /* Modal open/close, page transitions */
--slow: .6s ease           /* Animations, large transitions */

@keyframes fade {
  from { opacity: 0.55; transform: translateY(3px); }
  to { opacity: 1; transform: none; }
}

/* Applied to view transitions */
animation: fade 0.2s ease;
```

---

## 3. Component Library

### 3.1 Buttons

#### Button States & Classes

```html
<!-- Primary Button -->
<button class="btn btn-primary">Save</button>
<!-- Background: var(--primary), Color: #fff -->
<!-- Hover: var(--primary-dark), translate -1px -->

<!-- Secondary Button -->
<button class="btn btn-secondary">Cancel</button>
<!-- Background: var(--surface), Border: var(--border) -->

<!-- White Button (on blue) -->
<button class="btn btn-white">Create content</button>
<!-- Background: #fff, Color: var(--primary-dark) -->

<!-- Outline White Button (on gradient) -->
<button class="btn btn-outline-white">View report</button>
<!-- Border: rgba(255,255,255,.45), Color: #fff -->

<!-- Danger Button -->
<button class="btn btn-danger">Delete</button>
<!-- Background: var(--danger), Color: #fff -->

<!-- Small Button -->
<button class="btn btn-sm btn-primary">Save</button>
<!-- Padding: 7px 10px, Font-size: 13px -->

<!-- Block Button (full width) -->
<button class="btn btn-block btn-primary">Full Width</button>
```

#### Common Properties
- **Padding**: 10px 14px
- **Border**: 1px solid transparent
- **Border-radius**: 10px
- **Font-weight**: 650
- **Transition**: 0.18s ease
- **Hover**: translateY(-1px), background color change
- **Disabled**: opacity 0.5

---

### 3.2 Navigation

#### Sidebar Navigation

```html
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark">RM</div>  <!-- 42px avatar -->
    <div>
      <strong>Marketing Command</strong>
      <small>Operations Dashboard</small>
    </div>
  </div>
  
  <div class="nav-group-title">Workspace</div>
  <nav class="nav-list">
    <button class="nav-btn active" data-view="overview">
      <span class="nav-icon">⌂</span>Overview
    </button>
  </nav>
</aside>
```

**Sidebar Styling:**
- Width: 264px (fixed)
- Position: sticky (top: 0, height: 100vh)
- Background: Linear gradient (--sidebar to #0b1730)
- Padding: 22px 16px
- Overflow-y: auto
- Text color: rgba(255,255,255,.76)

**Nav Button:**
- Display: flex, align-items: center
- Padding: 11px 12px
- Border-radius: 11px
- Transition: 0.18s ease
- Gap: 11px
- Active state: Background rgba(255,255,255,.13), inset blue shadow

---

### 3.3 Top Bar / Header

```html
<header class="topbar">
  <div class="topbar-left">
    <button class="mobile-menu" id="mobileMenu">☰</button>
    <div class="page-heading">
      <strong id="pageTitle">Overview</strong>
      <small id="pageSubtitle">All important updates</small>
    </div>
  </div>
  <div class="topbar-right">
    <div class="top-control">
      <label for="clientSelector">Current client workspace</label>
      <select id="clientSelector"></select>
    </div>
    <button class="icon-btn">↻</button>
    <button class="icon-btn">◉<span class="notification-dot"></span></button>
    <div class="avatar">DM</div>
  </div>
</header>
```

**Topbar Styling:**
- Height: 76px
- Background: rgba(255,255,255,.92)
- Border-bottom: 1px solid var(--border)
- Position: sticky (top: 0, z-index: 20)
- Backdrop-filter: blur(12px)
- Padding: 0 28px
- Display: flex, justify-content: space-between

**Icon Button:**
- Width/Height: 42px
- Border: 1px solid var(--border)
- Border-radius: 11px
- Background: var(--surface)

**Avatar:**
- Width/Height: 42px
- Border-radius: 50%
- Background: var(--primary-soft)
- Color: var(--primary-dark)
- Font-weight: 700

---

### 3.4 Forms & Inputs

#### Input Fields

```html
<div class="form-field">
  <label class="form-label" for="clientName">Client name</label>
  <input class="input" id="clientName" placeholder="Example: ABC Retail">
</div>
```

**Input Styling:**
- Border: 1px solid var(--border)
- Background: var(--surface)
- Border-radius: 10px
- Padding: 8px 10px
- Color: var(--text)
- Font: inherit (14px)
- Width: 100%

**Form Label:**
- Display: block
- Font-size: 14px
- Font-weight: 500
- Color: var(--text)
- Margin-bottom: 6px

**Form Grid:**
```css
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.form-field.full {
  grid-column: 1 / -1;
}
```

#### Checkboxes (Card Style)

```html
<div class="check-grid">
  <label class="check-card">
    <input type="checkbox" value="instagram"> Instagram
  </label>
  <label class="check-card">
    <input type="checkbox" value="facebook"> Facebook
  </label>
</div>
```

**Check Grid:**
- Display: grid
- Grid-template-columns: repeat(3, 1fr)
- Gap: 12px

**Check Card:**
- Display: flex, align-items: center, gap: 8px
- Padding: 12px
- Border: 1px solid var(--border)
- Border-radius: 10px
- Cursor: pointer
- Transition: 0.18s ease
- Hover: background var(--primary-soft), border var(--primary)

---

### 3.5 Panels (Cards)

#### Basic Panel

```html
<section class="panel">
  <div class="panel-header">
    <div class="panel-title">
      <h3>Performance trend</h3>
      <p>Reach and engagement for the period</p>
    </div>
    <div class="panel-actions">
      <select class="select">
        <option>Last 30 days</option>
      </select>
    </div>
  </div>
  <div class="chart-wrap">
    <canvas id="performanceChart"></canvas>
  </div>
</section>
```

**Panel Styling:**
- Background: var(--surface)
- Border: 1px solid var(--border)
- Border-radius: 16px
- Padding: 20px 24px
- Box-shadow: var(--shadow)

**Panel Header:**
- Display: flex
- Justify-content: space-between
- Margin-bottom: 16px

**Panel Title:**
- Margin: 0
- h3: font-size 18px, color var(--text)
- p: font-size 14px, color var(--muted), margin-top 6px

---

### 3.6 Modals

```html
<div class="modal-backdrop" id="leadModal" role="dialog" aria-modal="true">
  <div class="modal">
    <div class="modal-head">
      <h3>Add lead manually</h3>
      <button class="modal-close">×</button>
    </div>
    <div class="modal-body">
      <!-- Form content -->
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary">Cancel</button>
      <button class="btn btn-primary">Save lead</button>
    </div>
  </div>
</div>
```

**Modal Backdrop:**
- Position: fixed (100% width, height)
- Background: rgba(15, 31, 61, .5)
- Display: grid, place-items: center
- Z-index: 1000
- Hidden initially (display: none)
- Show with .active class

**Modal:**
- Background: var(--surface)
- Border-radius: 16px
- Max-width: 520px
- Width: 95vw
- Box-shadow: var(--shadow)

**Modal Head:**
- Border-bottom: 1px solid var(--border)
- Padding: 20px 24px
- Display: flex, justify-content: space-between, align-items: center
- h3: font-size 20px, margin 0

**Modal Close Button:**
- Background: transparent
- Border: none
- Font-size: 28px
- Color: var(--text)
- Cursor: pointer
- Padding: 0

**Modal Body:**
- Padding: 20px 24px

**Modal Foot:**
- Padding: 16px 24px
- Border-top: 1px solid var(--border)
- Display: flex, justify-content: flex-end
- Gap: 10px

---

### 3.7 Tables

```html
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Team member</th>
        <th>Role</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>John Doe</td>
        <td>Manager</td>
        <td><span class="pill success">Active</span></td>
      </tr>
    </tbody>
  </table>
</div>
```

**Table Styling:**
- Width: 100%
- Border-collapse: collapse
- Font-size: 14px

**Table Header (th):**
- Text-align: left
- Padding: 12px 16px
- Border-bottom: 2px solid var(--border)
- Font-weight: 600
- Color: var(--text)
- Background: var(--surface-2)

**Table Cell (td):**
- Padding: 12px 16px
- Border-bottom: 1px solid var(--border)
- Color: var(--text)

**Table Hover:**
```css
tbody tr:hover {
  background-color: rgba(36, 87, 230, 0.03);
}
```

---

### 3.8 Pills / Badges

```html
<span class="pill success">Active</span>
<span class="pill warning">Pending</span>
<span class="pill danger">Failed</span>
<span class="pill info">Info</span>
```

**Pill Styling:**
- Padding: 6px 12px
- Border-radius: 8px
- Font-size: 12px
- Font-weight: 600
- Display: inline-block
- Background: [color]-soft, Color: [color]

**Variants:**
- `.pill.success`: background var(--success-soft), color var(--success)
- `.pill.warning`: background var(--warning-soft), color var(--warning)
- `.pill.danger`: background var(--danger-soft), color var(--danger)
- `.pill.info`: background var(--info-soft), color var(--info)

---

### 3.9 Metric Cards

```html
<div class="metric-card">
  <div class="metric-label">Total Reach</div>
  <div class="metric-value">125,450</div>
  <div class="metric-change up">↑ 12% from last period</div>
</div>
```

**Metric Card Styling:**
- Background: var(--surface)
- Border: 1px solid var(--border)
- Border-radius: 16px
- Padding: 18px 20px
- Box-shadow: var(--shadow)

**Metric Label:**
- Font-size: 12px
- Color: var(--muted)
- Text-transform: uppercase
- Letter-spacing: 0.5px
- Margin-bottom: 8px

**Metric Value:**
- Font-size: 28px
- Font-weight: 700
- Color: var(--text)
- Margin-bottom: 8px

**Metric Change:**
- Font-size: 12px
- Color: var(--success)
- Opacity: 0.8
- `.down`: Color var(--danger)

---

### 3.10 Charts

#### Chart Container

```html
<div class="chart-wrap">
  <canvas id="performanceChart" aria-label="Performance trend"></canvas>
</div>
```

**Chart Wrap Styling:**
- Position: relative
- Height: 300px (default)
- Margin-top: 16px

#### Chart Legend

```html
<div class="chart-legend">
  <span class="legend-item">
    <span class="legend-line"></span>Reach
  </span>
  <span class="legend-item">
    <span class="legend-line secondary"></span>Engagement
  </span>
</div>
```

**Legend Line:**
- Width: 12px, Height: 2px
- Background: var(--primary)
- Border-radius: 1px
- `.secondary`: Background var(--info)

---

### 3.11 Toast Notifications

```html
<div class="toast-wrap" id="toastWrap" aria-live="polite">
  <div class="toast success">
    <strong>Success!</strong>
    <p>Your lead has been saved.</p>
  </div>
</div>
```

**Toast Wrap:**
- Position: fixed (bottom-right, 16px 16px)
- Display: flex, flex-direction: column
- Gap: 8px
- Z-index: 2000
- Max-width: 380px

**Toast:**
- Background: var(--success)
- Color: #fff
- Padding: 12px 16px
- Border-radius: 12px
- Box-shadow: var(--shadow)
- Animation: slideIn 0.3s ease
- Auto-dismiss after 4 seconds

**Toast Variants:**
- `.toast.success`: Background var(--success)
- `.toast.error`: Background var(--danger)
- `.toast.warning`: Background var(--warning)
- `.toast.info`: Background var(--info)

---

### 3.12 Lists & Steps

#### Activity List

```html
<div class="activity-list">
  <div class="activity-item">
    <div class="activity-icon">1</div>
    <div class="activity-text">
      <strong>Use backend OAuth callbacks</strong>
      <span>Never store secrets in the browser.</span>
    </div>
  </div>
</div>
```

**Activity Icon:**
- Width/Height: 36px
- Border-radius: 50%
- Background: var(--primary-soft)
- Color: var(--primary)
- Font-weight: 700
- Display: flex, justify-content: center, align-items: center

**Activity Text strong:**
- Display: block
- Font-size: 14px
- Font-weight: 600
- Color: var(--text)
- Margin-bottom: 2px

**Activity Text span:**
- Font-size: 13px
- Color: var(--muted)

#### Step List

```html
<div class="step-list">
  <div class="step">
    <div>
      <strong>Select a client first</strong>
      <p>The top selector changes all data to that workspace.</p>
    </div>
  </div>
</div>
```

**Step Styling:**
- Padding: 16px 20px
- Border-bottom: 1px solid var(--border)
- Last child: no border

---

## 4. Responsive Design

### 4.1 Breakpoints

```css
/* Desktop (default) */
@media (max-width: 1440px) {
  /* Wide desktop */
}

@media (max-width: 1280px) {
  /* Desktop */
}

@media (max-width: 960px) {
  /* Tablet */
  .grid-2 { grid-template-columns: 1fr; }
  .grid-3 { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
  /* Mobile */
  .app-shell { grid-template-columns: 1fr; }
  .sidebar { display: none; }  /* Toggle with mobile menu */
  .topbar { padding: 0 16px; }
  .content { padding: 16px; }
  .grid-2, .grid-3 { grid-template-columns: 1fr; }
}
```

### 4.2 Mobile Menu

```html
<button class="mobile-menu" id="mobileMenu">☰</button>
```

**Mobile Menu Behavior:**
- Display: none on desktop
- Display: block on mobile
- Click toggles sidebar visibility
- Sidebar slides in from left (z-index > 30)

---

## 5. Accessibility (A11y)

### 5.1 ARIA Labels

```html
<!-- Buttons with icons -->
<button aria-label="Sync connected platforms">↻</button>
<button aria-label="Open notifications">◉</button>

<!-- Form inputs -->
<input aria-label="Search leads" placeholder="Name, email, phone...">

<!-- Lists and regions -->
<nav aria-label="Main navigation">
  <!-- Nav items -->
</nav>

<!-- Modals -->
<div role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <h3 id="modalTitle">Add lead manually</h3>
</div>
```

### 5.2 Keyboard Navigation

- **Tab**: Navigate through form fields, buttons, interactive elements
- **Shift+Tab**: Navigate backwards
- **Enter**: Submit forms, activate buttons
- **Space**: Toggle checkboxes, radios
- **Escape**: Close modals, dismiss overlays
- **Arrow keys**: Navigate select options, carousel slides

### 5.3 Color Contrast

- Text on background: 4.5:1 minimum (WCAG AA)
- Large text: 3:1 minimum
- Graphical elements: 3:1 minimum
- Current implementation meets or exceeds these ratios

### 5.4 Semantic HTML

- Use `<button>` for actions (not `<div>` styled as button)
- Use `<table>` for tabular data (not divs)
- Use `<nav>` for navigation
- Use `<main>` for main content
- Use proper heading hierarchy (h1, h2, h3...)

---

## 6. Layout Patterns

### 6.1 Grid Layouts

```html
<!-- Two-column grid -->
<div class="grid-2">
  <section class="panel">...</section>
  <section class="panel">...</section>
</div>

<!-- Three-column grid -->
<div class="grid-3">
  <div class="panel">...</div>
  <div class="panel">...</div>
  <div class="panel">...</div>
</div>

<!-- Equal-width grid (adaptive) -->
<div class="grid-equal">
  <section>...</section>
  <section>...</section>
</div>
```

**Grid Styling:**
```css
.grid-2 {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
  margin-top: 20px;
}

.grid-3 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
```

### 6.2 Welcome Panel

```html
<div class="welcome-panel">
  <div class="welcome-copy">
    <h2>Welcome to Marketing Command</h2>
    <p>Start by adding a client workspace to connect channels.</p>
  </div>
  <div class="welcome-actions">
    <button class="btn btn-outline-white">View reports</button>
    <button class="btn btn-white">Create content</button>
  </div>
</div>
```

**Welcome Panel Styling:**
- Background: Linear gradient (120deg, #143a9b, #2457e6 60%, #4e7cf2)
- Color: #fff
- Padding: 22px 24px
- Border-radius: 20px
- Display: flex, justify-content: space-between
- Overflow: hidden, position: relative
- Pseudo-element: Decorative circle (right -70px, top -110px)

---

## 7. Dark Mode (Future Consideration)

Currently not implemented, but design tokens are prepared for easy dark mode:

```css
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #0d1117;
    --surface: #161b22;
    --text: #e6edf3;
    --muted: #8b949e;
    /* ... adjust all colors ... */
  }
}
```

---

## 8. Animation Guidelines

### 8.1 Transition Timing

```css
/* Quick interactions (hover, focus) */
transition: 0.18s ease;

/* Page transitions (view changes) */
animation: fade 0.2s ease;

/* Larger animations (modal open, slide-in) */
transition: 0.3s ease;
```

### 8.2 Motion Principles

- **Purpose**: Every animation communicates state change
- **Subtle**: Avoid distracting animations
- **Fast**: Complete within 300ms for most interactions
- **Accessible**: Respect `prefers-reduced-motion`

```css
@media (prefers-reduced-motion: reduce) {
  * {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
```

---

## 9. Preserved Assets & Modifications

### 9.1 CSS Structure
- **Primary file**: `resources/css/app.css` (compiled from `assets/styles.css`)
- **Approach**: No breaking changes to existing selectors
- **Vite Integration**: Automatically compiles via `npm run build`

### 9.2 JavaScript Classes
- **Form elements**: Use `.input`, `.select`, `.textarea` (preserved)
- **Buttons**: Use `.btn` classes (preserved)
- **Layout**: Use `.grid-2`, `.grid-3` (preserved)
- **Panels**: Use `.panel` with `.panel-header`, `.panel-body` (preserved)

### 9.3 HTML Structure
- **Blade views** will replicate existing DOM structure
- **No CSS framework** (Tailwind) unless explicitly needed
- **Vanilla CSS** maintained from original design

---

## 10. Component Migration Guide

### Converting to Blade Components

**Old Static HTML:**
```html
<!-- index.html -->
<div class="metric-card">
  <div class="metric-label">Total Reach</div>
  <div class="metric-value">125,450</div>
</div>
```

**New Blade Component:**
```blade
<!-- resources/views/components/metric-card.blade.php -->
<div class="metric-card">
  <div class="metric-label">{{ $label }}</div>
  <div class="metric-value">{{ $value }}</div>
  @if($change)
    <div class="metric-change {{ $change > 0 ? 'up' : 'down' }}">
      {{ $change > 0 ? '↑' : '↓' }} {{ abs($change) }}%
    </div>
  @endif
</div>
```

**Usage in Blade:**
```blade
<x-metric-card label="Total Reach" value="125,450" :change="12" />
```

---

## Sign-Off

**Design Lead:** [Name]  
**Frontend Dev:** [Name]  
**Date:** August 2026  
**Status:** Approved - No Breaking Changes

