<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/services/HealthMedicalProfileService.php';

if (($argv[1] ?? null) === '--unauthenticated') {
	unset($_SERVER['HTTP_AUTHORIZATION']);
	authenticated_user();
	exit(1);
}

$database = database_connection();
$assertions = 0;
$userIds = [];
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$testUser = static function (PDO $database, array &$userIds): int {
	$email = 'medical-profile-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
	$statement = $database->prepare('INSERT INTO users (uuid, email, password_hash, status) VALUES (?, ?, ?, ?)');
	$statement->execute([auth_uuid(), $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'active']);
	$userIds[] = (int) $database->lastInsertId();

	return end($userIds);
};
$payload = static function (): array {
	return [
		'consent' => ['given' => true, 'version' => HealthMedicalProfileService::CONSENT_VERSION],
		'lifestyle' => [
			'smoking_status' => 'former',
			'cigarettes_per_day' => 8,
			'years_smoked' => 4.5,
			'alcohol_consumption' => 'occasionally',
		],
		'no_known_medical_conditions' => false,
		'conditions' => [
			['condition' => 'diabetes'],
			['condition' => 'other', 'description' => 'A user-provided condition'],
		],
		'medical_history' => [
			'surgeries' => [['description' => 'Previous procedure', 'occurred_at' => '2020-03-15']],
			'hospitalizations' => [],
			'major_illnesses' => [['description' => 'Past illness']],
		],
		'allergies' => [['allergen' => 'Example allergen', 'reaction' => 'Example reaction']],
		'medications' => [['name' => 'Example medication', 'dosage' => '10 mg', 'frequency' => 'daily']],
		'insurance' => ['has_coverage' => true, 'company_name' => 'Example insurer', 'type' => 'individual'],
	];
};
$expectInvalid = static function (array $invalid, string $expectedField) use ($assert): void {
	try {
		HealthMedicalProfileService::validate($invalid);
		throw new RuntimeException('Invalid medical profile input was accepted.');
	} catch (HealthMedicalProfileValidationException $exception) {
		$assert(isset($exception->fields[$expectedField]), 'Validation error should identify ' . $expectedField . '.');
	}
};

