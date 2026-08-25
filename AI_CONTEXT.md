# AI_CONTEXT.md

# AI Health & Wellness App — AI Coding Assistant Context

## 1. Purpose of This File

This file provides the complete context required for an AI coding assistant to understand, develop, modify, debug, and maintain the AI-Powered Health & Wellness mobile application.

The AI assistant must read this file before making architectural or feature-level changes.

This file should be treated as a development instruction and project-context document.

---

# 2. Project Overview

## Project Name

AI-Powered Health & Wellness App

## Project Type

Mobile application with:

- React Native frontend
- Core PHP REST API backend
- MySQL database
- AI-powered health and nutrition features

The application is designed to help users manage nutrition, meals, food choices, exercise, sleep, hydration, goals, habits, and general wellness.

The application will use AI to provide personalized recommendations based on the user's profile, body information, nutritional requirements, preferences, location, food data, and activity information.

---

# 3. Technology Stack

## Mobile Application

Framework:

- React Native

The mobile application communicates with the backend only through REST APIs.

The React Native application should NOT directly connect to MySQL.

Architecture:

React Native
    ↓
HTTPS REST API
    ↓
Core PHP Backend
    ↓
MySQL

---

## Backend

Language:

- PHP

Framework:

- Core PHP
- No Laravel dependency unless explicitly approved later

Database:

- MySQL

API:

- REST API
- JSON request/response

API version:

/api/v1/

---

## AI

AI functionality will be accessed through backend services.

The React Native application must NOT expose AI API keys.

Correct architecture:

React Native
    ↓
PHP API
    ↓
AIService.php
    ↓
AI Provider API

AI API keys must remain on the server.

---

# 4. Development Principles

The following rules must be followed:

1. Do not unnecessarily introduce a framework.
2. Keep the backend compatible with Core PHP.
3. Keep API responses consistent.
4. Keep business logic outside API endpoint files when practical.
5. Do not put database credentials in source code.
6. Do not expose API keys to React Native.
7. Do not expose .env files publicly.
8. Use prepared SQL statements.
9. Validate all user input.
10. Authenticate protected endpoints.
11. Check premium access on the backend.
12. Never trust the frontend for subscription status.
13. Never trust the frontend for user ID.
14. Never trust client-provided authorization data.
15. Use HTTPS in production.
16. Maintain API versioning.
17. Avoid breaking existing API contracts.
18. Reuse existing services and models instead of duplicating logic.
19. Do not change finalized project decisions without approval.
20. Keep the implementation suitable for a mobile application.

---

# 5. Phase 1 Scope

The approved Phase 1 features are:

## Core Features

1. Essential Nutrition Requirements
2. AI Meal Planning
3. Location-Based Food Preferences
4. AI Food Scanner
5. Smart Reminders
6. Workout Music Redirection
7. Daily Goals
8. Weekly Goals
9. Monthly Goals
10. User Streaks
11. User Achievements
12. Weekly Health Reports
13. AI Health & Wellness Chatbot
14. Subscription
15. Advertisement Revenue

---

# 6. Essential Nutrition Requirement

The application must determine the user's essential nutritional requirements based on their personal information and body-related data.

Potential user inputs include:

- Age
- Gender
- Height
- Weight
- Activity level
- Fitness goal
- Dietary preference
- Food preference
- Location
- Other approved wellness information

The system may calculate or estimate requirements such as:

- Daily calories
- Protein
- Carbohydrates
- Fat
- Fiber
- Water
- Selected vitamins/minerals where appropriate

The nutrition calculation logic must be centralized.

Recommended service:

services/NutritionService.php

The system should store the calculated nutritional targets so they can be used by:

- Meal planning
- Health dashboard
- Progress tracking
- Weekly reports
- AI recommendations

---

# 7. AI Meal Planning

The application will provide personalized meal plans using AI.

Meal planning should consider:

- User profile
- Nutritional requirements
- Fitness goal
- Dietary preference
- Food preference
- Location
- Available food preferences
- Previous meal information where available

The AI should return structured meal information.

Example:

Breakfast
- Food items
- Calories
- Protein
- Carbohydrates
- Fat

Lunch
- Food items
- Calories
- Protein
- Carbohydrates
- Fat

Snack

Dinner

The backend must validate and normalize AI responses before returning them to the mobile app.

AI meal planning should use:

