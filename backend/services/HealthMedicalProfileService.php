<?php

declare(strict_types=1);

final class HealthMedicalProfileValidationException extends InvalidArgumentException
{
	public function __construct(public readonly array $fields)
	{
		parent::__construct('Health and medical profile validation failed.');
	}
}

final class HealthMedicalProfileService
{
	public const CONSENT_VERSION = '1.0';
	public const CONSENT_NOTICE = 'I understand this profile contains sensitive health information, including lifestyle, medical history, allergies, medications, and insurance details. I voluntarily consent to Life Logic storing and processing it to provide health profile features. This profile is optional. I can withdraw consent at any time, which deletes this profile data; a record of consent and withdrawal may be retained.';

	private const CONDITIONS = [
		'diabetes',
		'hypertension',
		'hypotension',
		'thyroid_disorders',
		'heart_disease',
		'asthma',
		'kidney_disease',
		'liver_disease',
		'pcos_pcod',
		'arthritis',
		'high_cholesterol',
		'other',
	];

	public static function validate(array $body): array
	{
		$fields = [];
		$allowed = [
			'consent',
			'lifestyle',
			'no_known_medical_conditions',
			'conditions',
			'medical_history',
			'allergies',
			'medications',
			'insurance',
		];
		self::rejectUnknown($body, $allowed, '', $fields);

		$consent = $body['consent'] ?? null;
		if (!is_array($consent)) {
			$fields['consent'] = 'Consent must be explicitly provided.';
		} else {
			self::rejectUnknown($consent, ['given', 'version'], 'consent.', $fields);
			if (($consent['given'] ?? null) !== true) {
				$fields['consent.given'] = 'Explicit consent is required to save health information.';
			}
			if (($consent['version'] ?? null) !== self::CONSENT_VERSION) {
				$fields['consent.version'] = 'The current consent version must be accepted.';
			}
		}

		$lifestyle = $body['lifestyle'] ?? null;
		if (!is_array($lifestyle)) {
			$fields['lifestyle'] = 'Lifestyle information is required.';
			$lifestyle = [];
		}
		self::rejectUnknown($lifestyle, ['smoking_status', 'cigarettes_per_day', 'years_smoked', 'alcohol_consumption'], 'lifestyle.', $fields);
		$smoking = $lifestyle['smoking_status'] ?? null;
		if (!in_array($smoking, ['never', 'former', 'current'], true)) {
			$fields['lifestyle.smoking_status'] = 'Smoking status is invalid.';
		}
		if (!in_array($lifestyle['alcohol_consumption'] ?? null, ['never', 'former', 'occasionally', 'weekly', 'frequently'], true)) {
			$fields['lifestyle.alcohol_consumption'] = 'Alcohol consumption is invalid.';
		}
		$cigarettes = $lifestyle['cigarettes_per_day'] ?? null;
		$years = $lifestyle['years_smoked'] ?? null;
		if ($smoking === 'never') {
			if ($cigarettes !== null || $years !== null) {
				$fields['lifestyle.cigarettes_per_day'] = 'Smoking details must be null for never smokers.';
			}
			$cigarettes = null;
			$years = null;
		} elseif (in_array($smoking, ['former', 'current'], true)) {
			if (!is_int($cigarettes) || $cigarettes < 1 || $cigarettes > 200) {
				$fields['lifestyle.cigarettes_per_day'] = 'Cigarettes per day must be an integer from 1 to 200 for former or current smokers.';
			}
			if (!self::validNumber($years, 0.01, 100)) {
				$fields['lifestyle.years_smoked'] = 'Years smoked must be greater than 0 and at most 100.';
			}
		}

		$noKnownConditions = $body['no_known_medical_conditions'] ?? null;
		if (!is_bool($noKnownConditions)) {
			$fields['no_known_medical_conditions'] = 'This field must be true or false.';
		}
		$conditions = self::validateList($body['conditions'] ?? null, 'conditions', 50, $fields);
		$seenConditions = [];
		foreach ($conditions as $index => $condition) {
			if (!is_array($condition)) {
				$fields['conditions.' . $index] = 'Each condition must be an object.';
				continue;
			}
			self::rejectUnknown($condition, ['condition', 'description'], 'conditions.' . $index . '.', $fields);
			$name = $condition['condition'] ?? null;
			if (!in_array($name, self::CONDITIONS, true)) {
				$fields['conditions.' . $index . '.condition'] = 'Medical condition is invalid.';
			} elseif (isset($seenConditions[$name])) {
				$fields['conditions.' . $index . '.condition'] = 'Medical conditions must not be duplicated.';
			} else {
				$seenConditions[$name] = true;
			}
			$description = $condition['description'] ?? null;
			if ($name === 'other') {
				if ($description !== null && !self::validString($description, 1, 500)) {
					$fields['conditions.' . $index . '.description'] = 'Description must be 1 to 500 characters when provided.';
				}
			} elseif ($description !== null) {
				$fields['conditions.' . $index . '.description'] = 'Description is only supported for other conditions.';
			}
		}
		if ($noKnownConditions === true && $conditions !== []) {
			$fields['conditions'] = 'Conditions must be empty when no_known_medical_conditions is true.';
		}

		$history = $body['medical_history'] ?? null;
		if (!is_array($history)) {
			$fields['medical_history'] = 'Medical history is required.';
			$history = [];
		}
		self::rejectUnknown($history, ['surgeries', 'hospitalizations', 'major_illnesses'], 'medical_history.', $fields);
		$normalizedHistory = [];
		foreach (['surgeries' => 'surgery', 'hospitalizations' => 'hospitalization', 'major_illnesses' => 'major_illness'] as $key => $type) {
			$entries = self::validateList($history[$key] ?? null, 'medical_history.' . $key, 100, $fields);
			$normalizedHistory[$key] = [];
			foreach ($entries as $index => $entry) {
				$prefix = 'medical_history.' . $key . '.' . $index;
				if (!is_array($entry)) {
					$fields[$prefix] = 'Each history entry must be an object.';
					continue;
				}
				self::rejectUnknown($entry, ['description', 'occurred_at'], $prefix . '.', $fields);
				if (!self::validString($entry['description'] ?? null, 1, 1000)) {
					$fields[$prefix . '.description'] = 'Description must be 1 to 1000 characters.';
				}
				$date = $entry['occurred_at'] ?? null;
				if ($date !== null && !self::validDate($date)) {
					$fields[$prefix . '.occurred_at'] = 'Date must use the YYYY-MM-DD format.';
				}
				$normalizedHistory[$key][] = [
					'history_type' => $type,
					'description' => is_string($entry['description'] ?? null) ? trim($entry['description']) : '',
					'occurred_at' => $date,
				];
			}
		}

		$allergies = self::validateList($body['allergies'] ?? null, 'allergies', 100, $fields);
		foreach ($allergies as $index => $allergy) {
			$prefix = 'allergies.' . $index;
			if (!is_array($allergy)) {
				$fields[$prefix] = 'Each allergy must be an object.';
				continue;
			}
			self::rejectUnknown($allergy, ['allergen', 'reaction'], $prefix . '.', $fields);
			if (!self::validString($allergy['allergen'] ?? null, 1, 150)) {
				$fields[$prefix . '.allergen'] = 'Allergen must be 1 to 150 characters.';
			}
			if (($allergy['reaction'] ?? null) !== null && !self::validString($allergy['reaction'], 1, 500)) {
				$fields[$prefix . '.reaction'] = 'Reaction must be 1 to 500 characters when provided.';
			}
		}

		$medications = self::validateList($body['medications'] ?? null, 'medications', 100, $fields);
		foreach ($medications as $index => $medication) {
			$prefix = 'medications.' . $index;
			if (!is_array($medication)) {
				$fields[$prefix] = 'Each medication must be an object.';
				continue;
			}
			self::rejectUnknown($medication, ['name', 'dosage', 'frequency'], $prefix . '.', $fields);
			if (!self::validString($medication['name'] ?? null, 1, 150)) {
				$fields[$prefix . '.name'] = 'Medication name must be 1 to 150 characters.';
			}
			foreach (['dosage', 'frequency'] as $field) {
				if (($medication[$field] ?? null) !== null && !self::validString($medication[$field], 1, 100)) {
					$fields[$prefix . '.' . $field] = ucfirst($field) . ' must be 1 to 100 characters when provided.';
				}
			}
		}

		$insurance = $body['insurance'] ?? null;
		if (!is_array($insurance)) {
			$fields['insurance'] = 'Insurance information is required.';
			$insurance = [];
		}
		self::rejectUnknown($insurance, ['has_coverage', 'company_name', 'type'], 'insurance.', $fields);
		$hasCoverage = $insurance['has_coverage'] ?? null;
		if (!is_bool($hasCoverage)) {
			$fields['insurance.has_coverage'] = 'This field must be true or false.';
		} elseif ($hasCoverage) {
			if (!self::validString($insurance['company_name'] ?? null, 1, 191)) {
				$fields['insurance.company_name'] = 'Insurance company name must be 1 to 191 characters.';
			}
			if (!in_array($insurance['type'] ?? null, ['individual', 'family_floater', 'employer_provided', 'other'], true)) {
				$fields['insurance.type'] = 'Insurance type is invalid.';
			}
		} elseif (($insurance['company_name'] ?? null) !== null || ($insurance['type'] ?? null) !== null) {
			$fields['insurance.company_name'] = 'Company name and insurance type must be null when coverage is false.';
		}

		if ($fields !== []) {
			throw new HealthMedicalProfileValidationException($fields);
		}

		return [
			'lifestyle' => [
				'smoking_status' => $smoking,
				'cigarettes_per_day' => $cigarettes,
				'years_smoked' => $years === null ? null : (float) $years,
				'alcohol_consumption' => $lifestyle['alcohol_consumption'],
			],
			'no_known_medical_conditions' => $noKnownConditions,
			'conditions' => $conditions,
			'medical_history' => $normalizedHistory,
			'allergies' => $allergies,
			'medications' => $medications,
			'insurance' => [
				'has_coverage' => $hasCoverage,
				'company_name' => $hasCoverage ? trim($insurance['company_name']) : null,
				'type' => $hasCoverage ? $insurance['type'] : null,
			],
		];
	}

