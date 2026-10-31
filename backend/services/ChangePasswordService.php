<?php

declare(strict_types=1);

final class ChangePasswordValidationException extends InvalidArgumentException
{
	public function __construct(public readonly array $fields)
	{
		parent::__construct('Password change input is invalid.');
	}
}

final class ChangePasswordInvalidCurrentPasswordException extends RuntimeException
{
}

final class ChangePasswordSamePasswordException extends RuntimeException
{
}

final class ChangePasswordUnauthenticatedException extends RuntimeException
{
}

final class ChangePasswordService
{
	public static function validate(array $body): array
	{
		$fields = [];
		$currentPassword = $body['current_password'] ?? null;
		$newPassword = $body['new_password'] ?? null;
		$confirmPassword = $body['confirm_password'] ?? null;

		foreach ([
			'current_password' => $currentPassword,
			'new_password' => $newPassword,
			'confirm_password' => $confirmPassword,
		] as $field => $password) {
			if (!is_string($password) || $password === '') {
				$fields[$field] = 'This field is required.';
			} elseif (strlen($password) > 72) {
				$fields[$field] = 'Password must be no more than 72 bytes.';
			}
		}

		if (is_string($newPassword) && $newPassword !== '' && strlen($newPassword) <= 72) {
			$characterCount = preg_match_all('/./us', $newPassword);
			if ($characterCount === false || $characterCount < 8) {
				$fields['new_password'] = 'Password must be at least 8 characters.';
			}
		}

		if (is_string($newPassword) && is_string($confirmPassword) && $newPassword !== $confirmPassword) {
			$fields['confirm_password'] = 'Password confirmation does not match.';
		}

		if ($fields !== []) {
			throw new ChangePasswordValidationException($fields);
		}

		return [$currentPassword, $newPassword];
	}

	public static function change(PDO $database, int $userId, string $currentPassword, string $newPassword): void
	{
		$database->beginTransaction();

		try {
			$statement = $database->prepare(
				"SELECT password_hash FROM users
				 WHERE id = :user_id AND status = 'active' AND deleted_at IS NULL
				 FOR UPDATE"
			);
			$statement->execute(['user_id' => $userId]);
			$passwordHash = $statement->fetchColumn();

			if (!is_string($passwordHash)) {
				throw new ChangePasswordUnauthenticatedException('Authenticated account is no longer available.');
			}

			if (!password_verify($currentPassword, $passwordHash)) {
				throw new ChangePasswordInvalidCurrentPasswordException('Current password is incorrect.');
			}

			if (hash_equals($currentPassword, $newPassword)) {
				throw new ChangePasswordSamePasswordException('New password must differ from the current password.');
			}

			$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
			$update = $database->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
			$update->execute([
				'password_hash' => $newPasswordHash,
				'user_id' => $userId,
			]);

			$invalidateSessions = $database->prepare('DELETE FROM user_sessions WHERE user_id = :user_id');
			$invalidateSessions->execute(['user_id' => $userId]);
			$database->commit();
		} catch (Throwable $exception) {
			if ($database->inTransaction()) {
				$database->rollBack();
			}

			throw $exception;
		}
	}
}
