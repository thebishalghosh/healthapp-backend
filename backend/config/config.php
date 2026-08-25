<?php

declare(strict_types=1);

function load_environment(string $path): void
{
	if (!is_file($path)) {
		return;
	}

	foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
		$line = trim($line);

		if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
			continue;
		}

		[$name, $value] = explode('=', $line, 2);
		$name = trim($name);
		$value = trim($value);

		if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
			$value = substr($value, 1, -1);
		}

		if (getenv($name) === false) {
			putenv($name . '=' . $value);
			$_ENV[$name] = $value;
		}
	}
}

load_environment(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

function app_config(string $key, mixed $default = null): mixed
{
	$value = getenv($key);

	return $value === false ? $default : $value;
}

function app_debug(): bool
{
	return filter_var(app_config('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
}