services/MealService.php
services/AIService.php

---

# 8. Location-Based Food Preference

The application can use the user's location to provide food recommendations relevant to the user's region.

Examples:

- Local food preferences
- Regional meals
- Common local ingredients
- Local dietary patterns

Location data must be handled carefully.

The app should request only the location information required for this functionality.

Do not store precise location permanently unless required and explicitly approved.

Possible stored information:

- Country
- State
- City
- General region

The system should avoid unnecessary collection of exact GPS coordinates.

---

# 9. AI Food Scanner

The application will provide an AI-powered food scanner.

Users can provide a food image.

Flow:

React Native
    ↓
Food image
    ↓
PHP upload endpoint
    ↓
FoodScannerService.php
    ↓
AIService.php
    ↓
AI Vision API
    ↓
Structured food information
    ↓
React Native

The scanner may identify:

- Food name
- Estimated serving
- Calories
- Protein
- Carbohydrates
- Fat
- Fiber
- Other relevant nutritional information

AI-generated nutritional information must be presented as an estimate where appropriate.

The feature is available to ALL users.

Food scan history may be stored for authenticated users.

---

# 10. Smart Reminders

The application will support smart reminders for:

## Water

Examples:

- Water intake reminder
- Daily hydration target

## Food

Examples:

- Breakfast
- Lunch
- Dinner
- Snacks

## Workout

Examples:

- Workout reminder
- Scheduled exercise

## Sleep

Examples:

- Bedtime reminder
- Sleep schedule reminder

Users should be able to:

- Enable/disable reminders
- Set reminder times
- Modify reminder schedules
- Delete reminders

The backend stores reminder preferences.

The mobile application is responsible for displaying local notifications where appropriate.

Server-side scheduled jobs may be used where necessary.

---

# 11. Workout Music

Workout music is available to ALL users.

Important finalized decision:

Music will NOT be hosted or streamed directly by this application.

The application will redirect users to external music services/platforms.

Examples may include:

- Spotify
- Other approved music platforms

The application should use deep links or approved external URLs where possible.

Do not implement copyrighted music hosting or unauthorized streaming.

---

# 12. Goals

Users can have:

## Daily Goals

Examples:

- Water target
- Meal target
- Workout target
- Sleep target
- Nutrition target

## Weekly Goals

Examples:

- Number of workouts
- Hydration consistency
- Meal consistency

## Monthly Goals

Examples:

- Fitness consistency
- Weight-related goal
- Habit consistency

Goal completion should contribute to:

- Streaks
- Achievements
- Health reports

---

# 13. Streak System

The application will track user activity streaks.

Possible streak activities:

- Daily health activity
- Goal completion
- Water tracking
- Meal tracking
- Workout completion
- Other approved activities

The backend should calculate streaks reliably.

Do not rely only on the mobile application to calculate streaks.

Recommended service:

services/HealthReportService.php
or a dedicated StreakService if required.

---

# 14. Achievements

Users can earn achievements based on activity.

Examples:

- First goal completed
- 3-day streak
- 7-day streak
- 30-day streak
- First food scan
- First meal plan
- Workout milestones
- Hydration milestones

Achievement definitions should ideally be configurable instead of hard-coded throughout the application.

---

# 15. Weekly Health Report

The application will provide a weekly health report.

Possible information:

- Goal completion
- Water tracking
- Meal consistency
- Workout activity
- Sleep tracking
- Nutrition progress
- Streak status
- Achievements
- General wellness summary

The report should summarize the user's activity for the previous week.

Possible processing:

Cron job
    ↓
Weekly report generation
    ↓
HealthReportService.php
    ↓
Database
    ↓
React Native

AI-generated recommendations may be added later where appropriate.

---

# 16. AI Health & Wellness Chatbot

The AI chatbot is a PREMIUM-ONLY feature.

The chatbot provides general AI-based health and wellness assistance.

Potential topics:

- Nutrition
- Meal planning
- Exercise
- Hydration
- Sleep
- General wellness
- Healthy habits

The chatbot can use relevant user information to personalize responses.

Important:

The chatbot must NOT present itself as a doctor.

The system should provide appropriate safety messaging for medical emergencies or serious medical conditions.

The backend must enforce premium access.

Correct flow:

React Native
    ↓
POST /api/v1/chatbot/message.php
    ↓
Auth verification
    ↓
