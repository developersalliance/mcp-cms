# Admin Panel Design System

A modern, clean design system for admin panels built with Tailwind CSS and Alpine.js. Features a light/dark mode toggle, coral accent colors, and smooth animations.

## Quick Start Prompt

Use this prompt to apply the same design to other projects:

```
Apply a modern admin panel design with these specifications:

**Typography:**
- Primary font: Plus Jakarta Sans (Google Fonts)
- Monospace: JetBrains Mono for code elements
- Font weights: 300-700

**Color Palette:**
Light Mode:
- Background: #f8fafc (gray-50)
- Surface: white with subtle borders (#e2e8f0)
- Text: gray-900, gray-500, gray-400

Dark Mode (slightly dark, not pure black):
- Background: #1a1d21 (dark-500)
- Surface cards: #1e2126 (dark-400)
- Borders: #2a2e33 (dark-200)
- Text: white, gray-400, gray-500

Accent Colors (coral/salmon):
- Primary: #f96a4d
- Hover: #e64d2e
- Light: #fff5f3

**Components:**
- Cards: rounded-2xl, shadow-soft, border
- Buttons: gradient background, rounded-xl, shine effect on hover
- Inputs: rounded-xl, focus ring in accent color
- Badges: rounded-full, small text

**Animations:**
- fadeIn: opacity + translateY for list items
- slideIn: sidebar entrance from left
- Menu hover: accent bar slides in from left
- Button shine: sweeping gradient on hover
- Card hover: subtle lift with shadow

**Features:**
- Collapsible sidebar (64rem width)
- Dark mode toggle with localStorage persistence
- Toast notifications
- Smooth transitions (300ms duration)

**Tech Stack:**
- Tailwind CSS via CDN with custom config
- Alpine.js for interactivity
- Class-based dark mode
```

---

## Full Technical Specification