	public static function get(PDO $database, int $userId): array
	{
		$consentStatement = $database->prepare(
			'SELECT consent_version, consented_at, withdrawn_at
			 FROM user_health_consents WHERE user_id = :user_id ORDER BY id DESC LIMIT 1'
		);
		$consentStatement->execute(['user_id' => $userId]);
		$consent = $consentStatement->fetch() ?: null;
		$hasConsent = $consent !== null
			&& $consent['withdrawn_at'] === null
			&& $consent['consent_version'] === self::CONSENT_VERSION;
		if (!$hasConsent) {
			return [
				'consent' => [
					'given' => false,
					'version' => $consent['consent_version'] ?? null,
					'consented_at' => $consent['consented_at'] ?? null,
					'withdrawn_at' => $consent['withdrawn_at'] ?? null,
					'current_version' => self::CONSENT_VERSION,
					'notice' => self::CONSENT_NOTICE,
				],
				'medical_profile' => null,
				'completion' => [
					'complete' => false,
					'percentage' => 0,
					'missing_sections' => ['lifestyle', 'medical_conditions', 'medical_history', 'allergies', 'medications', 'insurance'],
				],
			];
		}

		$profileStatement = $database->prepare(
			'SELECT smoking_status, cigarettes_per_day, years_smoked, alcohol_consumption, no_known_medical_conditions, created_at, updated_at
			 FROM user_health_profiles WHERE user_id = :user_id LIMIT 1'
		);
		$profileStatement->execute(['user_id' => $userId]);
		$profile = $profileStatement->fetch();
		if (!$profile) {
			return [
				'consent' => [
					'given' => true,
					'version' => $consent['consent_version'],
					'consented_at' => $consent['consented_at'],
					'withdrawn_at' => null,
					'current_version' => self::CONSENT_VERSION,
					'notice' => self::CONSENT_NOTICE,
				],
				'medical_profile' => null,
				'completion' => ['complete' => false, 'percentage' => 0, 'missing_sections' => ['medical_profile']],
			];
		}

		$conditionStatement = $database->prepare(
			'SELECT condition_name AS `condition`, description FROM user_medical_conditions WHERE user_id = :user_id ORDER BY id'
		);
		$conditionStatement->execute(['user_id' => $userId]);
		$historyStatement = $database->prepare(
			'SELECT history_type, description, occurred_at FROM user_medical_history WHERE user_id = :user_id ORDER BY id'
		);
		$historyStatement->execute(['user_id' => $userId]);
		$medicationStatement = $database->prepare(
			'SELECT medication_name AS name, dosage, frequency FROM user_medications WHERE user_id = :user_id ORDER BY id'
		);
		$medicationStatement->execute(['user_id' => $userId]);
		$allergyStatement = $database->prepare(
			'SELECT allergen, reaction FROM user_allergies WHERE user_id = :user_id ORDER BY id'
		);
		$allergyStatement->execute(['user_id' => $userId]);
		$insuranceStatement = $database->prepare(
			'SELECT has_coverage, company_name, insurance_type FROM user_insurance WHERE user_id = :user_id LIMIT 1'
		);
		$insuranceStatement->execute(['user_id' => $userId]);
		$insurance = $insuranceStatement->fetch();
		$history = ['surgeries' => [], 'hospitalizations' => [], 'major_illnesses' => []];
		$historyKeys = ['surgery' => 'surgeries', 'hospitalization' => 'hospitalizations', 'major_illness' => 'major_illnesses'];
		foreach ($historyStatement->fetchAll() as $entry) {
			$history[$historyKeys[$entry['history_type']]][] = [
				'description' => $entry['description'],
				'occurred_at' => $entry['occurred_at'],
			];
		}

		return [
			'consent' => [
				'given' => true,
				'version' => $consent['consent_version'],
				'consented_at' => $consent['consented_at'],
				'withdrawn_at' => null,
				'current_version' => self::CONSENT_VERSION,
				'notice' => self::CONSENT_NOTICE,
			],
			'medical_profile' => [
				'lifestyle' => [
					'smoking_status' => $profile['smoking_status'],
					'cigarettes_per_day' => $profile['cigarettes_per_day'] === null ? null : (int) $profile['cigarettes_per_day'],
					'years_smoked' => $profile['years_smoked'] === null ? null : (float) $profile['years_smoked'],
					'alcohol_consumption' => $profile['alcohol_consumption'],
				],
				'no_known_medical_conditions' => (bool) $profile['no_known_medical_conditions'],
				'conditions' => $conditionStatement->fetchAll(),
				'medical_history' => $history,
				'allergies' => $allergyStatement->fetchAll(),
				'medications' => $medicationStatement->fetchAll(),
				'insurance' => $insurance ? [
					'has_coverage' => (bool) $insurance['has_coverage'],
					'company_name' => $insurance['company_name'],
					'type' => $insurance['insurance_type'],
				] : ['has_coverage' => false, 'company_name' => null, 'type' => null],
				'created_at' => $profile['created_at'],
				'updated_at' => $profile['updated_at'],
			],
			'completion' => ['complete' => true, 'percentage' => 100, 'missing_sections' => []],
		];
	}

