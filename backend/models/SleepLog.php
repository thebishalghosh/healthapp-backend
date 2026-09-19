<?php

declare(strict_types=1);

final class SleepLog
{
        public static function timezone(PDO $database, int $userId): DateTimeZone
        {
                $statement = $database->prepare('SELECT timezone FROM user_settings WHERE user_id = :user_id LIMIT 1');
                $statement->execute(['user_id' => $userId]);
                $timezone = $statement->fetchColumn() ?: HealthTrackingService::TIMEZONE;
                return HealthTrackingService::validTimezone($timezone) ? new DateTimeZone($timezone) : new DateTimeZone(HealthTrackingService::TIMEZONE);
        }

        public static function validate(array $data, DateTimeZone $timezone): array
        {
                $fields = [];
                $sleepDate = $data['sleep_date'] ?? null;
                $bedtime = $data['bedtime'] ?? null;
                $wakeTime = $data['wake_time'] ?? null;
                $today = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');
                if (!HealthTrackingService::validDate($sleepDate)) {
                        $fields['sleep_date'] = 'Sleep date must be a valid YYYY-MM-DD date.';
                } elseif ($sleepDate > $today) {
                        $fields['sleep_date'] = 'Sleep date cannot be in the future.';
                }
                if (!HealthTrackingService::validTime($bedtime)) {
                        $fields['bedtime'] = 'Bedtime must be a valid HH:MM or HH:MM:SS time.';
                }
                if (!HealthTrackingService::validTime($wakeTime)) {
                        $fields['wake_time'] = 'Wake time must be a valid HH:MM or HH:MM:SS time.';
                }
                return $fields;
        }

        public static function interval(array $data, DateTimeZone $timezone): array
        {
                $start = new DateTimeImmutable($data['sleep_date'] . ' ' . HealthTrackingService::normalizeTime($data['bedtime']), $timezone);
                $end = new DateTimeImmutable($data['sleep_date'] . ' ' . HealthTrackingService::normalizeTime($data['wake_time']), $timezone);
                if ($end <= $start) {
                        $end = $end->modify('+1 day');
                }
                $duration = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
                if ($duration < 1 || $duration > 1440) {
                        throw new InvalidArgumentException('Bedtime and wake time must describe a duration between 1 and 1440 minutes.');
                }
                $utc = new DateTimeZone('UTC');
                return [$start->setTimezone($utc), $end->setTimezone($utc), $duration];
        }

        public static function create(PDO $database, int $userId, array $data, DateTimeZone $timezone): array
        {
                [$start, $end, $duration] = self::interval($data, $timezone);
                $statement = $database->prepare('INSERT INTO sleep_logs (user_id, sleep_start, sleep_end, duration_minutes) VALUES (:user_id, :sleep_start, :sleep_end, :duration_minutes)');
                $statement->execute([
                        'user_id' => $userId,
                        'sleep_start' => $start->format('Y-m-d H:i:s'),
                        'sleep_end' => $end->format('Y-m-d H:i:s'),
                        'duration_minutes' => $duration,
                ]);
                return self::findOwned($database, $userId, (int) $database->lastInsertId(), $timezone);
        }

        public static function update(PDO $database, int $userId, int $id, array $data, DateTimeZone $timezone): ?array
        {
                [$start, $end, $duration] = self::interval($data, $timezone);
                $statement = $database->prepare('UPDATE sleep_logs SET sleep_start = :sleep_start, sleep_end = :sleep_end, duration_minutes = :duration_minutes WHERE id = :id AND user_id = :user_id');
                $statement->execute([
                        'sleep_start' => $start->format('Y-m-d H:i:s'),
                        'sleep_end' => $end->format('Y-m-d H:i:s'),
                        'duration_minutes' => $duration,
                        'id' => $id,
                        'user_id' => $userId,
                ]);
                return self::findOwned($database, $userId, $id, $timezone);
        }

        public static function delete(PDO $database, int $userId, int $id): bool
        {
                $statement = $database->prepare('DELETE FROM sleep_logs WHERE id = :id AND user_id = :user_id');
                $statement->execute(['id' => $id, 'user_id' => $userId]);
                return $statement->rowCount() > 0;
        }

        public static function today(PDO $database, int $userId, DateTimeZone $timezone): ?array
        {
                $today = new DateTimeImmutable('today', $timezone);
                return self::findInRange($database, $userId, $today, $today->modify('+1 day'), $timezone);
        }

        public static function history(PDO $database, int $userId, DateTimeZone $timezone, ?string $date = null): array
        {
                $parameters = ['user_id' => $userId];
                $where = 'WHERE user_id = :user_id';
                if ($date !== null) {
                        $start = new DateTimeImmutable($date . ' 00:00:00', $timezone);
                        $end = $start->modify('+1 day');
                        $where .= ' AND sleep_start >= :start AND sleep_start < :end';
                        $parameters['start'] = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                        $parameters['end'] = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
                $statement = $database->prepare('SELECT id, sleep_start, sleep_end, duration_minutes FROM sleep_logs ' . $where . ' ORDER BY sleep_start DESC, id DESC LIMIT 50');
                $statement->execute($parameters);
                $logs = [];
                while ($log = $statement->fetch()) {
                        $logs[] = self::serialize($log, $timezone);
                }
                return $logs;
        }

        private static function findInRange(PDO $database, int $userId, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $timezone): ?array
        {
                $statement = $database->prepare('SELECT id, sleep_start, sleep_end, duration_minutes FROM sleep_logs WHERE user_id = :user_id AND sleep_start >= :start AND sleep_start < :end ORDER BY sleep_start DESC, id DESC LIMIT 1');
                $utc = new DateTimeZone('UTC');
                $statement->execute([
                        'user_id' => $userId,
                        'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
                ]);
                $log = $statement->fetch();
                return $log ? self::serialize($log, $timezone) : null;
        }

        private static function findOwned(PDO $database, int $userId, int $id, DateTimeZone $timezone): ?array
        {
                $statement = $database->prepare('SELECT id, sleep_start, sleep_end, duration_minutes FROM sleep_logs WHERE id = :id AND user_id = :user_id LIMIT 1');
                $statement->execute(['id' => $id, 'user_id' => $userId]);
                $log = $statement->fetch();
                return $log ? self::serialize($log, $timezone) : null;
        }

        private static function serialize(array $log, DateTimeZone $timezone): array
        {
                $utc = new DateTimeZone('UTC');
                $start = (new DateTimeImmutable($log['sleep_start'], $utc))->setTimezone($timezone);
                $end = (new DateTimeImmutable($log['sleep_end'], $utc))->setTimezone($timezone);
                return [
                        'id' => (int) $log['id'],
                        'sleep_date' => $start->format('Y-m-d'),
                        'bedtime' => $start->format('H:i:s'),
                        'wake_time' => $end->format('H:i:s'),
                        'duration_minutes' => (int) $log['duration_minutes'],
                ];
        }
}
