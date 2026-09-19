<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin();
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/stats.php';

$database = database_connection();
$stats = getDashboardStats($database);

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
	<div><h1 class="h3 mb-1">Dashboard</h1><p class="text-muted mb-0">Read-only overview of the health app.</p></div>
</div>
<div class="row g-3">
<?php foreach ([
	['Total registered users', $stats['total_users'], 'primary'],
	['Active user accounts', $stats['active_users'], 'success'],
	['Active Personal subscribers', $stats['active_personal_subscribers'], 'info'],
	['Active Premium subscribers', $stats['active_premium_subscribers'], 'warning'],
	['Active current subscriptions', $stats['active_subscriptions'], 'success'],
	['Cancelled subscription records', $stats['cancelled_subscriptions'], 'secondary'],
] as [$label, $value, $color]): ?>
	<div class="col-12 col-sm-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small"><?= e($label) ?></div><div class="display-6 fw-semibold text-<?= e($color) ?>"><?= e($value) ?></div></div></div></div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>