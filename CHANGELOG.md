# Changelog

All notable changes to this project are documented here.

## [Unreleased]

Reserved for upcoming development.

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
