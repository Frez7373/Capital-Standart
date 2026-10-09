<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['timezone']);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('cci_bank_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/bank.php';
require_once __DIR__ . '/views.php';

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $d['host'], $d['port'], $d['name'], $d['charset']);
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    static $cached = false;
    static $user = null;
    if ($cached) return $user;
    $stmt = db()->prepare('SELECT id, first_name, last_name, email, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    $cached = true;
    if (!$user || $user['status'] !== 'active') {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Войдите в аккаунт, чтобы продолжить.');
        redirect('?page=login');
    }
    return $user;
}

function require_banker(): array
{
    $user = require_login();
    if ($user['role'] !== 'banker') {
        http_response_code(403);
        render_error_page(403, 'Недостаточно прав', 'Этот раздел доступен только сотрудникам банка.');
        exit;
    }
    return $user;
}

function audit(string $action, string $details, ?int $actorId = null): void
{
    try {
        $actorId ??= isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $stmt = db()->prepare('INSERT INTO audit_log (actor_user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$actorId, $action, mb_substr($details, 0, 500), $ip ?: null]);
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}
