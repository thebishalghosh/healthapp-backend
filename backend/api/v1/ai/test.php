<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/GeminiService.php';
require_once dirname(__DIR__, 3) . '/services/EntitlementService.php';

$diagnosticUser = authenticated_user();
EntitlementService::requireFeature(database_connection(), (int) $diagnosticUser['id'], 'ai');

function diagnostic_escape(mixed $value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function diagnostic_redact(string $value): string
{
	$value = preg_replace('/(GEMINI_API_KEY|API_KEY|PASSWORD|SECRET|TOKEN)\s*[:=]\s*["\']?[^\s,"\']+/i', '$1=<redacted>', $value) ?? $value;
	$value = preg_replace('/(X-Goog-Api-Key|Authorization|Cookie)\s*:\s*[^\r\n]+/i', '$1: <redacted>', $value) ?? $value;
	$value = preg_replace('/(Bearer\s+)[^\s]+/i', '$1<redacted>', $value) ?? $value;

	return $value;
}

function diagnostic_json(mixed $value): string
{
	$json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	return diagnostic_redact($json === false ? (string) $value : $json);
}

function diagnostic_schema(): array
{
	return [
		'type' => 'ARRAY',
		'items' => [
			'type' => 'OBJECT',
			'properties' => [
				'meal_type' => ['type' => 'STRING', 'enum' => ['breakfast', 'lunch', 'snack', 'dinner']],
				'title' => ['type' => 'STRING'],
				'description' => ['type' => 'STRING'],
				'foods' => [
					'type' => 'ARRAY',
					'items' => [
						'type' => 'OBJECT',
						'properties' => [
							'name' => ['type' => 'STRING'],
							'quantity' => ['type' => 'STRING'],
						],
						'required' => ['name', 'quantity'],
					],
				],
				'estimated_nutrition' => [
					'type' => 'OBJECT',
					'properties' => array_fill_keys(
						['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'],
						['type' => 'NUMBER']
					),
					'required' => ['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'],
				],
				'reason' => ['type' => 'STRING'],
			],
			'required' => ['meal_type', 'title', 'description', 'foods', 'estimated_nutrition', 'reason'],
		],
	];
}

function diagnostic_context(): array
{
	return [
		'profile' => [
			'age' => 30,
			'gender' => 'unspecified',
			'height_cm' => 170.0,
			'weight_kg' => 70.0,
			'activity_level' => 'moderate',
			'fitness_goal' => 'maintenance',
		],
		'nutrition_targets' => [
			'calories' => 2000.0,
			'protein_g' => 100.0,
			'carbohydrates_g' => 250.0,
			'fat_g' => 70.0,
			'fiber_g' => 30.0,
			'water_ml' => 2500.0,
		],
		'today_consumed' => [
			'calories' => 0.0,
			'protein_g' => 0.0,
			'carbohydrates_g' => 0.0,
			'fat_g' => 0.0,
			'fiber_g' => 0.0,
		],
		'today_remaining' => [
			'calories' => 2000.0,
			'protein_g' => 100.0,
			'carbohydrates_g' => 250.0,
			'fat_g' => 70.0,
			'fiber_g' => 30.0,
		],
		'logged_meals' => [],
	];
}

function diagnostic_prompt(array $context): string
{
	$builder = new ReflectionMethod(GeminiService::class, 'buildPrompt');
	$builder->setAccessible(true);

	return (string) $builder->invoke(null, $context, null);
}

function diagnostic_logs(): array
{
	$root = dirname(__DIR__, 3);
	$paths = [
		$root . '/logs/*',
		'C:/laragon/bin/apache/*/logs/error.log',
		'C:/laragon/bin/php/*/php_error_log',
		'C:/laragon/bin/php/*/logs/php_error_log',
	];
	$files = [];
	foreach ($paths as $pattern) {
		foreach (glob($pattern) ?: [] as $path) {
			if (is_file($path) && is_readable($path)) {
				$files[$path] = true;
			}
		}
	}

	$matches = [];
	$keywords = '/food-recommendations|GEMINI_UNAVAILABLE|Gemini|HTTP\s+(429|503)|RESOURCE_EXHAUSTED|exception|error/i';
	foreach (array_keys($files) as $path) {
		$lines = file($path, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			continue;
		}
		foreach (array_slice($lines, -2000) as $line) {
			if (preg_match($keywords, $line) === 1) {
				$matches[] = [
					'file' => $path,
					'modified_at' => date(DATE_ATOM, (int) filemtime($path)),
					'line' => diagnostic_redact($line),
				];
			}
		}
	}

	return array_slice($matches, -100);
}

function diagnostic_request(): array
{
	$apiKey = trim((string) app_config('GEMINI_API_KEY', ''));
	$model = trim((string) app_config('GEMINI_MODEL', ''));
	$apiVersion = trim((string) app_config('GEMINI_API_VERSION', ''));
	$timeout = filter_var(app_config('GEMINI_TIMEOUT_SECONDS', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
	if ($apiKey === '' || $model === '' || $apiVersion === '' || $timeout === false) {
		throw new RuntimeException('Gemini configuration is incomplete or invalid.');
	}

	$prompt = diagnostic_prompt(diagnostic_context());
	$payload = json_encode([
		'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
		'generationConfig' => [
			'temperature' => 0.4,
			'responseMimeType' => 'application/json',
			'responseSchema' => diagnostic_schema(),
		],
	], JSON_THROW_ON_ERROR);
	$url = 'https://generativelanguage.googleapis.com/' . rawurlencode($apiVersion) . '/models/' . rawurlencode($model) . ':generateContent';
	$curl = curl_init($url);
	if ($curl === false) {
		throw new RuntimeException('Gemini diagnostic request could not be initialized.');
	}

	curl_setopt_array($curl, [
		CURLOPT_POST => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $apiKey],
		CURLOPT_POSTFIELDS => $payload,
		CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
		CURLOPT_TIMEOUT => $timeout,
	]);
	$raw = curl_exec($curl);
	$curlError = curl_error($curl);
	$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
	$headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
	curl_close($curl);
	if ($raw === false || $curlError !== '') {
		throw new RuntimeException('Gemini diagnostic request failed: ' . diagnostic_redact($curlError));
	}

	$headers = diagnostic_redact(substr($raw, 0, $headerSize));
	$body = diagnostic_redact(substr($raw, $headerSize));
	$decoded = json_decode($body, true);
	$error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
	$candidate = is_array($decoded) && is_array($decoded['candidates'][0] ?? null) ? $decoded['candidates'][0] : [];
	$usage = is_array($decoded) && is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [];

	return [
		'status' => $status,
		'headers' => $headers,
		'body' => $body,
		'finish_reason' => $candidate['finishReason'] ?? null,
		'model_version' => $decoded['modelVersion'] ?? null,
		'prompt_tokens' => $usage['promptTokenCount'] ?? null,
		'candidate_tokens' => $usage['candidatesTokenCount'] ?? null,
		'total_tokens' => $usage['totalTokenCount'] ?? null,
		'error' => $error,
		'payload_bytes' => strlen($payload),
		'prompt_bytes' => strlen($prompt),
	];
}

function diagnostic_classification(?array $result, ?Throwable $exception, array $logs): string
{
	if ($exception !== null) {
		return 'PHP/runtime issue or diagnostic exception: ' . diagnostic_redact($exception->getMessage());
	}
	if ($result === null) {
		return 'Unknown: no Gemini result was captured.';
	}
	if ($result['status'] >= 200 && $result['status'] < 300) {
		return 'Gemini API success. The safe recommendation request reached Gemini and returned normally; this does not indicate a configuration mismatch.';
	}
	if (in_array($result['status'], [429, 503], true)) {
		return 'Gemini API failure. HTTP ' . $result['status'] . ' is an upstream quota, rate-limit, or availability response, not a PHP parsing failure.';
	}
	if ($logs === []) {
		return 'Application/logging issue is possible because no relevant local log entry was found.';
	}

	return 'Unknown: Gemini returned HTTP ' . $result['status'] . '.';
}

$result = null;
$exception = null;
$logs = diagnostic_logs();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'recommendation') {
	try {
		$result = diagnostic_request();
	} catch (Throwable $caught) {
		$exception = $caught;
	}
}

$configKeys = ['GEMINI_API_KEY', 'GEMINI_MODEL', 'GEMINI_API_VERSION', 'GEMINI_TIMEOUT_SECONDS'];
$configPresence = [];
foreach ($configKeys as $key) {
	$configPresence[$key] = app_config($key, null) !== null && (string) app_config($key, '') !== '';
}
$knownDirectTest = 'Known successful direct test: HTTP 200, modelVersion gemini-3.8-flash, finishReason STOP, promptTokenCount 8, serviceTier standard.';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Gemini Recommendation Diagnostic</title>
	<style>
		:root { color-scheme: light; font-family: Consolas, "Courier New", monospace; }
		body { margin: 0; background: #eef1f4; color: #18212b; }
		main { max-width: 1100px; margin: 0 auto; padding: 24px; }
		section { margin: 16px 0; padding: 18px; background: #fff; border: 1px solid #cbd3dc; border-radius: 6px; }
		h1, h2 { font-family: system-ui, sans-serif; }
		h1 { margin-top: 0; }
		h2 { margin-top: 0; font-size: 1.15rem; }
		dl { display: grid; grid-template-columns: minmax(220px, 0.4fr) 1fr; gap: 7px 18px; }
		dt { font-weight: bold; color: #465465; }
		dd { margin: 0; overflow-wrap: anywhere; }
		pre { max-height: 440px; overflow: auto; padding: 12px; background: #101820; color: #e6edf3; white-space: pre-wrap; overflow-wrap: anywhere; }
		button { padding: 10px 15px; border: 0; border-radius: 4px; background: #1769aa; color: #fff; font-weight: bold; cursor: pointer; }
		.notice { padding: 10px; background: #e8f2fb; border-left: 4px solid #1769aa; }
		.warning { padding: 10px; background: #fff4d6; border-left: 4px solid #bc7a00; }
		.ok { color: #176b3a; font-weight: bold; }
		.fail { color: #a12622; font-weight: bold; }
	</style>
</head>
<body>
<main>
	<h1>Gemini Recommendation Diagnostic</h1>
	<p class="notice">Temporary read-only diagnostic. The recommendation test uses fixed synthetic health data and never displays credentials, headers, cookies, or session tokens.</p>

	<section>
		<h2>Environment</h2>
		<dl>
			<dt>APP_ENV</dt><dd><?= diagnostic_escape(app_config('APP_ENV', 'not configured')) ?></dd>
			<dt>Backend path</dt><dd><?= diagnostic_escape(dirname(__DIR__, 3)) ?></dd>
			<dt>Script path</dt><dd><?= diagnostic_escape(__FILE__) ?></dd>
			<dt>Request URI</dt><dd><?= diagnostic_escape($_SERVER['REQUEST_URI'] ?? '') ?></dd>
			<dt>Document root</dt><dd><?= diagnostic_escape($_SERVER['DOCUMENT_ROOT'] ?? 'not reported') ?></dd>
			<dt>PHP version</dt><dd><?= diagnostic_escape(PHP_VERSION) ?></dd>
			<dt>PHP SAPI</dt><dd><?= diagnostic_escape(PHP_SAPI) ?></dd>
			<dt>Loaded error log setting</dt><dd><?= diagnostic_escape(ini_get('error_log') ?: 'not configured') ?></dd>
		</dl>
	</section>

	<section>
		<h2>Gemini Configuration</h2>
		<dl>
			<dt>GEMINI_MODEL</dt><dd><?= diagnostic_escape(app_config('GEMINI_MODEL', 'not configured')) ?></dd>
			<dt>GEMINI_API_VERSION</dt><dd><?= diagnostic_escape(app_config('GEMINI_API_VERSION', 'not configured')) ?></dd>
			<dt>GEMINI_TIMEOUT_SECONDS</dt><dd><?= diagnostic_escape(app_config('GEMINI_TIMEOUT_SECONDS', 'not configured')) ?></dd>
			<dt>GEMINI_API_KEY</dt><dd><?= $configPresence['GEMINI_API_KEY'] ? 'present (value hidden)' : 'missing' ?></dd>
			<dt>Other required settings</dt><dd><?= diagnostic_escape(json_encode(array_diff_key($configPresence, ['GEMINI_API_KEY' => true]))) ?></dd>
			<dt>Runtime/config comparison</dt><dd><?= diagnostic_escape($knownDirectTest) ?> This page loaded the same bootstrap and configuration functions as the application.</dd>
		</dl>
	</section>

	<section>
		<h2>Recent Logs</h2>
		<?php if ($logs === []): ?>
			<p class="warning">No relevant entries were found in the backend log directory or detected Laragon PHP/Apache log files.</p>
		<?php else: ?>
			<?php foreach ($logs as $entry): ?>
				<pre><?= diagnostic_escape($entry['file'] . "\nLast modified: " . $entry['modified_at'] . "\n" . $entry['line']) ?></pre>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>

	<section>
		<h2>Gemini Direct Test</h2>
		<p><?= diagnostic_escape($knownDirectTest) ?></p>
		<form method="post">
			<input type="hidden" name="action" value="recommendation">
			<button type="submit">Test Gemini Recommendation</button>
		</form>
		<?php if ($result !== null): ?>
			<h3>Captured request result</h3>
			<dl>
				<dt>HTTP status</dt><dd class="<?= $result['status'] >= 200 && $result['status'] < 300 ? 'ok' : 'fail' ?>"><?= diagnostic_escape($result['status']) ?></dd>
				<dt>Request prompt bytes</dt><dd><?= diagnostic_escape($result['prompt_bytes']) ?></dd>
				<dt>Request payload bytes</dt><dd><?= diagnostic_escape($result['payload_bytes']) ?></dd>
				<dt>Model version</dt><dd><?= diagnostic_escape($result['model_version'] ?? 'not provided') ?></dd>
				<dt>Finish reason</dt><dd><?= diagnostic_escape($result['finish_reason'] ?? 'not provided') ?></dd>
				<dt>Prompt tokens</dt><dd><?= diagnostic_escape($result['prompt_tokens'] ?? 'not provided') ?></dd>
				<dt>Candidate tokens</dt><dd><?= diagnostic_escape($result['candidate_tokens'] ?? 'not provided') ?></dd>
				<dt>Total tokens</dt><dd><?= diagnostic_escape($result['total_tokens'] ?? 'not provided') ?></dd>
				<dt>Error details</dt><dd><pre><?= diagnostic_escape(diagnostic_json($result['error'])) ?></pre></dd>
			</dl>
			<h3>Redacted upstream response headers</h3>
			<pre><?= diagnostic_escape($result['headers']) ?></pre>
			<h3>Redacted response body</h3>
			<pre><?= diagnostic_escape($result['body']) ?></pre>
		<?php endif; ?>
		<?php if ($exception !== null): ?>
			<h3 class="fail">Captured exception</h3>
			<pre><?= diagnostic_escape(diagnostic_redact((string) $exception)) ?></pre>
		<?php endif; ?>
	</section>

	<section>
		<h2>Diagnosis</h2>
		<?php if ($result !== null || $exception !== null): ?>
			<p><?= diagnostic_escape(diagnostic_classification($result, $exception, $logs)) ?></p>
		<?php else: ?>
			<p>Run the recommendation test to classify the current result against the known successful direct Gemini test.</p>
		<?php endif; ?>
		<p><strong>Logging note:</strong> the existing production service currently reduces upstream non-2xx responses to a sanitized exception, so this page captures the upstream response independently without changing production logging.</p>
	</section>
</main>
</body>
</html>