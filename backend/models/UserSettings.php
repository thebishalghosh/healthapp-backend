<?php

declare(strict_types=1);

final class UserSettings
{
        public static function get(PDO $database, int $userId): array
        {
                $statement = $database->prepare(
                        'INSERT INTO user_settings (user_id) VALUES (:user_id) ON DUPLICATE KEY UPDATE user_id = user_id'
                );
                $statement->execute(['user_id' => $userId]);

                $statement = $database->prepare(
                        'SELECT language, theme, timezone, notification_enabled, email_notification_enabled, push_notification_enabled FROM user_settings WHERE user_id = :user_id LIMIT 1'
                );
                $statement->execute(['user_id' => $userId]);
                $settings = $statement->fetch();

                if (!$settings) {
                        throw new RuntimeException('User settings could not be loaded.');
                }

                $settings['notification_enabled'] = (bool) $settings['notification_enabled'];
                $settings['email_notification_enabled'] = (bool) $settings['email_notification_enabled'];
                $settings['push_notification_enabled'] = (bool) $settings['push_notification_enabled'];
                return $settings;
        }

        public static function validate(array $data): array
        {
                $fields = [];
                if (array_key_exists('timezone', $data) && (!is_string($data['timezone']) || !in_array($data['timezone'], DateTimeZone::listIdentifiers(), true))) {
                        $fields['timezone'] = 'Timezone must be a valid IANA timezone identifier.';
                }
                foreach (['notification_enabled', 'email_notification_enabled', 'push_notification_enabled'] as $field) {
                        if (array_key_exists($field, $data) && !is_bool($data[$field]) && !in_array($data[$field], [0, 1, '0', '1'], true)) {
                                $fields[$field] = 'Setting must be a boolean.';
                        }
                }
                return $fields;
        }

        public static function update(PDO $database, int $userId, array $data): array
        {
                self::get($database, $userId);
                $allowed = ['timezone', 'notification_enabled', 'email_notification_enabled', 'push_notification_enabled'];
                $assignments = [];
                $parameters = ['user_id' => $userId];
                foreach ($allowed as $field) {
                        if (array_key_exists($field, $data)) {
                                $assignments[] = $field . ' = :' . $field;
                                $parameters[$field] = in_array($data[$field], [true, 1, '1'], true) ? 1 : ($field === 'timezone' ? $data[$field] : 0);
                        }
                }
                if ($assignments !== []) {
                        $statement = $database->prepare('UPDATE user_settings SET ' . implode(', ', $assignments) . ' WHERE user_id = :user_id');
                        $statement->execute($parameters);
                }
                return self::get($database, $userId);
        }
}
