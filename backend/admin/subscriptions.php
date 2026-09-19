<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin();
require_once __DIR__ . '/includes/helpers.php';

$planFilter = is_string($_GET['plan'] ?? null) ? strtoupper(trim($_GET['plan'])) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? strtolower(trim($_GET['status'])) : '';
$plans = ['PERSONAL', 'PREMIUM'];
$statuses = ['active', 'cancelled'];
$conditions = [];
$params = [];
if (in_array($planFilter, $plans, true)) { $conditions[] = 'p.code = :plan'; $params['plan'] = $planFilter; } else { $planFilter = ''; }
if (in_array($statusFilter, $statuses, true)) { $conditions[] = 's.status = :status'; $params['status'] = $statusFilter; } else { $statusFilter = ''; }
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$statement = database_connection()->prepare("SELECT s.id, s.provider_subscription_id, s.status, s.started_at, s.current_period_start, s.current_period_end, s.cancelled_at, s.created_at, p.code AS plan_code, p.name AS plan_name, u.email, up.first_name, up.last_name
	FROM subscriptions s INNER JOIN subscription_plans p ON p.id = s.plan_id INNER JOIN users u ON u.id = s.user_id LEFT JOIN user_profiles up ON up.user_id = u.id $where ORDER BY s.created_at DESC, s.id DESC");
$statement->execute($params);
$subscriptions = $statement->fetchAll();

$pageTitle = 'Subscriptions';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-4"><h1 class="h3 mb-1">Subscriptions</h1><p class="text-muted mb-0">Read-only subscription records from the app database.</p></div>
<form class="row g-2 mb-3" method="get"><div class="col-sm-auto"><label class="visually-hidden" for="plan">Plan</label><select class="form-select" id="plan" name="plan"><option value="">All plans</option><?php foreach ($plans as $plan): ?><option value="<?= e($plan) ?>" <?= $planFilter === $plan ? 'selected' : '' ?>><?= e(ucfirst(strtolower($plan))) ?></option><?php endforeach; ?></select></div><div class="col-sm-auto"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></div><div class="col-auto"><button class="btn btn-primary" type="submit">Filter</button></div><div class="col-auto"><a class="btn btn-outline-secondary" href="subscriptions.php">Clear</a></div></form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>User</th><th>Email</th><th>Plan</th><th>Razorpay subscription ID</th><th>Status</th><th>Start</th><th>Period</th><th>Cancelled</th><th>Created</th></tr></thead><tbody>
<?php foreach ($subscriptions as $subscription): ?><tr><td><?= e(trim(($subscription['first_name'] ?? '') . ' ' . ($subscription['last_name'] ?? '')) ?: '-') ?></td><td><?= e($subscription['email']) ?></td><td><?= e($subscription['plan_name']) ?></td><td><?= e($subscription['provider_subscription_id'] ?: '-') ?></td><td><span class="badge text-bg-<?= e(statusClass($subscription['status'])) ?>"><?= e(ucfirst($subscription['status'])) ?></span></td><td><?= adminDate($subscription['started_at']) ?></td><td><?= adminDate($subscription['current_period_start']) ?> - <?= adminDate($subscription['current_period_end']) ?></td><td><?= adminDate($subscription['cancelled_at']) ?></td><td><?= adminDate($subscription['created_at']) ?></td></tr><?php endforeach; ?>
<?php if ($subscriptions === []): ?><tr><td colspan="9" class="text-center text-muted py-4">No subscriptions found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>