# PROJECT.md

# AI-Powered Health & Wellness App

## Project Specification

---

# 1. Project Information

## Project Name

AI-Powered Health & Wellness App

## Project Type

AI-powered mobile health and wellness application.

## Development Stack

### Frontend

React Native

### Backend

Core PHP

### Database

MySQL

### API

REST API

### API Version

v1

### Development Environment

Local development:

Laragon

Production:

PHP/MySQL compatible hosting or VPS.

---

# 2. Project Objective

The objective of the application is to provide users with a personalized health and wellness platform.

The application will combine:

- Nutrition
- AI meal planning
- Food scanning
- Hydration
- Exercise
- Sleep
- Goals
- Streaks
- Achievements
- Health reports
- AI assistance
- Reminders
- Social/referral rewards
- Subscription services

The application should provide personalized recommendations based on user information and preferences.

---

# 3. Phase 1 Features

## 3.1 Essential Nutrition Requirement

The application will identify the user's essential nutritional requirements based on their body information and individual needs.

Inputs may include:

- Age
- Gender
- Height
- Weight
- Activity level
- Fitness goal
- Dietary preference
- Food preference
- Location

Outputs may include:

- Calories
- Protein
- Carbohydrates
- Fat
- Fiber
- Water
- Relevant nutritional targets

The nutrition engine will be used throughout the application.

---

# 4. AI Meal Planning

The application will generate personalized meal plans.

The AI meal planner will consider:

- Nutritional requirements
- User goals
- Body information
- Dietary preferences
- Food preferences
- Location
- Local food options
- User history where available

The meal plan may contain:

- Breakfast
- Lunch
- Snacks
- Dinner

Each meal may contain:

- Food name
- Quantity
- Calories
- Protein
- Carbohydrates
- Fat
- Fiber
- Other nutritional information

---

# 5. Location-Based Food Preference

The application will recommend food based on the user's general location and food preferences.

Examples:

- Local cuisine
- Regional food
- Local ingredients
- Common foods
- User-selected food preferences

The system should avoid unnecessary collection of precise location data.

---

# 6. AI Food Scanner

Users can scan food using their mobile camera or upload a food image.

The AI scanner will analyze the image and provide estimated information.

Possible results:

- Food name
- Food category
- Estimated serving
- Calories
- Protein
- Carbohydrates
- Fat
- Fiber
- Other relevant nutrition information

The feature is available to ALL users.

Food scan history may be stored.

---

# 7. Smart Reminders

The application supports reminders for:

## Water

Hydration reminders.

## Food

Meal reminders.

## Workout

Exercise reminders.

## Sleep

Sleep schedule reminders.

Users can:

- Create reminders
- Edit reminders
- Delete reminders
- Enable/disable reminders
- Set times
- Manage reminder preferences

---

# 8. Workout Music

Workout music is available to ALL users.

The application will not host music.

Instead, the app will redirect users to external music services.

Example:

Spotify

The implementation should use approved deep links or external URLs.

---

# 9. Goals

The application supports:

## Daily Goals

Daily health and wellness objectives.

## Weekly Goals

Weekly objectives.

## Monthly Goals

Monthly objectives.

Goals can be connected to:

- Nutrition
- Water
- Workout
- Sleep
- Habits
- Other health activities

---

# 10. User Streaks

The application tracks user consistency.

Examples:

- Daily activity streak
- Goal completion streak
- Workout streak
- Water tracking streak
- Meal tracking streak

Streaks should be calculated by the backend.

---

# 11. User Achievements

Users can unlock achievements.

Examples:

- First goal completed
- 3-day streak
- 7-day streak
- 30-day streak
- First food scan
- First meal plan
- Workout milestone
- Hydration milestone

Achievement definitions should be manageable without rewriting the entire application.

---

# 12. Weekly Health Report

The application generates a weekly health report.

The report may contain:

- Goal completion
- Nutrition summary
- Water intake
- Workout activity
- Sleep activity
- Meal consistency
- Streaks
- Achievements
- Wellness summary

The report should provide an easy-to-understand overview of the user's weekly progress.

---

# 13. AI Health & Wellness Chatbot

The AI chatbot is a PREMIUM feature.

The chatbot can assist users with general:

- Nutrition
- Meal planning
- Exercise
- Hydration
- Sleep
- Wellness
- Healthy habits

The chatbot may use relevant user profile information to personalize responses.

The chatbot must not claim to be a doctor or replace professional medical care.

Premium access must be verified by the backend.

---

# 14. User Profile

The profile system includes:

- Name
- Email
- Phone
- Age/date of birth
- Gender where required
- Height
- Weight
- Activity level
- Fitness goal
- Dietary preferences
- Food preferences
- Location preferences
- Profile image

Exact database fields will be finalized during schema design.

