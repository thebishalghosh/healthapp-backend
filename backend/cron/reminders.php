<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/models/UserSettings.php';
require_once dirname(__DIR__) . '/services/NotificationService.php';

$database = database_connection();
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$statement = $database->query(
	"SELECT r.*, COALESCE(s.timezone, 'UTC') AS user_timezone
	 FROM reminders r
	 LEFT JOIN user_settings s ON s.user_id = r.user_id
	 WHERE r.is_enabled = 1"
);

$processed = 0;
while ($reminder = $statement->fetch()) {
	try {
		$timezone = new DateTimeZone($reminder['user_timezone']);
		$localNow = $nowUtc->setTimezone($timezone);
		$localDate = $localNow->format('Y-m-d');
		$reminderTime = substr((string) $reminder['reminder_time'], 0, 5);
		if ($localNow->format('H:i') !== $reminderTime) {
			continue;
		}
		if ($reminder['start_date'] !== null && $localDate < $reminder['start_date']) {
			continue;
		}
		if ($reminder['end_date'] !== null && $localDate > $reminder['end_date']) {
			continue;
		}

		$repeatType = $reminder['repeat_type'];
		$repeatDays = $reminder['repeat_days'] === null ? [] : json_decode($reminder['repeat_days'], true, 512, JSON_THROW_ON_ERROR);
		$weekday = (int) $localNow->format('w');
		$due = $repeatType === 'daily';
		if ($repeatType === 'once') {
			$due = $reminder['start_date'] === null || $localDate === $reminder['start_date'];
		} elseif (in_array($repeatType, ['weekly', 'custom'], true)) {
			$due = in_array($weekday, $repeatDays, true);
		}
		if (!$due) {
			continue;
		}

		$occurrenceKey = implode('|', [(int) $reminder['id'], $localDate, $reminderTime, $reminder['user_timezone']]);
		if (NotificationService::createReminderNotification($database, $reminder, $occurrenceKey)) {
			$processed++;
			if ($repeatType === 'once') {
				$disable = $database->prepare('UPDATE reminders SET is_enabled = 0 WHERE id = :id AND user_id = :user_id');
				$disable->execute(['id' => (int) $reminder['id'], 'user_id' => (int) $reminder['user_id']]);
			}
		}
	} catch (Throwable $exception) {
		error_log(sprintf('[%s] Reminder processing failed for reminder %s: %s', request_id(), $reminder['id'] ?? 'unknown', $exception->getMessage()));
	}
}

echo json_encode(['processed' => $processed], JSON_UNESCAPED_SLASHES) . PHP_EOL;