	public static function consentedFoodAllergies(PDO $database, int $userId): array
	{
		$statement = $database->prepare(
			'SELECT allergy.allergen
			 FROM user_allergies allergy
			 WHERE allergy.user_id = :user_id
			   AND EXISTS (
					SELECT 1 FROM user_health_consents consent
					WHERE consent.user_id = allergy.user_id
					  AND consent.id = (
							SELECT MAX(latest.id) FROM user_health_consents latest WHERE latest.user_id = allergy.user_id
					  )
					  AND consent.consent_version = :consent_version
					  AND consent.withdrawn_at IS NULL
			   )
			 ORDER BY allergy.id'
		);
		$statement->execute([
			'user_id' => $userId,
			'consent_version' => self::CONSENT_VERSION,
		]);

		return array_map(static fn (array $allergy): string => $allergy['allergen'], $statement->fetchAll());
	}

	public static function save(PDO $database, int $userId, array $body): void
	{
		$data = self::validate($body);
		$database->beginTransaction();
		try {
			$profileStatement = $database->prepare(
				'INSERT INTO user_health_profiles
				 (user_id, smoking_status, cigarettes_per_day, years_smoked, alcohol_consumption, no_known_medical_conditions)
				 VALUES (:user_id, :smoking_status, :cigarettes_per_day, :years_smoked, :alcohol_consumption, :no_known_medical_conditions)
				 ON DUPLICATE KEY UPDATE smoking_status = VALUES(smoking_status),
				 cigarettes_per_day = VALUES(cigarettes_per_day), years_smoked = VALUES(years_smoked),
				 alcohol_consumption = VALUES(alcohol_consumption),
				 no_known_medical_conditions = VALUES(no_known_medical_conditions)'
			);
			$profileStatement->execute([
				'user_id' => $userId,
				'smoking_status' => $data['lifestyle']['smoking_status'],
				'cigarettes_per_day' => $data['lifestyle']['cigarettes_per_day'],
				'years_smoked' => $data['lifestyle']['years_smoked'],
				'alcohol_consumption' => $data['lifestyle']['alcohol_consumption'],
				'no_known_medical_conditions' => $data['no_known_medical_conditions'] ? 1 : 0,
			]);

			foreach (['user_medical_conditions', 'user_medical_history', 'user_medications', 'user_allergies', 'user_insurance'] as $table) {
				$delete = $database->prepare('DELETE FROM ' . $table . ' WHERE user_id = :user_id');
				$delete->execute(['user_id' => $userId]);
			}
			self::insertConditions($database, $userId, $data['conditions']);
			self::insertHistory($database, $userId, $data['medical_history']);
			self::insertMedications($database, $userId, $data['medications']);
			self::insertAllergies($database, $userId, $data['allergies']);
			self::insertInsurance($database, $userId, $data['insurance']);

			$withdraw = $database->prepare(
				'UPDATE user_health_consents SET withdrawn_at = UTC_TIMESTAMP()
				 WHERE user_id = :user_id AND withdrawn_at IS NULL AND consent_version <> :version'
			);
			$withdraw->execute(['user_id' => $userId, 'version' => self::CONSENT_VERSION]);
			$activeConsent = $database->prepare(
				'SELECT id FROM user_health_consents WHERE user_id = :user_id AND withdrawn_at IS NULL
				 AND consent_version = :version LIMIT 1 FOR UPDATE'
			);
			$activeConsent->execute(['user_id' => $userId, 'version' => self::CONSENT_VERSION]);
			if (!$activeConsent->fetch()) {
				$consentInsert = $database->prepare(
					'INSERT INTO user_health_consents (user_id, consent_version, consented_at) VALUES (:user_id, :version, UTC_TIMESTAMP())'
				);
				$consentInsert->execute(['user_id' => $userId, 'version' => self::CONSENT_VERSION]);
			}
			$database->commit();
		} catch (Throwable $exception) {
			if ($database->inTransaction()) {
				$database->rollBack();
			}
			throw $exception;
		}
	}