---

# 15. Settings

The application supports:

- Language
- Theme
- Password reset
- Delete user data
- Delete account
- Logout

Theme:

- Light
- Dark
- System

Language support should be designed for future expansion.

---

# 16. Support

Support features include:

### FAQ

Frequently asked questions.

### Live Chat

User-to-support communication.

### Report Bug

Users can submit technical problems.

### Feature Request

Users can request improvements or new functionality.

### Review

Users can submit feedback/reviews.

---

# 17. Referral Programme

Users can invite friends.

Referral rewards may include:

- Subscription access
- Subscription discounts
- Coins

Referral rules must prevent abuse.

The backend must prevent:

- Self-referrals
- Duplicate referrals
- Fraudulent claims
- Duplicate rewards

---

# 18. Coins

The application includes a virtual reward/coin system.

Coins may be earned through:

- Referrals
- Achievements
- Goals
- Promotional campaigns

Coins may be used for:

- Discounts
- Subscription benefits
- Other approved rewards

Every coin change should create a transaction record.

---

# 19. Revenue Model

The application will use two primary revenue sources:

## Subscription Revenue

Premium users pay for subscription access.

Premium users receive premium functionality.

The initial premium feature is:

- AI Health & Wellness Chatbot

Additional premium features may be introduced later.

---

## Advertisement Revenue

Free users may see advertisements.

Advertisement SDK integration will primarily be implemented in React Native.

---

# 20. User Types

## Free User

Free users have access to the standard application features.

Expected access:

- Essential nutrition
- AI meal planning
- Location-based food preference
- AI food scanner
- Smart reminders
- Goals
- Streaks
- Achievements
- Weekly health reports
- Workout music redirection
- Advertisements

---

## Premium User

Premium users have all applicable free features plus premium functionality.

Initial premium functionality:

- AI Health & Wellness Chatbot
- Premium benefits

Premium access must be verified server-side.

---

# 21. Mobile Application Architecture

React Native will be responsible for:

- UI
- Navigation
- Camera
- Image selection
- Local notifications
- Device storage
- Authentication state
- API communication
- Advertisement SDK
- External music links
- User interactions

The React Native application must communicate with the PHP backend through HTTPS APIs.

---

# 22. Backend Architecture

Backend:

Core PHP + MySQL

Architecture:

API
↓
Core utilities
↓
Services
↓
Models
↓
Database

Backend directories:

backend/
    api/
    config/
    core/
    models/
    services/
    cron/
    uploads/
    logs/
    database/

---

# 23. API Structure

Base URL:

/api/v1/

Examples:

POST /api/v1/auth/register.php

POST /api/v1/auth/login.php

GET /api/v1/user/profile.php

PUT /api/v1/user/update-profile.php

GET /api/v1/nutrition/requirements.php

POST /api/v1/meals/generate.php

POST /api/v1/food/scan.php

GET /api/v1/goals/daily.php

GET /api/v1/streaks/index.php

GET /api/v1/achievements/index.php

GET /api/v1/health-report/weekly.php

POST /api/v1/chatbot/message.php

GET /api/v1/subscription/status.php

GET /api/v1/referral/rewards.php

GET /api/v1/coins/balance.php

---

# 24. Authentication

Authentication functionality:

- Registration
- Login
- Logout
- Forgot password
- Reset password

Protected APIs require authentication.

Authentication tokens must be securely generated and validated.

The backend determines the authenticated user.

---

# 25. Database

Database:

MySQL

Initial tables will be designed after reviewing the complete Phase 1 requirements.

Expected table categories:

### User

- users
- user_profiles
- user_settings

### Nutrition

- nutrition_requirements

### Meals

- meal_plans
- meals
- meal_history

### Food Scanner

- food_scans

### Activity

- water_logs
- workout_logs
- sleep_logs
- meal_logs

### Reminders

- reminders

### Goals

- goals
- goal_progress

### Streaks

- streaks

### Achievements

- achievements
- user_achievements

### Reports

- health_reports

### Chatbot

- chat_sessions
- chat_messages

### Subscription

- subscription_plans
- subscriptions
- payments

### Referral

- referral_codes
- referrals
- referral_rewards

### Coins

- coin_transactions

### Notifications

- devices
- notifications

### Support

- faqs
- support_chats
- bug_reports
- feature_requests
- reviews

The final schema may differ after normalization and detailed design.

---

# 26. Security Requirements

The application must protect:

- User information
- Authentication credentials
- AI API keys
- Database credentials
- Payment credentials
- Subscription information
- Uploaded images

Required:

- Password hashing
- Prepared SQL statements
- Input validation
- Authentication
- Authorization
- Rate limiting
- Secure uploads
- HTTPS
- Environment variables
- Secure error handling

---

