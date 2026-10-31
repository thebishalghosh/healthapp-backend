<?php

declare(strict_types=1);

final class AccountDeactivationValidationException extends InvalidArgumentException
{
	public function __construct(public readonly array $fields)
	{
		parent::__construct('Account deactivation request is invalid.');
	}
}

final class AccountDeactivationInvalidPasswordException extends RuntimeException
{
}

final class AccountDeactivationFailureException extends RuntimeException
{
}

final class AccountDeactivationService
{
	public const CONFIRMATION = 'DEACTIVATE MY ACCOUNT';

	public static function validate(array $body): string
	{
		$fields = [];
		foreach ($body as $field => $_value) {
			if (!in_array($field, ['current_password', 'confirmation'], true)) {
				$fields[(string) $field] = 'This field is not supported.';
			}
		}

		$password = $body['current_password'] ?? null;
		if (!is_string($password) || $password === '') {
			$fields['current_password'] = 'This field is required.';
		} elseif (strlen($password) > 72) {
			$fields['current_password'] = 'Password must be no more than 72 bytes.';
		}

		if (!is_string($body['confirmation'] ?? null) || $body['confirmation'] !== self::CONFIRMATION) {
			$fields['confirmation'] = 'Enter the required account deactivation confirmation exactly.';
		}

		if ($fields !== []) {
			throw new AccountDeactivationValidationException($fields);
		}

		return $password;
	}

	public static function deactivate(PDO $database, int $userId, string $currentPassword): void
	{
		$database->beginTransaction();
		try {
			$lockUser = $database->prepare(
				"SELECT password_hash FROM users
				 WHERE id = :user_id AND status = 'active' AND deleted_at IS NULL
				 FOR UPDATE"
			);
			$lockUser->execute(['user_id' => $userId]);
			$user = $lockUser->fetch(PDO::FETCH_ASSOC);

			if (!$user) {
				throw new AccountDeactivationFailureException('The authenticated account is no longer active.');
			}
			if (!password_verify($currentPassword, $user['password_hash'])) {
				throw new AccountDeactivationInvalidPasswordException('Current password is incorrect.');
			}

			$deactivateUser = $database->prepare(
				"UPDATE users SET status = 'inactive' WHERE id = :user_id AND status = 'active' AND deleted_at IS NULL"
			);
			$deactivateUser->execute(['user_id' => $userId]);
			if ($deactivateUser->rowCount() !== 1) {
				throw new AccountDeactivationFailureException('The authenticated account could not be deactivated.');
			}

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
