<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin();
require_once __DIR__ . '/includes/helpers.php';

$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false || $userId === null) {
	header('Location: users.php');
	exit;
}

$statement = database_connection()->prepare("SELECT u.id, u.uuid, u.email, u.created_at, up.first_name, up.last_name, up.date_of_birth, up.gender, up.height_cm, up.weight_kg, up.activity_level, up.fitness_goal,
	p.name AS plan_name, s.status AS subscription_status, s.started_at, s.current_period_start, s.current_period_end, s.cancelled_at
	FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id
	LEFT JOIN subscriptions s ON s.id = (SELECT s2.id FROM subscriptions s2 WHERE s2.user_id = u.id ORDER BY CASE WHEN s2.status = 'active' THEN 0 ELSE 1 END, s2.created_at DESC, s2.id DESC LIMIT 1)
	LEFT JOIN subscription_plans p ON p.id = s.plan_id WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1");
$statement->execute(['id' => $userId]);
$user = $statement->fetch();
if (!$user) {
	http_response_code(404);
	exit('User not found.');
}

$pageTitle = 'User details';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><a href="users.php" class="text-decoration-none">&larr; Users</a><h1 class="h3 mt-2 mb-0"><?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['email']) ?></h1></div></div>
<div class="row g-4"><div class="col-12 col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5 mb-3">Profile</h2><dl class="row mb-0"><?php foreach ([['Email', $user['email']], ['UUID', $user['uuid']], ['Date of birth', $user['date_of_birth']], ['Gender', $user['gender']], ['Height (cm)', $user['height_cm']], ['Weight (kg)', $user['weight_kg']], ['Activity level', $user['activity_level']], ['Fitness goal', $user['fitness_goal']], ['Created', adminDate($user['created_at'])]] as [$label, $value]): ?><dt class="col-sm-5"><?= e($label) ?></dt><dd class="col-sm-7"><?= e($value ?: '-') ?></dd><?php endforeach; ?></dl></div></div></div>
<div class="col-12 col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5 mb-3">Current subscription</h2><?php if ($user['plan_name']): ?><dl class="row mb-0"><dt class="col-sm-5">Plan</dt><dd class="col-sm-7"><?= e($user['plan_name']) ?></dd><dt class="col-sm-5">Status</dt><dd class="col-sm-7"><span class="badge text-bg-<?= e(statusClass($user['subscription_status'])) ?>"><?= e(ucfirst($user['subscription_status'])) ?></span></dd><dt class="col-sm-5">Started</dt><dd class="col-sm-7"><?= adminDate($user['started_at']) ?></dd><dt class="col-sm-5">Period start</dt><dd class="col-sm-7"><?= adminDate($user['current_period_start']) ?></dd><dt class="col-sm-5">Period end</dt><dd class="col-sm-7"><?= adminDate($user['current_period_end']) ?></dd><dt class="col-sm-5">Cancelled</dt><dd class="col-sm-7"><?= adminDate($user['cancelled_at']) ?></dd></dl><?php else: ?><p class="text-muted mb-0">No subscription record.</p><?php endif; ?></div></div></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>