                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="border-t border-surface-200 dark:border-dark-200 bg-white dark:bg-dark-400 py-4 px-8" :class="sidebarOpen ? 'ml-64' : 'ml-0'">
        <div class="max-w-6xl mx-auto flex items-center justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                <?php
                require_once __DIR__ . '/../../core/Version.php';
                $version = new Version($config['root_dir']);
                $versionString = $version->getVersionString();
                ?>
                <span class="font-medium text-gray-700 dark:text-gray-300">CMS</span>
                <span class="mx-2 text-gray-300 dark:text-gray-600">|</span>
                <code class="text-xs bg-surface-100 dark:bg-dark-300 px-2 py-1 rounded-md font-mono text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($versionString); ?></code>
            </p>
            <p class="text-xs text-gray-400 dark:text-gray-500">&copy; <?php echo date('Y'); ?></p>
        </div>
    </footer>

    <!-- Toast Notifications Container -->
    <div id="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>

    <!-- Global Scripts -->
    <script>
        // Toast notification system
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');

            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                warning: 'bg-amber-500',
                info: 'bg-accent-500'
            };

            const icons = {
                success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>',
                error: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>',
                warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>',
                info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>'
            };

            toast.className = `${colors[type]} text-white px-4 py-3 rounded-xl shadow-lg flex items-center gap-3 transform translate-x-full transition-transform duration-300`;
            toast.innerHTML = `
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    ${icons[type]}
                </svg>
                <span class="text-sm font-medium">${message}</span>
            `;

            container.appendChild(toast);

            // Animate in
            requestAnimationFrame(() => {
                toast.classList.remove('translate-x-full');
            });

            // Remove after 4 seconds
            setTimeout(() => {
                toast.classList.add('translate-x-full');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Confirm dialog
        function confirmAction(message) {
            return confirm(message);
        }
    </script>
</body>
</html>
