<?php

declare(strict_types=1);

function respond(array $body, int $status = 200): never
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	exit;
}

function response_success(mixed $data = [], string $message = 'Success', int $status = 200): never
{
	respond(['success' => true, 'data' => $data, 'message' => $message], $status);
}

function response_error(string $code, string $message, int $status = 400, array $fields = []): never
{
	respond([
		'success' => false,
		'error' => ['code' => $code, 'message' => $message, 'fields' => $fields],
	], $status);
}

function response_validation_error(array $fields, string $message = 'Validation failed'): never
{
	response_error('VALIDATION_ERROR', $message, 422, $fields);
}