	public static function withdraw(PDO $database, int $userId): void
	{
		$database->beginTransaction();
		try {
			$withdraw = $database->prepare(
				'UPDATE user_health_consents SET withdrawn_at = UTC_TIMESTAMP()
				 WHERE user_id = :user_id AND withdrawn_at IS NULL'
			);
			$withdraw->execute(['user_id' => $userId]);
			$delete = $database->prepare('DELETE FROM user_health_profiles WHERE user_id = :user_id');
			$delete->execute(['user_id' => $userId]);
			$database->commit();
		} catch (Throwable $exception) {
			if ($database->inTransaction()) {
				$database->rollBack();
			}
			throw $exception;
		}
	}

	private static function insertConditions(PDO $database, int $userId, array $conditions): void
	{
		$statement = $database->prepare(
			'INSERT INTO user_medical_conditions (user_id, condition_name, description) VALUES (:user_id, :condition_name, :description)'
		);
		foreach ($conditions as $condition) {
			$statement->execute([
				'user_id' => $userId,
				'condition_name' => $condition['condition'],
				'description' => $condition['condition'] === 'other' && isset($condition['description']) ? trim($condition['description']) : null,
			]);
		}
	}

	private static function insertHistory(PDO $database, int $userId, array $history): void
	{
		$statement = $database->prepare(
			'INSERT INTO user_medical_history (user_id, history_type, description, occurred_at)
			 VALUES (:user_id, :history_type, :description, :occurred_at)'
		);
		foreach ($history as $entries) {
			foreach ($entries as $entry) {
				$statement->execute([
					'user_id' => $userId,
					'history_type' => $entry['history_type'],
					'description' => $entry['description'],
					'occurred_at' => $entry['occurred_at'],
				]);
			}
		}
	}