Premium verification
    ↓
ChatbotService.php
    ↓
AIService.php
    ↓
AI Provider
    ↓
Response

Free users must not be able to bypass the premium restriction by directly calling the API.

---

# 17. User Profile

The user profile may contain:

- Name
- Email
- Phone number where applicable
- Date of birth/age
- Gender where required
- Height
- Weight
- Activity level
- Fitness goal
- Dietary preference
- Food preference
- Location preference
- Profile image

The exact fields should be finalized during database design.

---

# 18. Settings

The application must support:

- Language
- Theme
- Password reset
- Delete user data
- Delete account
- Logout

Theme options may include:

- Light
- Dark
- System

Language architecture should be designed so additional languages can be added later.

---

# 19. Support

The application will provide:

## FAQ

Frequently asked questions.

## Live Chat

Users can communicate with support.

## Report Bug

Users can submit bug reports.

## Feature Request

Users can request new features.

## Review

Users can submit application feedback/reviews.

---

# 20. Referral Programme

Users can refer friends.

The referral system may provide rewards such as:

- Subscription benefits
- Discounts
- Coins

A referral should only become valid after the required verification condition is satisfied.

The backend must prevent:

- Self-referrals
- Duplicate referrals
- Fake referral claims
- Multiple rewards for the same referral

Referral logic must be handled server-side.

---

# 21. Coins / Rewards

The application may use a virtual coin/reward system.

Possible sources:

- Referrals
- Achievements
- Goals
- Promotional campaigns

Coins may later be used for:

- Discounts
- Subscription benefits
- Other approved rewards

Every coin transaction should be recorded.

Recommended structure:

coin_transactions

Never simply overwrite the user's coin balance without maintaining transaction history.

---

# 22. Subscription

The application will have:

## Free Users

Free users may have:

- Core nutrition features
- AI meal planning
- Location-based food preferences
- AI food scanner
- Smart reminders
- Goals
- Streaks
- Achievements
- Weekly reports
- Workout music redirection
- Advertisements

## Premium Users

Premium users receive premium benefits, including:

- AI Health & Wellness Chatbot

Additional premium features may be added later after approval.

The backend must be the final authority for premium status.

Never implement:

if (frontend says premium) {
    allow feature;
}

Instead:

API request
    ↓
Authentication
    ↓
Database subscription check
    ↓
Premium authorization
    ↓
Feature access

---

# 23. Advertisement Revenue

Free users may receive advertisements.

The backend may provide configuration required by the mobile advertisement SDK.

The actual advertisement SDK implementation will primarily be handled by React Native.

The backend should not contain unnecessary ad-rendering logic.

---

# 24. Authentication

Authentication will be API-based.

Required functionality:

- Registration
- Login
- Logout
- Forgot password
- Password reset
- Token/session management

Protected API requests must require authentication.

Never trust a user ID supplied by the React Native application.

The authenticated user identity must come from the authentication token.

---

# 25. API Response Standard

All API endpoints should return consistent JSON.

Success example:

{
    "status": true,
    "message": "Request successful",
    "data": {}
}

Error example:

{
    "status": false,
    "message": "Invalid request",
    "errors": {}
}

HTTP status codes should also be used correctly.

Examples:

200 - Success
201 - Created
400 - Bad Request
401 - Unauthorized
403 - Forbidden
404 - Not Found
422 - Validation Error
429 - Too Many Requests
500 - Server Error

---

# 26. Backend Directory

Recommended structure:

backend/

    api/
        v1/

    config/

    core/

    models/

    services/

    cron/

    uploads/

    logs/

    database/

---

# 27. API Versioning

All mobile APIs must use:

/api/v1/

Never create random API URLs.

Example:

/api/v1/auth/login.php

Future version:

/api/v2/auth/login.php

Existing v1 APIs should not be broken without a migration plan.

---

# 28. Database Rules

Use:

- MySQL
- InnoDB
- Foreign keys where appropriate
- Proper indexes
- UTC timestamps where practical
- Prepared statements

Avoid:

- SQL injection vulnerabilities
- Duplicate data
- Unnecessary JSON blobs
- Hard-coded user IDs
- Hard-coded subscription states

Database structure must be designed before implementing complex APIs.

---

# 29. AI Service Rules

All AI communication should go through:

services/AIService.php

Other services should call AIService instead of directly calling the external AI API.

