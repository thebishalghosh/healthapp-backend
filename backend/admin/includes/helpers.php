<?php

declare(strict_types=1);

function e(mixed $value): string
{
	return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function adminDate(mixed $value): string
{
	if (!is_string($value) || $value === '') {
		return '-';
	}

	$timestamp = strtotime($value);
	return $timestamp === false ? e($value) : date('d M Y, H:i', $timestamp);
}

function statusClass(string $status): string
{
	return match ($status) {
		'active' => 'success',
		'cancelled', 'expired' => 'secondary',
		'pending', 'trial' => 'warning',
		'paused' => 'info',
		default => 'light',
	};
}

function currentPage(string $page): bool
{
	return basename($_SERVER['SCRIPT_NAME'] ?? '') === $page;
}