### Fonts

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
```

### Tailwind Configuration

```javascript
tailwind.config = {
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                // Coral/salmon accent
                accent: {
                    50: '#fff5f3',
                    100: '#ffe8e4',
                    200: '#ffd5cd',
                    300: '#ffb5a8',
                    400: '#ff8a75',
                    500: '#f96a4d',  // Primary
                    600: '#e64d2e',  // Hover
                    700: '#c13d21',
                    800: '#a0351f',
                    900: '#843220',
                },
                // Neutral surface colors
                surface: {
                    50: '#f8fafc',
                    100: '#f1f5f9',
                    200: '#e2e8f0',
                    300: '#cbd5e1',
                    400: '#94a3b8',
                    500: '#64748b',
                },
                // Dark mode colors (slightly dark, not pure black)
                dark: {
                    50: '#3a3f47',
                    100: '#32363d',
                    200: '#2a2e33',  // Borders
                    300: '#24272c',  // Hover states
                    400: '#1e2126',  // Cards/surfaces
                    500: '#1a1d21',  // Main background
                    600: '#16181c',
                    700: '#121417',
                    800: '#0e0f11',
                    900: '#0a0b0c',
                },
            },
            fontFamily: {
                sans: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'],
                mono: ['JetBrains Mono', 'monospace'],
            },
            boxShadow: {
                'soft': '0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04)',
                'soft-lg': '0 10px 40px -10px rgba(0, 0, 0, 0.1), 0 2px 10px -2px rgba(0, 0, 0, 0.04)',
            },
        }
    }
}
```

### CSS Animations

```css
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideIn {
    from { opacity: 0; transform: translateX(-10px); }
    to { opacity: 1; transform: translateX(0); }
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fade-in {
    animation: fadeIn 0.3s ease-out forwards;
}

.animate-slide-in {
    animation: slideIn 0.3s ease-out forwards;
}

/* Staggered list animation */
.list-item {
    animation: fadeIn 0.3s ease-out both;
    animation-delay: calc(var(--index) * 0.05s);
}
```

### Button Styles

```css
/* Primary button with gradient and shine effect */
.btn-primary {
    background: linear-gradient(135deg, #f96a4d 0%, #e64d2e 100%);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.btn-primary::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.5s ease;
}

.btn-primary:hover::before {
    left: 100%;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 20px -10px rgba(249, 106, 77, 0.4);
}
```

### Menu Item Hover Effect

```css
/* Menu item with sliding accent bar */
.menu-item {
    position: relative;
    transition: all 0.2s ease;
}

.menu-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%) scaleY(0);
    width: 3px;
    height: 60%;
    background: linear-gradient(180deg, #f96a4d, #e64d2e);
    border-radius: 0 4px 4px 0;
    transition: transform 0.2s ease;
}

.menu-item:hover::before,
.menu-item.active::before {
    transform: translateY(-50%) scaleY(1);
}
```

### Card Hover Effect

```css
.card-hover {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 40px -10px rgba(0, 0, 0, 0.1);
}
```

### Dark Mode Implementation

**IMPORTANT: Prevent Flash of White Background**

Add this script and style in the `<head>` BEFORE any other scripts to prevent the white flash on page load:

```html
<head>
    <!-- Prevent dark mode flash - must run before anything renders -->
    <script>
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        /* Prevent flash of white background */
        html.dark { background-color: #1a1d21; }
        html:not(.dark) { background-color: #fafbfc; }
    </style>

    <!-- Rest of your head content... -->
</head>
```

Then on the `<html>` element for Alpine.js state management:

```html
<html lang="en"
    x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }"
    x-init="$watch('darkMode', val => localStorage.setItem('darkMode', val))"
    :class="{ 'dark': darkMode }">
```

```html
<!-- Toggle button -->
<button @click="darkMode = !darkMode"
    class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-dark-300 transition-colors">
    <!-- Sun icon (shown in dark mode) -->
    <svg x-show="darkMode" class="w-5 h-5 text-amber-400">...</svg>
    <!-- Moon icon (shown in light mode) -->
    <svg x-show="!darkMode" class="w-5 h-5 text-gray-500">...</svg>
</button>
```

### Component Patterns

**Card:**
```html
<div class="bg-white dark:bg-dark-400 rounded-2xl shadow-soft border border-surface-200 dark:border-dark-200 p-6">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Title</h3>
    <p class="text-gray-500 dark:text-gray-400">Description</p>
</div>
```

**Badge:**
```html
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
    Published
</span>
```

**Input:**
```html
<input type="text"
    class="w-full px-4 py-3 bg-surface-50 dark:bg-dark-300 border-2 border-surface-200 dark:border-dark-200 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-accent-500 focus:ring-4 focus:ring-accent-500/10 transition-all">
```

**Collapsible Sidebar:**
```html
<aside x-data="{ sidebarOpen: true }"
    class="fixed left-0 top-0 h-screen bg-white dark:bg-dark-400 border-r border-surface-200 dark:border-dark-200 transition-all duration-300"
    :class="sidebarOpen ? 'w-64' : 'w-0 -translate-x-full'">
    <!-- Sidebar content -->
</aside>
```

### Toast Notifications

```javascript
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');

    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-amber-500',
        info: 'bg-accent-500'
    };

    toast.className = `${colors[type]} text-white px-4 py-3 rounded-xl shadow-lg flex items-center gap-3 transform translate-x-full transition-transform duration-300`;
    toast.innerHTML = `<span class="text-sm font-medium">${message}</span>`;

    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.remove('translate-x-full'));

    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}
```

### Key Design Principles

1. **Rounded corners everywhere** - Use `rounded-xl` or `rounded-2xl` for a soft, modern feel
2. **Subtle shadows** - Use custom `shadow-soft` instead of harsh drop shadows
3. **Smooth transitions** - 300ms duration with ease-out or cubic-bezier timing
4. **Generous spacing** - Use `gap-4`, `p-6`, `mb-8` for breathing room
5. **Hover feedback** - Always provide visual feedback on interactive elements
6. **Color consistency** - Use the accent color sparingly for primary actions
7. **Dark mode contrast** - Ensure sufficient contrast ratios in both modes
8. **Staggered animations** - Use delays for list items to create flow

### Required Dependencies

```html
<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
```
