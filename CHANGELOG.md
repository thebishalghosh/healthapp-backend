# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Added

#### Phase 1 Health Tracking Foundation

- Added authenticated water logging and daily water aggregation at `/api/v1/health/water`.
- Added authenticated food logging and daily meal totals at `/api/v1/health/food`.
- Added authenticated workout logging and daily workout totals at `/api/v1/health/workouts`.
- Added authenticated sleep logging and today's sleep retrieval at `/api/v1/health/sleep`.
- Added the authenticated daily summary endpoint at `/api/v1/health/today`.
- Reused the existing `water_logs`, `meal_logs`, `workout_logs`, and `sleep_logs` tables; no duplicate tracking tables were added.
- Added `snack` to the existing `meal_logs.meal_type` enum.

### Validation and Security

- Tracking POST endpoints validate JSON, required fields, numeric ranges, controlled meal types, dates, times, and durations server-side.
- All tracking data is scoped to the authenticated bearer-token user.
- Tracking timestamps and daily aggregation boundaries use explicit UTC.
- Nutrition targets are read from existing Nutrition Requirements v1 results; missing or stale nutrition is never replaced with a hardcoded target.

### Verification

- PHP syntax checks passed for the tracking service and health endpoints.
- `git diff --check` passed.
- No automated backend test directory was present; live API tests require a configured local database and authenticated test data.

### Limitations

- Nutrition calculations remain the existing Phase 1 estimates and were not changed.
- The daily summary returns null nutrition and water targets until a nutrition calculation exists.

## [2026-08-25] - Authentication, Health Profile & Nutrition Requirements v1

### Added

#### Authentication

- User registration, login, authenticated-user, and logout endpoints.
- Bearer token authentication with hashed session tokens.
- Token revocation and Apache Authorization header forwarding.

#### Health Profile

- GET and PUT health profile endpoints.
- Authenticated user profile scoping.
- Validation for profile fields, dates, measurements, activity levels, and fitness goals.

#### Nutrition Requirements

- Nutrition calculation and retrieval endpoints.
- Mifflin-St Jeor BMR calculation.
- Activity-based TDEE calculation and fitness-goal calorie adjustments.
- Protein, carbohydrate, fat, fiber, and water calculations.
- Calculation metadata, historical storage, and latest-result retrieval.

### Security

- Protected operations resolve user identity from the authenticated session.
- Prepared SQL statements are used for database operations.
- Password hashes and token hashes are excluded from API responses.

### Validation

- PHP syntax validation passed.
- Composer validation passed.
- Apache configuration validation passed.
- Authentication, profile, nutrition, unauthorized, incomplete-profile, and invalid-gender flows were tested.

### Database

- No database schema changes.
- Existing `user_profiles` and `nutrition_requirements` tables reused.

### Notes

- Nutrition calculations are estimates based on the approved v1 methodology.
- Gender-specific Mifflin-St Jeor calculation supports `male` and `female`.
- Age is derived from `date_of_birth` and is not stored separately.
- Food preferences, location, meal planning, AI, reminders, goals, and subscriptions remain planned features.
