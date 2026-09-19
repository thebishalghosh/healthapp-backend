<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin();
require_once __DIR__ . '/includes/helpers.php';

$database = database_connection();
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$where = 'u.deleted_at IS NULL';
$params = [];
if ($search !== '') {
	$where .= ' AND (u.email LIKE :search OR up.first_name LIKE :search OR up.last_name LIKE :search)';
	$params['search'] = '%' . $search . '%';
}

$countStatement = $database->prepare("SELECT COUNT(*) FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE $where");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$statement = $database->prepare("SELECT u.id, u.uuid, u.email, u.created_at, u.status, up.first_name, up.last_name,
	p.name AS plan_name, s.status AS subscription_status, s.current_period_end
	FROM users u
	LEFT JOIN user_profiles up ON up.user_id = u.id
	LEFT JOIN subscriptions s ON s.id = (SELECT s2.id FROM subscriptions s2 WHERE s2.user_id = u.id ORDER BY CASE WHEN s2.status = 'active' THEN 0 ELSE 1 END, s2.created_at DESC, s2.id DESC LIMIT 1)
	LEFT JOIN subscription_plans p ON p.id = s.plan_id
	WHERE $where ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset");
foreach ($params as $key => $value) {
	$statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$users = $statement->fetchAll();

$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h1 class="h3 mb-1">Users</h1><p class="text-muted mb-0"><?= e($total) ?> registered users</p></div></div>
<form class="row g-2 mb-3" method="get"><div class="col-sm-8 col-md-5"><label class="visually-hidden" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Search name or email"></div><div class="col-auto"><button class="btn btn-primary" type="submit">Search</button></div><?php if ($search !== ''): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="users.php">Clear</a></div><?php endif; ?></form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>ID</th><th>User</th><th>Email</th><th>Created</th><th>Plan</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?= e($user['id']) ?></td><td><?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '-') ?><div class="small text-muted"><?= e($user['uuid']) ?></div></td><td><?= e($user['email']) ?></td><td><?= adminDate($user['created_at']) ?></td><td><?= e($user['plan_name'] ?? 'Free / none') ?><div class="small text-muted"><?= adminDate($user['current_period_end'] ?? null) ?></div></td><td><?php if ($user['subscription_status']): ?><span class="badge text-bg-<?= e(statusClass($user['subscription_status'])) ?>"><?= e(ucfirst($user['subscription_status'])) ?></span><?php else: ?>-<?php endif; ?></td><td><a class="btn btn-sm btn-outline-primary" href="user.php?id=<?= e($user['id']) ?>">View</a></td></tr><?php endforeach; ?>
<?php if ($users === []): ?><tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($totalPages > 1): ?><nav class="mt-3" aria-label="Users pages"><ul class="pagination"><?php for ($number = 1; $number <= $totalPages; $number++): ?><li class="page-item <?= $number === $page ? 'active' : '' ?>"><a class="page-link" href="?q=<?= urlencode($search) ?>&page=<?= $number ?>"><?= $number ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>