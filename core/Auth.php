<?php
/**
 * Auth - Handles user authentication for the admin panel.
 *
 * Uses PHP sessions and password_verify() for authentication.
 * User data is stored in /cms/config/users.json
 */

class Auth
{
    private string $usersFile;

    public function __construct(string $usersFile)
    {
        $this->usersFile = $usersFile;

        if (session_status() === PHP_SESSION_NONE) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public function login(string $username, string $password): bool
    {
        $users = $this->loadUsers();

        foreach ($users as $user) {
            if ($user['username'] === $username) {
                if (password_verify($password, $user['password_hash'])) {
                    // Prevent session fixation: regenerate session ID before marking authenticated
                    session_regenerate_id(true);
                    $_SESSION['cms_user'] = [
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                    ];
                    return true;
                }
            }
        }

        return false;
    }

    public function logout(): void
    {
        unset($_SESSION['cms_user']);
        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['cms_user']);
    }

    public function getCurrentUser(): ?array
    {
        return $_SESSION['cms_user'] ?? null;
    }

    public function requireAuth(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: /cms/admin/login.php');
            exit;
        }
    }

    private function loadUsers(): array
    {
        if (!file_exists($this->usersFile)) {
            return [];
        }

        $json = file_get_contents($this->usersFile);
        $data = json_decode($json, true);

        return $data['users'] ?? [];
    }

    /**
     * @throws Exception if save fails
     */
    public function saveUsers(array $users): void
    {
        $data = ['users' => $users];
        $json = json_encode($data, JSON_PRETTY_PRINT);

        if (file_put_contents($this->usersFile, $json) === false) {
            throw new Exception("Failed to save users file");
        }
    }

    /**
     * @throws Exception if user already exists
     */
    public function createUser(string $username, string $email, string $password, string $role = 'owner'): void
    {
        $users = $this->loadUsers();

        foreach ($users as $user) {
            if ($user['username'] === $username) {
                throw new Exception("Username already exists");
            }
        }

        $users[] = [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ];

        $this->saveUsers($users);
    }
}