Example:

MealService
    ↓
AIService

FoodScannerService
    ↓
AIService

ChatbotService
    ↓
AIService

This allows the AI provider to be changed later without rewriting the entire application.

---

# 30. Security

The backend must implement:

- Prepared SQL statements
- Input validation
- Authentication
- Authorization
- Rate limiting where required
- Secure password hashing
- Secure token handling
- File upload validation
- File type validation
- File size limits
- API key protection
- Error logging
- Production error handling

Never expose:

- Database passwords
- AI API keys
- Payment secrets
- JWT secrets
- Internal server paths

---

# 31. File Upload Security

Food scanner images and profile images must be validated.

Validate:

- MIME type
- File extension
- File size
- Image validity

Do not trust the filename supplied by the client.

Use generated filenames.

---

# 32. Cron Jobs

Scheduled backend tasks may include:

- Reminder processing
- Streak processing
- Achievement processing
- Weekly health report generation

Cron files:

cron/reminders.php
cron/streaks.php
cron/achievements.php
cron/weekly-report.php

Cron jobs must be safe to run repeatedly.

They should avoid generating duplicate records.

---

# 33. Development Workflow

Development should follow this order:

1. Project architecture
2. Database schema
3. Authentication
4. User profile
5. Nutrition system
6. Meal planning
7. Food scanner
8. Reminders
9. Goals
10. Streaks
11. Achievements
12. Weekly health report
13. Chatbot
14. Subscription
15. Referral/coins
16. Support
17. Notifications
18. React Native integration
19. Testing
20. Production deployment

Do not build everything simultaneously.

---

# 34. Current Development Status

Current status:

PROJECT INITIALIZATION

Completed:

- Phase 1 feature requirements collected
- MOM finalized
- Backend technology selected
- React Native selected
- Backend directory structure created

Next task:

DATABASE ARCHITECTURE

The next development task is to design:

backend/database/schema.sql

No complex API implementation should begin until the database architecture is reviewed.

---

# 35. Important Finalized Decisions

These decisions come from the project meeting dated 10 August 2026.

### Workout Music

Available to ALL users.

Music will be redirected to external music platforms.

### AI Food Scanner

Available to ALL users.

### AI Chatbot

Premium users ONLY.

### Backend

Core PHP + MySQL.

### Mobile

React Native.

### Revenue

Subscription + advertisements.

### Phase 1 Budget

₹30,000.

---

# 36. AI Assistant Behavior

When modifying this project:

1. Read AI_CONTEXT.md.
2. Read PROJECT.md when feature requirements are involved.
3. Check the existing implementation before creating new files.
4. Reuse existing architecture.
5. Do not duplicate functionality.
6. Do not change database structure without explaining the impact.
7. Do not change finalized requirements without approval.
8. Keep code production-oriented.
9. Keep security in mind.
10. Keep React Native and PHP API compatibility in mind.
11. Use clear naming conventions.
12. Provide migration instructions when database changes are required.
13. Test affected APIs after changes.
14. Do not remove existing functionality just to simplify implementation.
15. Ask for approval before major architectural changes.

---

# 37. Coding Style

PHP:

- PSR-inspired formatting
- Clear function names
- Meaningful variable names
- Prepared statements
- Small reusable functions
- Avoid unnecessary global state

React Native:

- Component-based architecture
- Reusable components
- Central API service
- Central authentication state
- Environment-based configuration
- Avoid hard-coded API URLs

Database:

- snake_case table/column naming

PHP:

- PascalCase class names
- camelCase methods/functions where appropriate

API:

- REST-style endpoint naming

---

# 38. Do Not Do These Things

Do NOT:

- Put MySQL credentials in React Native
- Put AI keys in React Native
- Trust frontend premium status
- Trust frontend user IDs
- Store plain-text passwords
- Write raw SQL with user input
- Upload unrestricted files
- Hard-code API keys
- Hard-code business rules throughout endpoints
- Break existing API contracts
- Remove features without approval
- Change finalized project decisions silently

---

# 39. Definition of Done

A feature is considered complete only when:

- Database support exists where required
- Backend API exists
- Authentication/authorization is implemented
- Validation exists
- Error handling exists
- React Native integration is possible
- Security has been considered
- API response format is consistent
- Basic testing has been performed
- Documentation is updated where required

---

# END OF AI_CONTEXT.md