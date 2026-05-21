<?php
/* Security headers for every admin page. Sent before the doctype so they
 * apply even if a subsequent require fails mid-render. SAMEORIGIN blocks
 * clickjacking; nosniff blocks MIME confusion; same-origin Referer-Policy
 * keeps admin URLs out of analytics on outbound links. */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" x-init="$watch('darkMode', val => localStorage.setItem('darkMode', val))" :class="{ 'dark': darkMode }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Admin'); ?> - CMS</title>

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

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js with plugins -->
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        accent: {
                            50: '#fff5f3',
                            100: '#ffe8e4',
                            200: '#ffd5cd',
                            300: '#ffb5a8',
                            400: '#ff8a75',
                            500: '#f96a4d',
                            600: '#e64d2e',
                            700: '#c13d21',
                            800: '#a0351f',
                            900: '#843220',
                        },
                        surface: {
                            50: '#fafbfc',
                            100: '#f4f6f8',
                            200: '#e9ecef',
                            300: '#dee2e6',
                        },
                        dark: {
                            50: '#3a3f47',
                            100: '#32363d',
                            200: '#2a2e33',
                            300: '#24272c',
                            400: '#1e2126',
                            500: '#1a1d21',
                            600: '#16181c',
                            700: '#121417',
                            800: '#0e0f11',
                            900: '#0a0b0c',
                        }
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    boxShadow: {
                        'soft': '0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04)',
                        'soft-lg': '0 10px 40px -10px rgba(0, 0, 0, 0.1), 0 2px 10px -2px rgba(0, 0, 0, 0.04)',
                        'dark-soft': '0 2px 15px -3px rgba(0, 0, 0, 0.3), 0 10px 20px -2px rgba(0, 0, 0, 0.2)',
                        'dark-soft-lg': '0 10px 40px -10px rgba(0, 0, 0, 0.4), 0 2px 10px -2px rgba(0, 0, 0, 0.3)',
                    },
                    animation: {
                        'slide-down': 'slideDown 0.2s ease-out',
                    }
                }
            }
        }
    </script>

    <style>
        [x-cloak] { display: none !important; }

        /* Custom scrollbar - Light */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f4f6f8;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }

        /* Custom scrollbar - Dark */
        .dark ::-webkit-scrollbar-track {
            background: #1a1d21;
        }
        .dark ::-webkit-scrollbar-thumb {
            background: #3a3f47;
        }
        .dark ::-webkit-scrollbar-thumb:hover {
            background: #4a5058;
        }

        /* Animations */
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Sidebar menu animations */
        .menu-item {
            position: relative;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .menu-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(135deg, #f96a4d, #e64d2e);
            transform: scaleY(0);
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 0 3px 3px 0;
        }
        .menu-item:hover::before,
        .menu-item.active::before {
            transform: scaleY(1);
        }
        .menu-item:hover {
            background: rgba(249, 106, 77, 0.08);
            padding-left: 1.75rem;
        }
        .dark .menu-item:hover {
            background: rgba(249, 106, 77, 0.15);
        }
        .menu-item.active {
            background: rgba(249, 106, 77, 0.1);
        }
        .dark .menu-item.active {
            background: rgba(249, 106, 77, 0.2);
        }

        /* Submenu animations */
        .submenu-item {
            transition: all 0.15s ease;
        }
        .submenu-item:hover {
            padding-left: 3.5rem;
            color: #e64d2e;
        }
        .dark .submenu-item:hover {
            color: #ff8a75;
        }

        /* Card hover effects */
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.12), 0 2px 10px -2px rgba(0, 0, 0, 0.05);
        }
        .dark .card-hover:hover {
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.4), 0 2px 10px -2px rgba(0, 0, 0, 0.3);
        }

        /* Button styles */
        .btn-primary {
            background: linear-gradient(135deg, #f96a4d 0%, #e64d2e 100%);
            transition: all 0.2s ease;
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        .btn-primary:hover::before {
            left: 100%;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(249, 106, 77, 0.4);
        }

        /* Glass effect */
        .glass {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .dark .glass {
            background: rgba(26, 29, 33, 0.8);
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(135deg, #f96a4d 0%, #c13d21 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Focus states */
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #f96a4d;
            box-shadow: 0 0 0 3px rgba(249, 106, 77, 0.15);
        }

        /* Table styles */
        .table-modern tbody tr {
            transition: all 0.15s ease;
        }
        .table-modern tbody tr:hover {
            background: rgba(249, 106, 77, 0.04);
        }
        .dark .table-modern tbody tr:hover {
            background: rgba(249, 106, 77, 0.1);
        }

        /* Status badges */
        .badge {
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        /* Floating label inputs */
        .input-modern {
            border: 1.5px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .input-modern:hover {
            border-color: #d1d5db;
        }
        .input-modern:focus {
            border-color: #f96a4d;
            box-shadow: 0 0 0 3px rgba(249, 106, 77, 0.1);
        }
        .dark .input-modern {
            border-color: #3a3f47;
            background: #24272c;
        }
        .dark .input-modern:hover {
            border-color: #4a5058;
        }

        /* Code blocks */
        code {
            font-family: 'JetBrains Mono', monospace;
        }

        /* Sidebar collapsed state */
        .sidebar-icon {
            transition: all 0.2s ease;
        }
        .menu-item:hover .sidebar-icon {
            transform: scale(1.1);
        }

        /* Dark mode toggle animation */
        .theme-toggle {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .theme-toggle:hover {
            transform: rotate(15deg);
        }

    </style>
</head>
<body class="bg-surface-50 dark:bg-dark-500 font-sans antialiased" x-data="{
    sidebarOpen: true,
    contentOpen: <?php echo in_array($activePage ?? '', ['pages', 'create', 'sync']) ? 'true' : 'false'; ?>,
    settingsOpen: <?php echo ($activePage ?? '') === 'settings' || in_array($_SERVER['PHP_SELF'] ?? '', ['/cms/admin/settings.php', '/cms/admin/backups.php', '/cms/admin/mcp-config.php', '/cms/admin/collections.php', '/cms/admin/collection-templates.php', '/cms/admin/authors.php', '/cms/admin/blog-categories.php', '/cms/admin/ai-settings.php']) ? 'true' : 'false'; ?>,
    collectionsOpen: <?php echo in_array($activePage ?? '', ['posts', 'blog', 'blog-sync']) ? 'true' : 'false'; ?>,
    docsOpen: <?php echo ($activePage ?? '') === 'docs' || in_array($_SERVER['PHP_SELF'] ?? '', ['/cms/admin/docs/blocks.php', '/cms/admin/docs/file-editing.php']) ? 'true' : 'false'; ?>
}">

    <!-- Top Header Bar -->
    <header class="fixed top-0 left-0 right-0 h-16 bg-white dark:bg-dark-400 border-b border-surface-200 dark:border-dark-200 z-50 shadow-soft dark:shadow-dark-soft transition-colors">
        <div class="h-full px-6 flex items-center justify-between">
            <!-- Logo & Toggle -->
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-dark-200 transition-colors">
                    <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <a href="/cms/admin/" class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-accent-500 to-accent-600 flex items-center justify-center shadow-lg shadow-accent-500/25">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-gray-800 dark:text-white tracking-tight">CMS</span>
                </a>
            </div>

            <!-- Right Side -->
            <div class="flex items-center gap-2">
                <!-- Dark Mode Toggle -->
                <button @click="darkMode = !darkMode" class="theme-toggle p-2.5 rounded-xl hover:bg-surface-100 dark:hover:bg-dark-200 transition-colors" title="Toggle theme">
                    <!-- Sun icon (shown in dark mode) -->
                    <svg x-show="darkMode" x-cloak class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <!-- Moon icon (shown in light mode) -->
                    <svg x-show="!darkMode" class="w-5 h-5 text-gray-500 hover:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                    </svg>
                </button>

                <!-- View Site -->
                <a href="/" target="_blank" class="p-2.5 rounded-xl hover:bg-surface-100 dark:hover:bg-dark-200 transition-colors group" title="View Site">
                    <svg class="w-5 h-5 text-gray-500 dark:text-gray-400 group-hover:text-accent-600 dark:group-hover:text-accent-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                    </svg>
                </a>

                <!-- User Menu -->
                <?php if (isset($currentUser)): ?>
                <div x-data="{ userOpen: false }" class="relative ml-2">
                    <button @click="userOpen = !userOpen" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-surface-100 dark:hover:bg-dark-200 transition-all">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent-400 to-accent-600 flex items-center justify-center text-white font-semibold text-sm shadow-sm">
                            <?php echo strtoupper(substr($currentUser['username'], 0, 1)); ?>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300 hidden sm:block"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform" :class="userOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div x-show="userOpen" x-cloak @click.away="userOpen = false"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="absolute right-0 mt-2 w-48 bg-white dark:bg-dark-300 rounded-xl shadow-soft-lg dark:shadow-dark-soft-lg border border-surface-200 dark:border-dark-100 py-2 z-50">
                        <a href="/cms/admin/settings.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-surface-100 dark:hover:bg-dark-200 transition-colors">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            Settings
                        </a>
                        <div class="border-t border-surface-200 dark:border-dark-100 my-2"></div>
                        <form method="post" action="/cms/admin/logout.php" class="m-0">
                            <?php require_once __DIR__ . '/../../core/CSRF.php'; echo CSRF::inputField(); ?>
                            <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors text-left">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                </svg>
                                Sign Out
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="flex pt-16 min-h-screen">
        <!-- Sidebar -->
        <nav class="fixed left-0 top-16 bottom-0 w-64 bg-white dark:bg-dark-400 border-r border-surface-200 dark:border-dark-200 shadow-soft dark:shadow-dark-soft z-40 overflow-hidden"
             x-show="sidebarOpen" x-cloak>
            <div class="h-full overflow-y-auto py-6 px-3">

                <!-- Dashboard -->
                <a href="/cms/admin/" class="menu-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 mb-1 <?php echo ($activePage ?? '') === 'dashboard' ? 'active' : ''; ?>">
                    <svg class="sidebar-icon w-5 h-5 <?php echo ($activePage ?? '') === 'dashboard' ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path>
                    </svg>
                    <span class="font-medium text-sm">Dashboard</span>
                </a>

                <!-- Section Label -->
                <div class="px-4 py-2 mt-4 mb-2">
                    <span class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Content</span>
                </div>

                <!-- Content Menu -->
                <div class="mb-1">
                    <button @click="contentOpen = !contentOpen" class="menu-item w-full flex items-center justify-between px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 <?php echo in_array($activePage ?? '', ['pages', 'create', 'sync']) ? 'active' : ''; ?>">
                        <div class="flex items-center gap-3">
                            <svg class="sidebar-icon w-5 h-5 <?php echo in_array($activePage ?? '', ['pages', 'create', 'sync']) ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="font-medium text-sm">Pages</span>
                        </div>
                        <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" :class="contentOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="contentOpen" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         class="ml-4 mt-1 space-y-1 border-l-2 border-surface-200 dark:border-dark-100">
                        <a href="/cms/admin/pages.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($activePage ?? '') === 'pages' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            All Pages
                        </a>
                        <a href="/cms/admin/create.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($activePage ?? '') === 'create' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            Create Page
                        </a>
                    </div>
                </div>

                <!-- Collections Menu -->
                <?php
                if (!isset($blogManager)) {
                    require_once __DIR__ . '/../../core/BlogManager.php';
                    $blogManager = new BlogManager($config['root_dir'] ?? '', $config['cms_dir'] ?? '');
                }
                $navCollections = $blogManager->getCollections();
                // Configuration items (Manage Collections, Collection Templates,
                // Authors, Categories) live under Settings now. The Collections
                // sidebar entry lists only actual collections so the user can
                // jump straight to "Blog" without wading through admin chrome.
                ?>
                <?php if (!empty($navCollections)): ?>
                <div class="mb-1">
                    <button @click="collectionsOpen = !collectionsOpen" class="menu-item w-full flex items-center justify-between px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 <?php echo in_array($activePage ?? '', ['posts', 'blog', 'blog-sync']) ? 'active' : ''; ?>">
                        <div class="flex items-center gap-3">
                            <svg class="sidebar-icon w-5 h-5 <?php echo in_array($activePage ?? '', ['posts', 'blog', 'blog-sync']) ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                            <span class="font-medium text-sm">Collections</span>
                        </div>
                        <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" :class="collectionsOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="collectionsOpen" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         class="ml-4 mt-1 space-y-1 border-l-2 border-surface-200 dark:border-dark-100">
                        <?php foreach ($navCollections as $navCollection): ?>
                        <a href="/cms/admin/blog.php?collection=<?php echo urlencode($navCollection['id']); ?>" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($activePage ?? '') === 'blog' && ($_GET['collection'] ?? 'blog') === $navCollection['id'] ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            <?php echo htmlspecialchars($navCollection['label']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Section Label -->
                <div class="px-4 py-2 mt-4 mb-2">
                    <span class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Assets</span>
                </div>

                <!-- Media -->
                <a href="/cms/admin/media.php" class="menu-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 mb-1 <?php echo ($activePage ?? '') === 'media' ? 'active' : ''; ?>">
                    <svg class="sidebar-icon w-5 h-5 <?php echo ($activePage ?? '') === 'media' ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span class="font-medium text-sm">Media Library</span>
                </a>

                <!-- File Manager -->
                <a href="/cms/admin/files.php" class="menu-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 mb-1 <?php echo ($activePage ?? '') === 'files' ? 'active' : ''; ?>">
                    <svg class="sidebar-icon w-5 h-5 <?php echo ($activePage ?? '') === 'files' ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                    </svg>
                    <span class="font-medium text-sm">File Manager</span>
                </a>

                <!-- Section Label -->
                <div class="px-4 py-2 mt-4 mb-2">
                    <span class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">System</span>
                </div>

                <!-- Import Pages -->
                <a href="/cms/admin/import.php" class="menu-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 mb-1 <?php echo ($activePage ?? '') === 'import' ? 'active' : ''; ?>">
                    <svg class="sidebar-icon w-5 h-5 <?php echo ($activePage ?? '') === 'import' ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 0115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    <span class="font-medium text-sm">Import Pages</span>
                </a>

                <!-- Docs (submenu) -->
                <?php
                $docsPaths = ['/cms/admin/docs/blocks.php', '/cms/admin/docs/file-editing.php'];
                $docsActive = ($activePage ?? '') === 'docs' || in_array($_SERVER['PHP_SELF'] ?? '', $docsPaths, true);
                ?>
                <div class="mb-1">
                    <button @click="docsOpen = !docsOpen" class="menu-item w-full flex items-center justify-between px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 <?php echo $docsActive ? 'active' : ''; ?>">
                        <div class="flex items-center gap-3">
                            <svg class="sidebar-icon w-5 h-5 <?php echo $docsActive ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                            <span class="font-medium text-sm">Docs</span>
                        </div>
                        <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" :class="docsOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="docsOpen" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         class="ml-4 mt-1 space-y-1 border-l-2 border-surface-200 dark:border-dark-100">
                        <a href="/cms/admin/docs/blocks.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/docs/blocks.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            Blocks
                        </a>
                        <a href="/cms/admin/docs/file-editing.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/docs/file-editing.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            AI File Editing
                        </a>
                    </div>
                </div>

                <!-- Settings Menu -->
                <?php
                $settingsPages = [
                    '/cms/admin/settings.php',
                    '/cms/admin/collections.php',
                    '/cms/admin/collection-templates.php',
                    '/cms/admin/authors.php',
                    '/cms/admin/blog-categories.php',
                    '/cms/admin/backups.php',
                    '/cms/admin/mcp-config.php',
                    '/cms/admin/ai-settings.php',
                ];
                $settingsActivePages = ['settings', 'collections', 'collection-templates'];
                $settingsActive = in_array($activePage ?? '', $settingsActivePages, true)
                    || in_array($_SERVER['PHP_SELF'] ?? '', $settingsPages, true);
                ?>
                <div class="mb-1">
                    <button @click="settingsOpen = !settingsOpen" class="menu-item w-full flex items-center justify-between px-4 py-3 rounded-lg text-gray-700 dark:text-gray-300 <?php echo $settingsActive ? 'active' : ''; ?>">
                        <div class="flex items-center gap-3">
                            <svg class="sidebar-icon w-5 h-5 <?php echo $settingsActive ? 'text-accent-600 dark:text-accent-400' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <span class="font-medium text-sm">Settings</span>
                        </div>
                        <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" :class="settingsOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="settingsOpen" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-2"
                         class="ml-4 mt-1 space-y-1 border-l-2 border-surface-200 dark:border-dark-100">
                        <a href="/cms/admin/settings.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/settings.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            General
                        </a>
                        <a href="/cms/admin/collections.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($activePage ?? '') === 'collections' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            Manage Collections
                        </a>
                        <a href="/cms/admin/collection-templates.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($activePage ?? '') === 'collection-templates' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            Collection Templates
                        </a>
                        <a href="/cms/admin/authors.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/authors.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            Authors
                        </a>
                        <a href="/cms/admin/blog-categories.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/blog-categories.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            Categories
                        </a>
                        <a href="/cms/admin/backups.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/backups.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            Backups
                        </a>
                        <a href="/cms/admin/mcp-config.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/mcp-config.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            MCP Config
                        </a>
                        <a href="/cms/admin/ai-settings.php" class="submenu-item block pl-7 pr-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-accent-600 dark:hover:text-accent-400 rounded-r-lg <?php echo ($_SERVER['PHP_SELF'] ?? '') === '/cms/admin/ai-settings.php' ? 'text-accent-600 dark:text-accent-400 font-medium bg-accent-50 dark:bg-accent-900/20' : ''; ?>">
                            AI Assistant
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="flex-1" :class="sidebarOpen ? 'ml-64' : 'ml-0'">
            <div class="p-8">
                <div class="max-w-6xl mx-auto">
