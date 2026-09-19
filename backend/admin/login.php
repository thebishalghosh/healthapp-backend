<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (isAdmin()) {
	header('Location: index.php');
	exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
	$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

	if (filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '' && adminLogin($email, $password)) {
		header('Location: index.php');
		exit;
	}

	$error = 'Invalid email or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Admin Login - Health App</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark d-flex align-items-center min-vh-100">
	<div class="container">
		<div class="row justify-content-center">
			<div class="col-12 col-sm-9 col-md-6 col-lg-4">
				<div class="card shadow-sm border-0">
					<div class="card-body p-4">
						<h1 class="h4 mb-4">Administrator login</h1>
						<?php if ($error !== null): ?>
							<div class="alert alert-danger" role="alert"><?= e($error) ?></div>
						<?php endif; ?>
						<form method="post" novalidate>
							<div class="mb-3">
								<label class="form-label" for="email">Email</label>
								<input class="form-control" id="email" name="email" type="email" autocomplete="username" required value="<?= e($_POST['email'] ?? '') ?>">
							</div>
							<div class="mb-4">
								<label class="form-label" for="password">Password</label>
								<input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
							</div>
							<button class="btn btn-primary w-100" type="submit">Login</button>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
</body>
</html>