	private static function insertMedications(PDO $database, int $userId, array $medications): void
	{
		$statement = $database->prepare(
			'INSERT INTO user_medications (user_id, medication_name, dosage, frequency)
			 VALUES (:user_id, :medication_name, :dosage, :frequency)'
		);
		foreach ($medications as $medication) {
			$statement->execute([
				'user_id' => $userId,
				'medication_name' => trim($medication['name']),
				'dosage' => isset($medication['dosage']) ? trim($medication['dosage']) : null,
				'frequency' => isset($medication['frequency']) ? trim($medication['frequency']) : null,
			]);
		}
	}

	private static function insertAllergies(PDO $database, int $userId, array $allergies): void
	{
		$statement = $database->prepare(
			'INSERT INTO user_allergies (user_id, allergen, reaction) VALUES (:user_id, :allergen, :reaction)'
		);
		foreach ($allergies as $allergy) {
			$statement->execute([
				'user_id' => $userId,
				'allergen' => trim($allergy['allergen']),
				'reaction' => isset($allergy['reaction']) ? trim($allergy['reaction']) : null,
			]);
		}
	}

	private static function insertInsurance(PDO $database, int $userId, array $insurance): void
	{
		$statement = $database->prepare(
			'INSERT INTO user_insurance (user_id, has_coverage, company_name, insurance_type)
			 VALUES (:user_id, :has_coverage, :company_name, :insurance_type)'
		);
		$statement->execute([
			'user_id' => $userId,
			'has_coverage' => $insurance['has_coverage'] ? 1 : 0,
			'company_name' => $insurance['company_name'],
			'insurance_type' => $insurance['type'],
		]);
	}

	private static function rejectUnknown(array $value, array $allowed, string $prefix, array &$fields): void
	{
		foreach ($value as $key => $_) {
			if (!is_string($key) || !in_array($key, $allowed, true)) {
				$fields[$prefix . (string) $key] = 'This field is not supported.';
			}
		}
	}

	private static function validateList(mixed $value, string $field, int $maximum, array &$fields): array
	{
		if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
			$fields[$field] = 'This field must be a list with no more than ' . $maximum . ' entries.';
			return [];
		}

		return $value;
	}

	private static function validString(mixed $value, int $minimum, int $maximum): bool
	{
		if (!is_string($value)) {
			return false;
		}
		$value = trim($value);
		if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
			return false;
		}
		$length = preg_match_all('/./us', $value, $characters);

		return $length !== false && $length >= $minimum && $length <= $maximum;
	}

	private static function validNumber(mixed $value, float $minimum, float $maximum): bool
	{
		return (is_int($value) || is_float($value))
			&& is_finite((float) $value)
			&& (float) $value >= $minimum
			&& (float) $value <= $maximum;
	}

	private static function validDate(mixed $value): bool
	{
		if (!is_string($value)) {
			return false;
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

		return $date !== false && $date->format('Y-m-d') === $value;
	}
}
