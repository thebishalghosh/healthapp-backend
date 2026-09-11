<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
$timezone = $_GET['timezone'] ?? HealthTrackingService::TIMEZONE;
if (!HealthTrackingService::validTimezone($timezone)) {
	response_validation_error(['timezone' => 'Timezone must be a valid IANA timezone identifier.']);
}

$localTimezone = new DateTimeZone($timezone);
$utcTimezone = new DateTimeZone(HealthTrackingService::TIMEZONE);
$localToday = new DateTimeImmutable('today', $localTimezone);
$today = $localToday->format('Y-m-d');

$activityStatement = $database->prepare(
	"SELECT consumed_at AS activity_at FROM meal_logs WHERE user_id = :user_id
	 UNION ALL
	 SELECT consumed_at AS activity_at FROM water_logs WHERE user_id = :water_user_id
	 UNION ALL
	 SELECT workout_date AS activity_at FROM workout_logs WHERE user_id = :workout_user_id
	 UNION ALL
	 SELECT sleep_start AS activity_at FROM sleep_logs WHERE user_id = :sleep_user_id"
);
$activityStatement->execute([
	'user_id' => $userId,
	'water_user_id' => $userId,
	'workout_user_id' => $userId,
	'sleep_user_id' => $userId,
]);
$completedDates = [];
while ($activity = $activityStatement->fetch()) {
	$timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $activity['activity_at'], $utcTimezone);
	if ($timestamp === false) {
		continue;
	}
	$localDate = $timestamp->setTimezone($localTimezone)->format('Y-m-d');
	if ($localDate <= $today) {
		$completedDates[$localDate] = true;
	}
}

$currentStreak = 0;
$cursor = $localToday;
while (isset($completedDates[$cursor->format('Y-m-d')])) {
	$currentStreak++;
	$cursor = $cursor->modify('-1 day');
}

$longestStreak = 0;
$run = 0;
$sortedDates = array_keys($completedDates);
sort($sortedDates);
$previousDate = null;
foreach ($sortedDates as $date) {
	if ($previousDate !== null) {
		$expectedDate = (new DateTimeImmutable($previousDate, $localTimezone))->modify('+1 day')->format('Y-m-d');
		if ($date !== $expectedDate) {
			$run = 0;
		}
	}
	$run++;
	$longestStreak = max($longestStreak, $run);
	$previousDate = $date;
}

$weekStart = $localToday->modify('monday this week');
$weekDays = [];
$weeklyCompleted = 0;
for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
	$day = $weekStart->modify('+' . $dayOffset . ' days');
	$date = $day->format('Y-m-d');
	$completed = isset($completedDates[$date]);
	if ($completed) {
		$weeklyCompleted++;
	}
	$weekDays[] = [
		'date' => $date,
		'completed' => $completed,
	];
}

$achievementDefinitions = [
	'THREE_DAY_STREAK' => ['title' => '3 Day Streak', 'description' => 'Maintain a 3 day health streak', 'required_days' => 3],
	'SEVEN_DAY_STREAK' => ['title' => '7 Day Streak', 'description' => 'Maintain a 7 day health streak', 'required_days' => 7],
	'FOURTEEN_DAY_STREAK' => ['title' => '14 Day Streak', 'description' => 'Maintain a 14 day health streak', 'required_days' => 14],
	'THIRTY_DAY_STREAK' => ['title' => '30 Day Streak', 'description' => 'Maintain a 30 day health streak', 'required_days' => 30],
];
$achievementStatement = $database->prepare(
	"SELECT a.id, a.code, a.title, a.description, a.requirement_value, ua.achieved_at
	 FROM achievements a
	 LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = :user_id
	 WHERE a.category = 'streak' AND a.is_active = TRUE
	 ORDER BY a.requirement_value ASC, a.id ASC"
);
$achievementStatement->execute(['user_id' => $userId]);
$achievements = [];
$seenCodes = [];
while ($achievement = $achievementStatement->fetch()) {
	$code = (string) $achievement['code'];
	$requiredDays = (int) $achievement['requirement_value'];
	$seenCodes[$code] = true;
	$achievements[] = [
		'code' => $code,
		'title' => $achievement['title'],
		'description' => $achievement['description'],
		'required_days' => $requiredDays,
		'unlocked' => $longestStreak >= $requiredDays,
		'achieved_at' => $achievement['achieved_at'],
	];
}
foreach ($achievementDefinitions as $code => $definition) {
	if (isset($seenCodes[$code])) {
		continue;
	}
	$achievements[] = [
		'code' => $code,
		'title' => $definition['title'],
		'description' => $definition['description'],
		'required_days' => $definition['required_days'],
		'unlocked' => $longestStreak >= $definition['required_days'],
		'achieved_at' => null,
	];
}
usort($achievements, static fn (array $left, array $right): int => $left['required_days'] <=> $right['required_days']);

response_success([
	'current_streak' => $currentStreak,
	'longest_streak' => $longestStreak,
	'today_completed' => isset($completedDates[$today]),
	'weekly_completed' => $weeklyCompleted,
	'weekly_target' => 7,
	'week_days' => $weekDays,
	'achievements' => $achievements,
], 'Health streak retrieved.');
