# AI Health & Wellness Backend

## Project Overview

This repository contains the Core PHP REST API for the AI Health & Wellness mobile application. The React Native frontend is maintained in a separate project.

## Technology Stack

- PHP 8.2+
- Core PHP REST API
- MySQL 8+
- PDO
- Apache/Laragon for local development
- Composer for dependency management and autoloading

## Backend Structure

```text
backend/
	api/v1/       API endpoint handlers
	config/       environment and database configuration
	core/         authentication, responses, and security helpers
	services/     business logic
	models/       reserved data model layer
	bootstrap.php shared request initialization
	index.php     API front controller
database/
	schema.sql    canonical MySQL schema
```

## Requirements

- PHP 8.2 or newer with PDO MySQL enabled
- MySQL 8 or compatible MariaDB setup
- Apache with URL rewriting enabled
- Composer 2+

## Local Setup

1. Place the project in the Laragon web root.
2. Copy `backend/.env.example` to `backend/.env`.
3. Set the local database name, username, password, and CORS origin in `backend/.env`.
4. Run `composer install` from `backend/`.
5. Import `database/schema.sql` into the configured MySQL database. The schema is not run automatically by the API.

## Environment Configuration

The backend reads configuration from `backend/.env`. It supports application settings, MySQL connection settings, API version, and comma-separated CORS origins. Never commit `backend/.env` or real credentials.

## Database Setup

The canonical schema is `database/schema.sql`. The file `backend/database/schema.sql` is an empty legacy placeholder and must not be treated as a second schema.

## Running Locally

With Laragon Apache running, use:

```text
http://localhost/health-app/backend/api/v1
```

## API Base URL

```text
http://localhost/health-app/backend/api/v1
```

## Authentication Endpoints

- `POST /auth/register`
- `POST /auth/login`
- `GET /auth/me`
- `POST /auth/logout`

Authentication uses bearer tokens. The raw token is returned only at login; only its SHA-256 hash is stored in `user_sessions`.

## Health Profile Endpoints

- `GET /health/profile`
- `PUT /health/profile`

Supported fields are `first_name`, `last_name`, `date_of_birth`, `gender`, `height_cm`, `weight_kg`, `activity_level`, `fitness_goal`, and `dietary_preference`.

## Nutrition Endpoints

- `GET /health/nutrition`
- `POST /health/nutrition/calculate`

Nutrition calculation uses the authenticated user's saved health profile and stores historical results in `nutrition_requirements`.

## Authentication Flow

Register or log in to receive an access token. Send it on protected requests as:

```http
Authorization: Bearer <access-token>
```

The server resolves the user from the token and never trusts a client-supplied user ID.

## Nutrition Calculation Methodology

Nutrition Requirements v1 uses the Mifflin-St Jeor equation, activity multipliers, and fitness-goal calorie adjustments. It calculates estimated calories, protein, carbohydrates, fat, fiber, and water. Age is derived from `date_of_birth` and is not stored separately. The supported calculation genders are `male` and `female`.

These values are estimates and are not medical advice.

## Current Implementation Status

Implemented and verified:

- Authentication foundation
- Health profile foundation
- Nutrition Requirements v1
- JSON responses, centralized errors, CORS, PDO, and Apache front-controller routing

Meal planning, food scanning, AI, reminders, goals, subscriptions, and other product features are planned and not implemented in this checkpoint.

## Security Notes

- Passwords use `password_hash()` and `password_verify()`.
- Database access uses PDO prepared statements.
- Authentication and profile/nutrition operations are scoped to the server-resolved user.
- Password hashes, token hashes, credentials, and stack traces are not returned by the API.
- Keep `.env`, logs, uploads, dependency directories, and local database dumps out of source control.
