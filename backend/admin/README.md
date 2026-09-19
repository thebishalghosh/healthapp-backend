# Admin panel

Apply `database/admin_users.sql` to the existing `health_app` database, then create a local administrator from the backend directory:

```text
php admin/create_admin.php admin@example.com "Administrator"
```

The script prompts for the password and stores only a `password_hash`. Do not put a production password in source control.

Local URLs:

- `http://localhost/health-app/backend/admin/`
- `http://localhost/health-app/backend/admin/login.php`