<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	session_set_cookie_params([
		'httponly' => true,
		'samesite' => 'Lax',
		'secure' => $secure,
	]);
	session_name('health_admin');
	session_start();
}

function isAdmin(): bool
{
	return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

function adminLogin(string $email, string $password): bool
{
	$statement = database_connection()->prepare(
		'SELECT id, email, name, password_hash FROM admin_users WHERE email = :email LIMIT 1'
	);
	$statement->execute(['email' => strtolower(trim($email))]);
	$admin = $statement->fetch();

	if (!$admin || !password_verify($password, $admin['password_hash'])) {
		return false;
	}

	session_regenerate_id(true);
	$_SESSION['admin_id'] = (int) $admin['id'];
	$_SESSION['admin_email'] = $admin['email'];
	$_SESSION['admin_name'] = $admin['name'];

	return true;
}

function adminLogout(): void
{
	$_SESSION = [];

	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
	}

	session_destroy();
}

function requireAdmin(): void
{
	if (!isAdmin()) {
		header('Location: login.php');
		exit;
	}
}