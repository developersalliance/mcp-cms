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

    /**
     * All users, with password hashes stripped — safe to hand to the UI.
     *
     * @return array<int,array{username:string,email:string,role:string}>
     */
    public function listUsers(): array
    {
        return array_map(static function (array $u): array {
            return [
                'username' => $u['username'] ?? '',
                'email' => $u['email'] ?? '',
                'role' => $u['role'] ?? 'viewer',
            ];
        }, $this->loadUsers());
    }

    /**
     * Update an existing user's email, role and/or password. A null/empty
     * password leaves the current hash untouched.
     *
     * @throws Exception if the user does not exist, or the change would remove
     *                   the last owner
     */
    public function updateUser(string $username, ?string $email = null, ?string $role = null, ?string $password = null): void
    {
        $users = $this->loadUsers();
        $found = false;

        foreach ($users as &$user) {
            if ($user['username'] === $username) {
                $found = true;
                if ($email !== null) {
                    $user['email'] = $email;
                }
                if ($role !== null && $role !== '') {
                    // Guard against demoting the only owner.
                    if (($user['role'] ?? '') === 'owner' && $role !== 'owner' && $this->countOwners($users) <= 1) {
                        throw new Exception('Cannot change the role of the last owner.');
                    }
                    $user['role'] = $role;
                }
                if ($password !== null && $password !== '') {
                    $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                break;
            }
        }
        unset($user);

        if (!$found) {
            throw new Exception('User not found.');
        }

        $this->saveUsers($users);
    }

    /**
     * Delete a user by username.
     *
     * @throws Exception if the user does not exist or is the last owner
     */
    public function deleteUser(string $username): void
    {
        $users = $this->loadUsers();
        $target = null;
        foreach ($users as $u) {
            if ($u['username'] === $username) {
                $target = $u;
                break;
            }
        }

        if ($target === null) {
            throw new Exception('User not found.');
        }
        if (($target['role'] ?? '') === 'owner' && $this->countOwners($users) <= 1) {
            throw new Exception('Cannot delete the last owner.');
        }

        $users = array_values(array_filter($users, static fn(array $u): bool => ($u['username'] ?? '') !== $username));
        $this->saveUsers($users);
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function countOwners(array $users): int
    {
        $n = 0;
        foreach ($users as $u) {
            if (($u['role'] ?? '') === 'owner') {
                $n++;
            }
        }
        return $n;
    }
}
