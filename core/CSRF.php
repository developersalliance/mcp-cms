<?php
/**
 * CSRF - Simple CSRF token generation and validation.
 */

class CSRF
{
    private static function ensureSession(): void
    {
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

    public static function generateToken(): string
    {
        self::ensureSession();

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;

        return $token;
    }

    public static function getToken(): ?string
    {
        self::ensureSession();

        return $_SESSION['csrf_token'] ?? null;
    }

    public static function validateToken(string $token): bool
    {
        $sessionToken = self::getToken();

        if (!$sessionToken) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    public static function inputField(): string
    {
        $token = self::getToken();
        if (!$token) {
            $token = self::generateToken();
        }
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    public static function verifyOrDie(): void
    {
        $token = $_POST['csrf_token'] ?? '';

        if (!self::validateToken($token)) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
}