# 27. AI Architecture

All AI functionality should go through the backend.

AI features:

1. AI Meal Planning
2. AI Food Scanner
3. AI Health & Wellness Chatbot

Architecture:

AI Feature
↓
Service
↓
AIService.php
↓
External AI API

API keys remain on the backend.

---

# 28. Notification Architecture

Notifications may be generated from:

- Water reminders
- Meal reminders
- Workout reminders
- Sleep reminders
- Goal completion
- Achievement unlock
- Weekly report availability
- Subscription events
- Referral rewards

Mobile notifications may be handled using an appropriate React Native notification/push system.

---

# 29. Cron Jobs

Scheduled backend tasks:

reminders.php

streaks.php

achievements.php

weekly-report.php

Cron jobs must be idempotent where possible.

They must not create duplicate reports, rewards, or achievements.

---

# 30. Project Development Phases

## Phase A — Foundation

- Backend setup
- Database setup
- API structure
- Authentication
- User profile
- Settings

## Phase B — Nutrition

- Nutrition calculation
- Nutrition requirements
- Nutrition dashboard

## Phase C — AI Meal Planning

- AI integration
- Meal generation
- Meal storage
- Meal history

## Phase D — Food Scanner

- Image upload
- AI image analysis
- Nutrition results
- Scan history

## Phase E — Activity

- Water
- Meals
- Workout
- Sleep
- Reminders

## Phase F — Gamification

- Goals
- Streaks
- Achievements
- Weekly reports

## Phase G — AI Chatbot

- Chat sessions
- Messages
- Premium authorization
- AI integration

## Phase H — Monetization

- Subscription plans
- Subscription status
- Payment verification
- Advertisement integration

## Phase I — Referral & Rewards

- Referral codes
- Referral tracking
- Coins
- Rewards
- Discounts

## Phase J — Support

- FAQ
- Live chat
- Bug reports
- Feature requests
- Reviews

## Phase K — Mobile Integration

- React Native screens
- API integration
- Authentication state
- Notifications
- Camera/scanner
- Subscription UI
- Ads
- External music links

## Phase L — Testing & Deployment

- API testing
- Mobile testing
- Security testing
- Performance testing
- Production configuration
- Deployment

---

# 31. Current Status

Project initialization is complete.

Current status:

FOUNDATION / DATABASE DESIGN

Completed decisions:

- React Native frontend
- Core PHP backend
- MySQL database
- REST API
- API versioning
- Phase 1 feature list
- Premium/free concept
- Revenue model
- Workout music redirection
- AI food scanner
- AI chatbot

Next task:

DATABASE SCHEMA DESIGN

---

# 32. Important MOM

Meeting:

Feature Discussion & Updates

Date:

10 August 2026

Important decisions:

### Workout Music

Originally considered premium-only.

Final decision:

Available to ALL users through redirection to external music platforms.

### AI Food Scanner

Added to Phase 1.

Available to ALL users.

### AI Chatbot

Added to Phase 1.

Available to PREMIUM users only.

### Revenue

Subscription + advertisements.

---

# 33. Phase 1 Development Cost

Agreed Phase 1 development cost:

₹30,000

This document describes the agreed feature scope.

If a new major feature is requested outside this scope, it should be treated as a scope change and evaluated separately.

---

# 34. Scope Control

The following are considered Phase 1 requirements.

Any major functionality not listed here should not be automatically implemented as part of Phase 1.

Examples of potential future features:

- Wearable device integration
- Smartwatch integration
- Advanced fitness tracking
- Medical report analysis
- Doctor consultation
- Telemedicine
- Laboratory integration
- Advanced body scanning
- Advanced AI diagnosis
- Community/social feed
- Trainer marketplace

These are NOT currently part of Phase 1 unless explicitly approved.

---

# 35. Important Product Rule

This application is a health and wellness application.

It should provide general wellness guidance and personalized recommendations.

It should not claim to diagnose diseases or replace professional medical advice.

Any feature involving medical diagnosis, prescription, or emergency medical treatment requires separate product and safety review.

---

# 36. Success Criteria

Phase 1 will be considered successfully implemented when:

- Users can register/login
- Users can create and manage profiles
- Nutrition requirements can be generated
- Personalized meal plans can be generated
- Food can be scanned
- Food nutrition information can be displayed
- Location-based food preferences work
- Water/food/workout/sleep reminders work
- Users can track goals
- Streaks work
- Achievements work
- Weekly reports work
- Workout music redirection works
- Premium subscription architecture works
- Premium chatbot access works
- Free users can receive advertisements
- Referral system works
- Coins/rewards work
- Support features work
- React Native communicates correctly with the PHP API
- Authentication is secure
- Sensitive credentials are protected
- The application can be deployed to production

---

# END OF PROJECT.md