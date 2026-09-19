<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
$pageTitle = $pageTitle ?? 'Admin Panel';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= e($pageTitle) ?> - Health App Admin</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark navbar-expand-lg">
	<div class="container-fluid">
		<a class="navbar-brand fw-semibold" href="index.php">Health App Admin</a>
		<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="Toggle navigation">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="adminNav">
			<ul class="navbar-nav me-auto mb-2 mb-lg-0">
				<li class="nav-item"><a class="nav-link <?= currentPage('index.php') ? 'active' : '' ?>" href="index.php">Dashboard</a></li>
				<li class="nav-item"><a class="nav-link <?= currentPage('users.php') || currentPage('user.php') ? 'active' : '' ?>" href="users.php">Users</a></li>
				<li class="nav-item"><a class="nav-link <?= currentPage('subscriptions.php') ? 'active' : '' ?>" href="subscriptions.php">Subscriptions</a></li>
			</ul>
			<span class="navbar-text me-3"><?= e($_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Administrator') ?></span>
			<a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
		</div>
	</div>
</nav>
<main class="container-fluid py-4">