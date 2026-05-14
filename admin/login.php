<?php
/**
 * Admin Login Page
 */

require_once __DIR__ . '/../core/Auth.php';

$config = is_file(__DIR__ . '/../config/config.php') ? require __DIR__ . '/../config/config.php' : [];
$adminPath = $config['admin_path'] ?? '/cms/admin/';

$auth = new Auth(__DIR__ . '/../config/users.json');

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: ' . $adminPath);
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($auth->login($username, $password)) {
        header('Location: ' . $adminPath);
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - CMS</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
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
                        }
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <style>
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }
        @keyframes float-reverse {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-15px) rotate(-5deg); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 0.5; }
            50% { transform: scale(1); opacity: 0.3; }
            100% { transform: scale(0.8); opacity: 0.5; }
        }
        .float-1 { animation: float 6s ease-in-out infinite; }
        .float-2 { animation: float-reverse 8s ease-in-out infinite; animation-delay: -2s; }
        .float-3 { animation: float 7s ease-in-out infinite; animation-delay: -4s; }
        .fade-in-up { animation: fadeInUp 0.6s ease-out forwards; }
        .fade-in-up-delay-1 { animation: fadeInUp 0.6s ease-out 0.1s forwards; opacity: 0; }
        .fade-in-up-delay-2 { animation: fadeInUp 0.6s ease-out 0.2s forwards; opacity: 0; }
        .pulse-ring { animation: pulse-ring 3s ease-in-out infinite; }

        .btn-login {
            background: linear-gradient(135deg, #f96a4d 0%, #e64d2e 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s ease;
        }
        .btn-login:hover::before {
            left: 100%;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(249, 106, 77, 0.5);
        }
        .btn-login:active {
            transform: translateY(0);
        }

        .input-field {
            transition: all 0.2s ease;
        }
        .input-field:focus {
            border-color: #f96a4d;
            box-shadow: 0 0 0 4px rgba(249, 106, 77, 0.1);
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 via-white to-gray-100 font-sans antialiased flex items-center justify-center p-4 relative overflow-hidden">

    <!-- Decorative Background Elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <!-- Gradient Orbs -->
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-accent-200/30 rounded-full blur-3xl float-1"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 bg-blue-200/30 rounded-full blur-3xl float-2"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-accent-100/20 rounded-full blur-3xl pulse-ring"></div>

        <!-- Grid Pattern -->
        <div class="absolute inset-0" style="background-image: radial-gradient(circle at 1px 1px, rgb(209 213 219 / 0.3) 1px, transparent 0); background-size: 40px 40px;"></div>

        <!-- Floating Shapes -->
        <div class="absolute top-20 left-20 w-16 h-16 border-2 border-accent-200/50 rounded-2xl float-3 rotate-12"></div>
        <div class="absolute bottom-32 right-32 w-12 h-12 bg-accent-100/50 rounded-xl float-1 -rotate-12"></div>
        <div class="absolute top-40 right-40 w-8 h-8 bg-blue-100/50 rounded-lg float-2 rotate-45"></div>
    </div>

    <!-- Login Card -->
    <div class="relative z-10 w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8 fade-in-up">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-accent-500 to-accent-600 shadow-xl shadow-accent-500/30 mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">Welcome back</h1>
            <p class="text-gray-500 mt-1">Sign in to your CMS account</p>
        </div>

        <!-- Card -->
        <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-2xl shadow-gray-200/50 border border-white/50 p-8 fade-in-up-delay-1">
            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-100 rounded-xl flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <p class="text-red-700 text-sm font-medium"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-5">
                <!-- Username -->
                <div>
                    <label for="username" class="block text-sm font-semibold text-gray-700 mb-2">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            required
                            autofocus
                            autocomplete="username"
                            class="input-field w-full pl-12 pr-4 py-3.5 bg-gray-50 border-2 border-gray-100 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none"
                            placeholder="Enter your username"
                        >
                    </div>
                </div>

                <!-- Password -->
                <div>
                    <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            class="input-field w-full pl-12 pr-4 py-3.5 bg-gray-50 border-2 border-gray-100 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:outline-none"
                            placeholder="Enter your password"
                        >
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-login w-full py-3.5 px-6 text-white font-semibold rounded-xl shadow-lg">
                    Sign In
                </button>
            </form>
        </div>

        <!-- Footer -->
        <p class="text-center text-sm text-gray-400 mt-6 fade-in-up-delay-2">
            Secure admin access
        </p>
    </div>

</body>
</html>
