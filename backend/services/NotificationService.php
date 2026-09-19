<?php

declare(strict_types=1);

final class NotificationService
{
	public static function list(PDO $database, int $userId, int $limit = 50): array
	{
		$limit = max(1, min($limit, 100));
		$statement = $database->prepare('SELECT id, notification_type, title, message, data, is_read, sent_at, read_at, created_at FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
		$statement->execute(['user_id' => $userId]);
		$notifications = [];
		while ($notification = $statement->fetch()) {
			$notification['id'] = (int) $notification['id'];
			$notification['is_read'] = (bool) $notification['is_read'];
			$notification['data'] = $notification['data'] === null ? null : json_decode($notification['data'], true);
			$notifications[] = $notification;
		}
		return $notifications;
	}

	public static function markRead(PDO $database, int $userId, int $notificationId): bool
	{
		$statement = $database->prepare('UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE id = :id AND user_id = :user_id');
		$statement->execute(['id' => $notificationId, 'user_id' => $userId]);
		return $statement->rowCount() > 0;
	}

	public static function createReminderNotification(PDO $database, array $reminder, string $occurrenceKey): bool
	{
		$userId = (int) $reminder['user_id'];
		$lockName = 'health-reminder-' . $userId;
		$lock = $database->prepare('SELECT GET_LOCK(:lock_name, 0)');
		$lock->execute(['lock_name' => $lockName]);
		if ((int) $lock->fetchColumn() !== 1) {
			return false;
		}

		try {
			$duplicate = $database->prepare("SELECT id FROM notifications WHERE user_id = :user_id AND notification_type = 'reminder' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.occurrence_key')) = :occurrence_key LIMIT 1");
			$duplicate->execute(['user_id' => $userId, 'occurrence_key' => $occurrenceKey]);
			if ($duplicate->fetchColumn() !== false) {
				return false;
			}

			$data = json_encode([
				'reminder_id' => (int) $reminder['id'],
				'reminder_type' => $reminder['reminder_type'],
				'occurrence_key' => $occurrenceKey,
			], JSON_THROW_ON_ERROR);
			$statement = $database->prepare('INSERT INTO notifications (user_id, notification_type, title, message, data, sent_at) VALUES (:user_id, :notification_type, :title, :message, :data, UTC_TIMESTAMP())');
			$statement->execute([
				'user_id' => $userId,
				'notification_type' => 'reminder',
				'title' => $reminder['title'],
				'message' => $reminder['message'] ?: 'It is time for your health reminder.',
				'data' => $data,
			]);
			return true;
		} finally {
			$release = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
			$release->execute(['lock_name' => $lockName]);
		}
	}
}
