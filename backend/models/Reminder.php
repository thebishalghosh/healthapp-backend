<?php

declare(strict_types=1);

final class Reminder
{
	public const TYPES = ['water', 'meal', 'workout', 'sleep'];
	public const REPEAT_TYPES = ['once', 'daily', 'weekly', 'custom'];
	public const SOURCES = ['manual', 'reminder'];

	public static function validate(array $data): array
	{
		$fields = [];
		$reminderType = $data['reminder_type'] ?? null;
		$title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
		$repeatType = $data['repeat_type'] ?? null;
		$reminderTime = $data['reminder_time'] ?? null;
		$startDate = $data['start_date'] ?? null;
		$endDate = $data['end_date'] ?? null;

		if (!in_array($reminderType, self::TYPES, true)) {
			$fields['reminder_type'] = 'Reminder type must be water, meal, workout, or sleep.';
		}
		if ($title === '' || strlen($title) > 191) {
			$fields['title'] = 'Title must be 1 to 191 characters.';
		}
		if ($reminderTime === null || !is_string($reminderTime) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $reminderTime) !== 1) {
			$fields['reminder_time'] = 'Reminder time must be a valid HH:MM or HH:MM:SS time.';
		}
		if (!in_array($repeatType, self::REPEAT_TYPES, true)) {
			$fields['repeat_type'] = 'Repeat type must be once, daily, weekly, or custom.';
		}
		foreach (['start_date' => $startDate, 'end_date' => $endDate] as $field => $value) {
			if ($value !== null && (!is_string($value) || !self::validDate($value))) {
				$fields[$field] = 'Date must be a valid YYYY-MM-DD date.';
			}
		}
		if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
			$fields['end_date'] = 'End date must be on or after start date.';
		}
		if (array_key_exists('message', $data) && $data['message'] !== null && (!is_string($data['message']) || strlen($data['message']) > 500)) {
			$fields['message'] = 'Message must be null or at most 500 characters.';
		}
		if (array_key_exists('repeat_days', $data) && $data['repeat_days'] !== null) {
			$repeatDays = $data['repeat_days'];
			if (!is_array($repeatDays) || count(array_unique($repeatDays)) !== count($repeatDays)) {
				$fields['repeat_days'] = 'Repeat days must be a unique array of weekday numbers from 0 to 6.';
			} else {
				foreach ($repeatDays as $day) {
					if (!is_int($day) || $day < 0 || $day > 6) {
						$fields['repeat_days'] = 'Repeat days must be a unique array of weekday numbers from 0 to 6.';
						break;
					}
				}
			}
		}
		if (array_key_exists('is_enabled', $data) && !is_bool($data['is_enabled']) && !in_array($data['is_enabled'], [0, 1, '0', '1'], true)) {
			$fields['is_enabled'] = 'Enabled must be a boolean.';
		}

		return $fields;
	}

	public static function normalize(array $data): array
	{
		$data['id'] = (int) $data['id'];
		$data['is_enabled'] = (bool) $data['is_enabled'];
		$data['repeat_days'] = $data['repeat_days'] === null ? null : json_decode($data['repeat_days'], true);
		return $data;
	}

	public static function list(PDO $database, int $userId): array
	{
		$statement = $database->prepare('SELECT id, reminder_type, title, message, reminder_time, start_date, end_date, repeat_type, repeat_days, is_enabled, created_at, updated_at FROM reminders WHERE user_id = :user_id ORDER BY reminder_time ASC, id ASC');
		$statement->execute(['user_id' => $userId]);
		$reminders = [];
		while ($reminder = $statement->fetch()) {
			$reminders[] = self::normalize($reminder);
		}
		return $reminders;
	}

	public static function create(PDO $database, int $userId, array $data): array
	{
		$statement = $database->prepare(
			'INSERT INTO reminders (user_id, reminder_type, title, message, reminder_time, start_date, end_date, repeat_type, repeat_days, is_enabled)
			 VALUES (:user_id, :reminder_type, :title, :message, :reminder_time, :start_date, :end_date, :repeat_type, :repeat_days, :is_enabled)'
		);
		$statement->execute(self::parameters($userId, $data));
		return self::findOwned($database, $userId, (int) $database->lastInsertId());
	}

	public static function update(PDO $database, int $userId, int $id, array $data): ?array
	{
		$statement = $database->prepare(
			'UPDATE reminders SET reminder_type = :reminder_type, title = :title, message = :message, reminder_time = :reminder_time, start_date = :start_date, end_date = :end_date, repeat_type = :repeat_type, repeat_days = :repeat_days, is_enabled = :is_enabled WHERE id = :id AND user_id = :user_id'
		);
		$params = self::parameters($userId, $data);
		$params['id'] = $id;
		$statement->execute($params);
		return self::findOwned($database, $userId, $id);
	}

	public static function delete(PDO $database, int $userId, int $id): bool
	{
		$statement = $database->prepare('DELETE FROM reminders WHERE id = :id AND user_id = :user_id');
		$statement->execute(['id' => $id, 'user_id' => $userId]);
		return $statement->rowCount() > 0;
	}

	private static function findOwned(PDO $database, int $userId, int $id): ?array
	{
		$statement = $database->prepare('SELECT id, reminder_type, title, message, reminder_time, start_date, end_date, repeat_type, repeat_days, is_enabled, created_at, updated_at FROM reminders WHERE id = :id AND user_id = :user_id LIMIT 1');
		$statement->execute(['id' => $id, 'user_id' => $userId]);
		$reminder = $statement->fetch();
		return $reminder ? self::normalize($reminder) : null;
	}

	private static function parameters(int $userId, array $data): array
	{
		$repeatDays = $data['repeat_days'] ?? null;
		return [
			'user_id' => $userId,
			'reminder_type' => $data['reminder_type'],
			'title' => trim($data['title']),
			'message' => $data['message'] ?? null,
			'reminder_time' => $data['reminder_time'],
			'start_date' => $data['start_date'] ?? null,
			'end_date' => $data['end_date'] ?? null,
			'repeat_type' => $data['repeat_type'],
			'repeat_days' => $repeatDays === null ? null : json_encode(array_values($repeatDays), JSON_THROW_ON_ERROR),
			'is_enabled' => (int) ($data['is_enabled'] ?? true),
		];
	}

	private static function validDate(string $value): bool
	{
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
		return $date !== false && $date->format('Y-m-d') === $value;
	}
}
