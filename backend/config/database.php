<?php

declare(strict_types=1);

function database_connection(): PDO
{
	static $connection;

	if ($connection instanceof PDO) {
		return $connection;
	}

	$database = app_config('DB_DATABASE', '');
	$username = app_config('DB_USERNAME', '');

	if ($database === '' || $username === '') {
		throw new RuntimeException('Database configuration is incomplete.');
	}

	$dsn = sprintf(
		'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
		app_config('DB_HOST', '127.0.0.1'),
		app_config('DB_PORT', '3306'),
		$database
	);

	$connection = new PDO($dsn, $username, app_config('DB_PASSWORD', ''), [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);

	return $connection;
}
