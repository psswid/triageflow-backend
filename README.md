# TriageFlow — Backend

Symfony 7.4 PHP API for AI-assisted medical triage. Processes symptom interviews, calls LLMs for analysis, and returns triage recommendations (specialist, urgency, justification). Async AI processing via Symfony Messenger, JWT auth, PostgreSQL 16.

> **Project Status:** Portfolio demo — 2-week development showcase. All data is synthetic. Not for medical use.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) + [Docker Compose](https://docs.docker.com/compose/install/) (v2+)
- [PHP](https://www.php.net/downloads) 8.2+ if running outside Docker (not recommended)
- OpenRouter API key — [get one free](https://openrouter.ai/keys)

## Quick Start

```bash
# 1. Start the stack
docker compose up -d

# 2. Copy environment config
cp .env.example .env
# Edit .env to add your OPENROUTER_API_KEY

# 3. Generate JWT key pair
docker exec -it triageflow_php php bin/console lexik:jwt:generate-keypair

# 4. Run database migrations
docker exec -it triageflow_php php bin/console doctrine:migrations:migrate --no-interaction

# 5. Verify it's running
curl http://localhost:8000/health
# → {"status":"ok"}
```

The API is now live at `http://localhost:8000`.

### From Scratch (All Commands)

```bash
git clone git@github.com:psswid/triageflow-backend.git
cd triageflow-backend
docker compose up -d
cp .env.example .env
# Edit .env → set OPENROUTER_API_KEY
docker exec -it triageflow_php php bin/console lexik:jwt:generate-keypair
docker exec -it triageflow_php php bin/console doctrine:migrations:migrate --no-interaction
curl http://localhost:8000/health
```

## Environment

Key environment variables (see `.env.example` for all):

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_ENV` | Application environment | `dev` |
| `DATABASE_URL` | PostgreSQL DSN | `postgresql://triageflow:triageflow@db:5432/triageflow` |
| `JWT_SECRET_KEY` | Path to JWT private key | `config/jwt/private.pem` |
| `JWT_PASSPHRASE` | JWT key passphrase | _(set during key generation)_ |
| `OPENROUTER_API_KEY` | OpenRouter API key for AI calls | **Required** |
| `CORS_ALLOW_ORIGIN` | Allowed CORS origins regex | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` |
| `MESSENGER_TRANSPORT_DSN` | Async message transport | `doctrine://default?auto_setup=0` |

### Generating JWT Keys

```bash
# Inside the container:
docker exec -it triageflow_php php bin/console lexik:jwt:generate-keypair

# The keys are stored in config/jwt/ (gitignored — never commit them)
```

## Available Commands

All commands run via `docker exec -it triageflow_php php bin/console <command>` or directly if PHP is installed locally.

### Testing

```bash
# Run the full test suite (204 tests, 644 assertions)
php bin/phpunit

# Run a specific test file
php bin/phpunit tests/Triage/Domain/Entity/TriageSubmissionTest.php

# Run tests by suite
php bin/phpunit --testsuite "Project Test Suite"

# Generate coverage report (HTML)
php bin/phpunit --coverage-html var/coverage
```

### Application

```bash
# Generate a synthetic triage case (manual trigger)
php bin/console app:synthetic-case:generate

# Process async messages (run in background)
php bin/console messenger:consume async -vv

# Run the scheduler (auto-generates cases every 60s)
php bin/console messenger:consume scheduler_default -vv

# Promote a user to admin
php bin/console app:promote-to-admin email@example.com

# List all registered routes
php bin/console debug:router

# Create a new database migration
php bin/console make:migration

# Run pending migrations
php bin/console doctrine:migrations:migrate
```

### Database

```bash
# Create the database
php bin/console doctrine:database:create

# Drop the database
php bin/console doctrine:database:drop --force

# Create a migration from entity changes
php bin/console make:migration

# Run migrations
php bin/console doctrine:migrations:migrate
```

## Docker Services

| Service | Image | Port | Purpose |
|---------|-------|------|---------|
| `php` | `php:8.4-fpm-alpine` (custom) | — | PHP-FPM with PostgreSQL extensions |
| `nginx` | `nginx:alpine` | `8000:80` | Web server |
| `db` | `postgres:16-alpine` | `5432` | Primary database |

```bash
# View logs
docker compose logs -f php
docker compose logs -f nginx
docker compose logs -f db

# Stop everything
docker compose down

# Full reset (delete volumes too)
docker compose down -v
```

## Architecture

### Bounded Contexts (DDD-Lite)

```
src/
├── Triage/          # Core domain: triage submissions, interviews, AI analysis
│   ├── Application/   # Commands, Queries, Handlers, Services
│   ├── Domain/        # Entities, Value Objects, Repository interfaces
│   └── Infrastructure/# Controllers, API Platform, Doctrine repos
├── User/            # Authentication, registration, JWT
│   ├── Application/
│   ├── Domain/
│   └── Infrastructure/
├── Admin/           # Dashboard stats, user management, synthetic triggers
│   ├── Application/
│   ├── Domain/
│   └── Infrastructure/
├── Synthetic/       # Synthetic case generation
│   ├── Application/
│   └── Infrastructure/
├── Shared/          # Shared kernel: AI client, value objects
│   ├── Domain/
│   └── Infrastructure/
└── Controller/
    └── HealthController.php
```

### Key Design Decisions

- **Single aggregate** — `TriageSubmission` owns its `TriageOutcome` as an embedded value object (no separate table). See [ADR-0004](../docs/adr/0004-single-aggregate-embedded-outcome.md).
- **JSON conversation history** — Stored as a JSON column, not join tables. See [ADR-0005](../docs/adr/0005-json-column-conversation-history.md).
- **Async AI processing** — Symfony Messenger with Doctrine transport queues AI analysis calls.
- **System user** — A sentinel UUID user for synthetic cases. See [ADR-0006](../docs/adr/0006-system-user-sentinel-uuid.md).
- **OpenRouter** — Uses free models (Gemma 4, GPT-OSS) via OpenRouter API. See [ADR-0001](../docs/adr/0001-openrouter-free-models.md).

### Triage Pipeline Flow

```
User submits symptoms
  → POST /api/triage/submit
  → SubmitTriageHandler creates TriageSubmission (status: pending)
  → ProcessTriageMessage dispatched via Messenger
  → TriageAnalyzer calls OpenRouter LLM
    → If enough info: generates TriageOutcome (status: completed)
    → If needs more info: stores AI question (status: awaiting_answer)
  → User answers → POST /api/triage/{id}/answer
  → Repeat up to 3 Turns
  → After Turn 3: backend forces final result
```

## API Endpoints

### Public

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/health` | Health check |

### Auth

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/register` | Register a new User |
| `POST` | `/api/login` | Login (returns JWT) |

### Triage (authenticated)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/triage/submit` | Submit initial symptom description |
| `GET` | `/api/triage/status/{id}` | Poll submission status |
| `POST` | `/api/triage/{id}/answer` | Submit answer to AI follow-up question |
| `GET` | `/api/triage/result/{id}` | Get full triage result with conversation history |
| `GET` | `/api/triage/submissions` | List own submissions |

### Admin (admin role required)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/admin/stats` | Dashboard statistics |
| `GET` | `/api/admin/submissions` | All submissions across all Users |
| `GET` | `/api/admin/submissions/{id}` | Submission detail |
| `GET` | `/api/admin/users` | List all Users (excludes system user) |
| `POST` | `/api/admin/users/{id}/impersonate` | Impersonate a User |
| `POST` | `/api/admin/synthetic/generate` | Trigger a synthetic case |

## Test Suite

**204 PHPUnit tests, 644 assertions** across all bounded contexts:

| Test Area | Files |
|-----------|-------|
| Triage Domain | `TriageSubmissionTest.php`, `TriageOutcomeTest.php`, `TriageStatusTest.php` |
| Triage Application | `SubmitTriageHandlerTest.php`, `ProcessTriageMessageHandlerTest.php`, `TriageAnalyzerTest.php`, `TriageSystemPromptTest.php` |
| Triage Infrastructure | `TriageControllerTest.php`, `DoctrineTriageSubmissionRepositoryTest.php` |
| User Domain | `UserTest.php` |
| User Infrastructure | `AuthControllerTest.php`, `RegistrationControllerTest.php`, `DoctrineUserRepositoryTest.php`, `JWTSubscriberTest.php` |
| Admin | `AdminControllerTest.php` |
| Synthetic | `GenerateSyntheticCaseHandlerTest.php`, `SyntheticSystemPromptTest.php` |
| Shared | `OpenRouterClientTest.php` |
| Smoke | `SmokeTest.php` |

## Tech Stack

| Component | Version |
|-----------|---------|
| PHP | >=8.2 |
| Symfony | 7.4.* |
| Doctrine ORM | ^3.6 |
| PostgreSQL | 16 |
| PHPUnit | ^11.5 |
| lexik/jwt-auth | latest |

## Related Repositories

- [triageflow-docs](https://github.com/psswid/triageflow-docs) — Documentation hub, ADRs, agent config
- [triageflow-frontend](https://github.com/psswid/triageflow-frontend) — React 19 SPA
