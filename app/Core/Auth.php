<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const SESSION_KEY = 'auth_user_id';
    private ?array $cachedUser = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function attempt(string $email, string $password): bool
    {
        $email = mb_strtolower(trim($email));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $failures = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip_address = ? AND successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            [$email, $ip]
        );
        if ($failures >= 5) {
            return false;
        }

        $user = $this->db->fetch(
            'SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1',
            [$email]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Mantém o custo de verificação semelhante mesmo quando o e-mail não existe.
            if (!$user) {
                password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            }
            $this->db->query('INSERT INTO login_attempts (email, ip_address, successful) VALUES (?, ?, 0)', [$email, $ip]);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $this->cachedUser = $user;
        $this->db->query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        $this->db->query('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?', [$email, $ip]);
        if (random_int(1, 100) === 1) {
            $this->db->query('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        }

        return true;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?array
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $id = (int) ($_SESSION[self::SESSION_KEY] ?? 0);
        if ($id < 1) {
            return null;
        }

        $this->cachedUser = $this->db->fetch(
            'SELECT id, name, email, role, active, last_login_at FROM users WHERE id = ? AND active = 1',
            [$id]
        );

        if (!$this->cachedUser) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        return $this->cachedUser ?: null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        $this->cachedUser = null;
    }

    public function canWrite(): bool
    {
        return in_array($this->user()['role'] ?? '', ['admin', 'manager'], true);
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'admin';
    }
}