try {
	$database->query('SELECT user_id FROM user_health_profiles LIMIT 0');
	$database->query('SELECT user_id FROM user_medical_conditions LIMIT 0');
	$database->query('SELECT user_id FROM user_medical_history LIMIT 0');
	$database->query('SELECT user_id FROM user_medications LIMIT 0');
	$database->query('SELECT user_id FROM user_allergies LIMIT 0');
	$database->query('SELECT user_id FROM user_insurance LIMIT 0');
	$database->query('SELECT user_id FROM user_health_consents LIMIT 0');

	$userId = $testUser($database, $userIds);
	$otherUserId = $testUser($database, $userIds);
	$withoutConsent = HealthMedicalProfileService::get($database, $userId);
	$assert($withoutConsent['consent']['given'] === false && $withoutConsent['medical_profile'] === null, 'Profile should be empty until consent is obtained.');
	$process = proc_open([PHP_BINARY, __FILE__, '--unauthenticated'], [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	], $pipes);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start the authentication test process.');
	}
	fclose($pipes[0]);
	$unauthenticatedResponse = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$unauthenticatedError = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$authenticationExitCode = proc_close($process);
	$authenticationBody = json_decode($unauthenticatedResponse, true);
	$assert($authenticationExitCode === 0 && $authenticationBody['success'] === false && $authenticationBody['error']['code'] === 'UNAUTHENTICATED', 'Unauthenticated requests must be rejected.');
	$assert($unauthenticatedError === '', 'Unauthenticated requests should not emit server errors.');

	$valid = $payload();
	$expectInvalid(array_diff_key($valid, ['consent' => true]), 'consent');
	$noConsent = $valid;
	$noConsent['consent']['given'] = false;
	$expectInvalid($noConsent, 'consent.given');
	$optionalOtherDescription = $valid;
	unset($optionalOtherDescription['conditions'][1]['description']);
	HealthMedicalProfileService::validate($optionalOtherDescription);
	$badEnum = $valid;
	$badEnum['lifestyle']['smoking_status'] = 'daily';
	$expectInvalid($badEnum, 'lifestyle.smoking_status');
	$missingOtherDescription = $valid;
	$missingOtherDescription['conditions'][1]['description'] = '';
	$expectInvalid($missingOtherDescription, 'conditions.1.description');
	$unexpectedUser = $valid;
	$unexpectedUser['user_id'] = $otherUserId;
	$expectInvalid($unexpectedUser, 'user_id');
	fwrite(STDOUT, "PASS consent and field validation\n");

	HealthMedicalProfileService::save($database, $userId, $valid);
	$saved = HealthMedicalProfileService::get($database, $userId);
	$assert($saved['completion']['complete'] === true && $saved['completion']['percentage'] === 100, 'Saved profile should report complete.');
	$assert(count($saved['medical_profile']['conditions']) === 2, 'Multiple conditions should persist.');
	$assert($saved['medical_profile']['conditions'][1]['description'] === 'A user-provided condition', 'Other condition description should persist.');
	$assert(count($saved['medical_profile']['medical_history']['surgeries']) === 1, 'Previous surgeries should persist.');
	$assert(count($saved['medical_profile']['medications']) === 1 && count($saved['medical_profile']['allergies']) === 1, 'Medications and allergies should persist.');
	$optionalDescription = $payload();
	unset($optionalDescription['conditions'][1]['description']);
	HealthMedicalProfileService::save($database, $userId, $optionalDescription);
	$optionalCondition = HealthMedicalProfileService::get($database, $userId);
	$assert($optionalCondition['medical_profile']['conditions'][1]['description'] === null, 'Other condition description should be optional.');
	HealthMedicalProfileService::save($database, $userId, $valid);
	$otherUser = HealthMedicalProfileService::get($database, $otherUserId);
	$assert($otherUser['medical_profile'] === null && $otherUser['consent']['given'] === false, 'A different user must not see the saved profile.');
	$consentCount = $database->prepare('SELECT COUNT(*) FROM user_health_consents WHERE user_id = ? AND withdrawn_at IS NULL');
	$consentCount->execute([$userId]);
	$assert((int) $consentCount->fetchColumn() === 1, 'Consent should be recorded once and associated with its user.');
	fwrite(STDOUT, "PASS profile persistence, completion, consent recording, and user isolation\n");

	$updated = $payload();
	$updated['lifestyle'] = [
		'smoking_status' => 'never',
		'cigarettes_per_day' => null,
		'years_smoked' => null,
		'alcohol_consumption' => 'never',
	];
	$updated['no_known_medical_conditions'] = true;
	$updated['conditions'] = [];
	$updated['medical_history'] = ['surgeries' => [], 'hospitalizations' => [], 'major_illnesses' => []];
	$updated['allergies'] = [];
	$updated['medications'] = [];
	$updated['insurance'] = ['has_coverage' => false, 'company_name' => null, 'type' => null];
	HealthMedicalProfileService::save($database, $userId, $updated);
	$updatedProfile = HealthMedicalProfileService::get($database, $userId);
	$assert($updatedProfile['medical_profile']['lifestyle']['smoking_status'] === 'never', 'Lifestyle update should replace saved fields.');
	$assert($updatedProfile['medical_profile']['no_known_medical_conditions'] === true && $updatedProfile['medical_profile']['conditions'] === [], 'No-known-conditions choice should persist.');
	$assert($updatedProfile['medical_profile']['medications'] === [] && $updatedProfile['medical_profile']['allergies'] === [], 'Empty replacement lists should remove prior entries.');
	$consentCount->execute([$userId]);
	$assert((int) $consentCount->fetchColumn() === 1, 'A repeated update with the current consent should not duplicate consent.');
	$legacyConsent = $database->prepare('UPDATE user_health_consents SET consent_version = ? WHERE user_id = ? AND withdrawn_at IS NULL');
	$legacyConsent->execute(['0.9', $userId]);
	$staleConsent = HealthMedicalProfileService::get($database, $userId);
	$assert($staleConsent['consent']['given'] === false && $staleConsent['medical_profile'] === null, 'An outdated consent version must not expose stored health information.');
	HealthMedicalProfileService::save($database, $userId, $updated);
	$reconsented = HealthMedicalProfileService::get($database, $userId);
	$assert($reconsented['consent']['given'] === true && $reconsented['medical_profile'] !== null, 'Accepting the current version should restore access to the profile.');
	fwrite(STDOUT, "PASS complete profile update and replacement semantics\n");

	HealthMedicalProfileService::withdraw($database, $userId);
	$withdrawn = HealthMedicalProfileService::get($database, $userId);
	$assert($withdrawn['consent']['given'] === false && $withdrawn['medical_profile'] === null, 'Withdrawal should hide and delete the profile.');
	$withdrawnConsent = $database->prepare('SELECT withdrawn_at FROM user_health_consents WHERE user_id = ? ORDER BY id DESC LIMIT 1');
	$withdrawnConsent->execute([$userId]);
	$assert($withdrawnConsent->fetchColumn() !== null, 'Withdrawal should be timestamped in the consent history.');
	$childCount = $database->prepare('SELECT (SELECT COUNT(*) FROM user_medical_conditions WHERE user_id = ?) + (SELECT COUNT(*) FROM user_medical_history WHERE user_id = ?) + (SELECT COUNT(*) FROM user_medications WHERE user_id = ?) + (SELECT COUNT(*) FROM user_allergies WHERE user_id = ?) + (SELECT COUNT(*) FROM user_insurance WHERE user_id = ?)');
	$childCount->execute([$userId, $userId, $userId, $userId, $userId]);
	$assert((int) $childCount->fetchColumn() === 0, 'Withdrawal should remove all associated medical data.');
	HealthMedicalProfileService::withdraw($database, $userId);
	fwrite(STDOUT, "PASS consent withdrawal, profile deletion, and idempotent deletion\n");
	fwrite(STDOUT, "OK {$assertions} assertions\n");
} finally {
	if ($userIds !== []) {
		$placeholders = implode(',', array_fill(0, count($userIds), '?'));
		$deleteUsers = $database->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
		$deleteUsers->execute($userIds);
	}
}
