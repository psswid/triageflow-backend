# TriageFlow Backend — Agent Configuration

> Symfony 7.4 API backend rules for AI agents. Load this file before ANY backend work (controllers, entities, AI integration, testing). Applies DDD Light architecture with bounded contexts: Triage, Admin, Reporting.

## Quick Reference

```bash
# Create new Symfony project
symfony new backend --version="7.4.*" --webapp

# Install core dependencies
composer require symfony/ai-bundle symfony/scheduler symfony/messenger api
composer require doctrine/orm doctrine/doctrine-migrations-bundle
composer require lexik/jwt-authentication-bundle nelmio/cors-bundle
composer require --dev phpunit/phpunit dama/doctrine-test-bundle phpstan/phpstan

# Run tests
php bin/phpunit

# Run static analysis
php vendor/bin/phpstan analyse

# Generate migration
php bin/console make:migration

# Consume async queue (AI calls)
php bin/console messenger:consume async

# Consume scheduler (synthetic case generation)
php bin/console messenger:consume scheduler_default
```

## Architecture: DDD Light with Bounded Contexts

Inspired by `CodelyTV/php-ddd-example` and `mxkh/symfony-api-platform-ddd-cqrs-boilerplate`.

### Directory Structure per Context

```
src/
├── Triage/                        # Bounded Context: Triage Pipeline
│   ├── Application/
│   │   ├── Command/
│   │   │   ├── SubmitTriageCommand.php
│   │   │   └── SubmitTriageHandler.php
│   │   ├── Query/
│   │   │   ├── GetTriageResultQuery.php
│   │   │   └── GetTriageResultHandler.php
│   │   └── Service/
│   │       └── TriageAnalyzer.php       # AI analysis via symfony/ai Agent
│   ├── Domain/
│   │   ├── Entity/
│   │   │   ├── TriageSubmission.php
│   │   │   └── TriageResult.php
│   │   ├── ValueObject/
│   │   │   ├── Symptom.php
│   │   │   ├── UrgencyLevel.php         # Enum: LOW, MEDIUM, HIGH, EMERGENCY
│   │   │   └── SpecialistType.php       # Enum: GP, CARDIOLOGIST, DERMATOLOGIST, etc.
│   │   ├── Repository/
│   │   │   └── TriageSubmissionRepository.php  # Interface only
│   │   └── Event/
│   │       └── TriageCompletedEvent.php
│   └── Infrastructure/
│       ├── Controller/
│       │   └── TriageController.php     # Manual REST (shows senior patterns)
│       ├── ApiResource/
│       │   └── TriageSubmissionResource.php  # API Platform resource
│       └── Repository/
│           └── DoctrineTriageSubmissionRepository.php
├── Admin/                          # Bounded Context: Admin Panel
│   ├── Application/
│   ├── Domain/
│   └── Infrastructure/
│       ├── ApiResource/            # API Platform auto-CRUD
│       │   └── AdminDashboardResource.php
│       └── Controller/
│           └── SyntheticCaseController.php  # Manual trigger
└── Reporting/                      # Bounded Context: Statistics
    ├── Application/
    │   └── Query/
    │       └── TriageStatsQuery.php
    ├── Domain/
    └── Infrastructure/
        └── Controller/
            └── StatsController.php
```

### Bounded Context Rules

1. **Each context has its own domain model** — no cross-context entity references
2. **Communication between contexts via Messenger events** — never via direct service calls
3. **Repository interfaces in Domain, implementations in Infrastructure**
4. **Value Objects for all domain primitives** — no stringly-typed code
5. **Read/Write separation** — Queries can bypass domain model; Commands always go through domain

## symfony/ai Integration

### Configuration (`config/packages/ai.yaml`)

```yaml
ai:
    platform:
        generic:
            deepseek:
                base_url: 'https://api.deepseek.com'
                api_key: '%env(DEEPSEEK_API_KEY)%'
                model_catalog: 'Symfony\AI\Platform\Bridge\ModelsDev\ModelCatalog'
    agent:
        triage_agent:
            platform: 'ai.platform.generic.deepseek'
            model: 'deepseek-chat'
            system_prompt: 'app.triage.system_prompt'
            tools: false
        triage_reasoner:
            platform: 'ai.platform.generic.deepseek'
            model: 'deepseek-reasoner'
            system_prompt: 'app.triage.system_prompt'
            tools: false
        synthetic_agent:
            platform: 'ai.platform.generic.deepseek'
            model: 'deepseek-chat'
            system_prompt: 'app.synthetic.system_prompt'
            tools: false

services:
    Symfony\AI\Platform\Bridge\ModelsDev\ModelCatalog:
        arguments:
            $providerId: 'deepseek'

    app.triage.system_prompt:
        class: Symfony\Component\DependencyInjection\ParameterBag\ParameterBag
        # Defined in services.yaml as a parameter
```

### AI Integration Patterns

```php
// ✅ CORRECT: TriageAnalyzer service using symfony/ai Agent
declare(strict_types=1);

namespace App\Triage\Application\Service;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Model\Request;
use Symfony\AI\Platform\Model\Response;

final readonly class TriageAnalyzer
{
    public function __construct(
        private PlatformInterface $aiPlatform,
        private string $systemPrompt,
    ) {}

    public function analyze(array $symptoms, array $patientContext): TriageResultDto
    {
        $prompt = $this->buildPrompt($symptoms, $patientContext);

        $response = $this->aiPlatform->request(new Request(
            model: 'deepseek-chat',
            messages: [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            temperature: 0.3,  // Low temp for medical consistency
            maxTokens: 1000,
        ));

        return TriageResultDto::fromAiResponse($response->content);
    }

    private function buildPrompt(array $symptoms, array $patientContext): string
    {
        // Structured prompt with medical context
        return sprintf(
            "Patient symptoms: %s\nContext: %s\n\nAnalyze and return JSON with: specialist, urgency, justification.",
            implode(', ', $symptoms),
            json_encode($patientContext),
        );
    }
}
```

### AI Call Rules

1. **Always use async** — AI calls go through Messenger transport (never blocking HTTP request)
2. **Always provide system prompt** — medical domain context + output format constraints
3. **Always validate AI output** — parse JSON, validate against enums, fallback on malformed
4. **Never expose raw AI output** — always transform through DTO before returning to client
5. **Retry on failure** — configure Messenger retry strategy for AI calls (max 3 attempts)
6. **Low temperature** (0.2-0.3) for medical analysis; higher (0.7) for synthetic data generation

### System Prompt Template

```
You are a medical triage assistant. Analyze patient symptoms and provide:
1. Recommended specialist (one of: GP, CARDIOLOGIST, DERMATOLOGIST, NEUROLOGIST, ORTHOPEDIST, GASTROENTEROLOGIST, PULMONOLOGIST, PSYCHIATRIST)
2. Urgency level (one of: LOW, MEDIUM, HIGH, EMERGENCY)
3. Brief medical justification (2-3 sentences)

IMPORTANT: This is a DEMONSTRATION system. All data is synthetic. Never provide real medical advice.
Respond ONLY with valid JSON: {"specialist": "...", "urgency": "...", "justification": "..."}
```

## Symfony 7.4 Conventions

### Code Style

```php
// ✅ Correct
declare(strict_types=1);

namespace App\Triage\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
final class TriageSubmission
{
    public function __construct(
        #[ORM\Id, ORM\GeneratedValue, ORM\Column]
        private readonly int $id,

        #[ORM\Column(type: 'json')]
        private readonly array $symptoms,

        #[ORM\Embedded(class: UrgencyLevel::class)]
        private readonly UrgencyLevel $urgency,

        #[ORM\Column]
        private readonly \DateTimeImmutable $submittedAt = new \DateTimeImmutable(),
    ) {}
}
```

### Rules
- **`declare(strict_types=1)`** in every PHP file
- **`readonly` classes** for DTOs, Value Objects, Commands, Queries
- **`final` classes** by default — open for extension only when designed
- **Constructor property promotion** with `#[ORM\]` attributes
- **Named constructors** for domain objects: `TriageSubmission::create()`, `TriageSubmission::fromArray()`
- **No setters** — immutable domain objects
- **No inheritance** in entities — use composition (Embeddables)
- **PHP 8.4 property hooks** where appropriate (getters without methods)

### Naming
- **Controllers:** `{Resource}Controller` (TriageController, AdminController)
- **Commands:** `{Action}{Resource}Command` (SubmitTriageCommand)
- **Queries:** `Get{Resource}{Filter}Query` (GetTriageResultQuery)
- **Handlers:** `{Command|Query}Handler` (SubmitTriageHandler)
- **Entities:** Domain concepts as nouns (TriageSubmission, TriageResult)
- **Value Objects:** Domain primitives (UrgencyLevel, SpecialistType, Symptom)
- **Repositories:** `{Entity}Repository` interface in Domain, `Doctrine{Entity}Repository` in Infrastructure
- **Events:** `{Entity}{Action}Event` (TriageCompletedEvent)

### Dependency Injection
- **Constructor injection only** — never use `$container->get()` or service location
- **Tagged iterators** for strategy patterns
- **`autowire` + `autoconfigure`** — minimum manual service definitions
- **Interface bindings** in `services.yaml`, never in entity constructors

## API Conventions

### REST API Design

**Triage Pipeline (Manual Controllers):**
```
POST   /api/triage/submit          # Submit symptom questionnaire
GET    /api/triage/result/{id}     # Get AI triage result
GET    /api/triage/status/{id}     # Check async processing status
```

**Admin Panel (API Platform):**
```
GET    /api/admin/submissions      # List all triaged submissions
GET    /api/admin/submissions/{id} # View single submission
PATCH  /api/admin/submissions/{id} # Update status/override
GET    /api/admin/stats            # Dashboard statistics
POST   /api/admin/synthetic/generate # Manually trigger synthetic case
```

**Synthetic Data:**
```
GET    /api/synthetic/status       # Generator health check
```

### Response Format

All responses follow JSON:API-like conventions:

```json
{
  "data": {
    "id": "uuid",
    "type": "triage_result",
    "attributes": {
      "specialist": "CARDIOLOGIST",
      "urgency": "HIGH",
      "justification": "Patient reports chest pain with radiating...",
      "submittedAt": "2026-05-26T10:30:00+00:00"
    }
  }
}
```

### Error Responses

```json
{
  "errors": [{
    "status": "422",
    "code": "VALIDATION_FAILED",
    "title": "Validation Failed",
    "detail": "Symptoms array must contain at least 1 item"
  }]
}
```

### API Rules
- **Async endpoints return 202** with status URL for polling
- **Validation errors return 422** with specific field errors
- **Auth errors return 401** (JWT expired) or 403 (insufficient permissions)
- **Always return proper HTTP status codes** — never 200 for errors
- **OpenAPI docs** auto-generated via API Platform (admin) + NelmioApiDoc (triage endpoints)

## Database & Doctrine

### Entities

```php
#[ORM\Entity]
#[ORM\Table(name: 'triage_submissions')]
final class TriageSubmission
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private readonly Uuid $id;

    #[ORM\Column(type: 'json')]
    private readonly array $symptoms;

    #[ORM\Column(type: 'string', enumType: UrgencyLevel::class)]
    private readonly UrgencyLevel $urgency;

    #[ORM\Column(type: 'string', enumType: SpecialistType::class)]
    private readonly SpecialistType $recommendedSpecialist;

    #[ORM\Column(type: 'text')]
    private readonly string $aiJustification;

    #[ORM\Column(type: 'json', nullable: true)]
    private readonly ?array $aiRawResponse;

    #[ORM\Column(type: 'string', length: 20)]
    private readonly string $status; // pending, processing, completed, failed

    #[ORM\Column(type: 'boolean')]
    private readonly bool $isSynthetic;

    #[ORM\Column]
    private readonly \DateTimeImmutable $submittedAt;

    #[ORM\Column(nullable: true)]
    private readonly ?\DateTimeImmutable $processedAt;
}
```

### Database Rules
- **UUID primary keys** — never auto-increment IDs in API-facing entities
- **JSON columns** for AI responses, symptoms, flexible data
- **PHP 8.4 Enums** mapped to Doctrine string columns
- **`isSynthetic` flag** on all entities — easy filtering of AI-generated data
- **Timestamps** — `submittedAt`, `processedAt` on every entity
- **Migrations** — always generate via `make:migration`, review before committing
- **No business logic in entities** — entities are data containers only

## Testing

### PHPUnit Configuration

```xml
<!-- phpunit.xml.dist -->
<phpunit>
    <php>
        <env name="KERNEL_CLASS" value="App\Kernel" />
        <env name="DATABASE_URL" value="sqlite:///:memory:" />
        <env name="DEEPSEEK_API_KEY" value="test_key" />
        <env name="APP_ENV" value="test" />
    </php>
    <extensions>
        <extension class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
    </extensions>
</phpunit>
```

### Test Patterns

```php
// ✅ Unit test for TriageAnalyzer (with mocked AI)
final class TriageAnalyzerTest extends KernelTestCase
{
    public function testAnalyzeReturnsValidResult(): void
    {
        $mockPlatform = $this->createMock(PlatformInterface::class);
        $mockPlatform->method('request')
            ->willReturn(new Response(content: json_encode([
                'specialist' => 'CARDIOLOGIST',
                'urgency' => 'HIGH',
                'justification' => 'Test justification',
            ])));

        $analyzer = new TriageAnalyzer(
            aiPlatform: $mockPlatform,
            systemPrompt: 'test prompt',
        );

        $result = $analyzer->analyze(
            symptoms: ['chest pain'],
            patientContext: ['age' => 45],
        );

        $this->assertSame(SpecialistType::CARDIOLOGIST, $result->specialist);
        $this->assertSame(UrgencyLevel::HIGH, $result->urgency);
    }

    public function testAnalyzeHandlesMalformedAiResponse(): void
    {
        $mockPlatform = $this->createMock(PlatformInterface::class);
        $mockPlatform->method('request')
            ->willReturn(new Response(content: 'not json'));

        $analyzer = new TriageAnalyzer($mockPlatform, 'test prompt');

        $this->expectException(TriageAnalysisFailedException::class);
        $analyzer->analyze(['symptom'], ['age' => 30]);
    }
}
```

### Testing Rules
- **Unit tests for domain logic** — mock external dependencies (AI, DB)
- **Integration tests for repositories** — use `DAMA\DoctrineTestBundle` for isolated DB
- **Functional tests for controllers** — test full request/response cycle
- **Mock AI always** — never call real DeepSeek API in tests
- **80%+ code coverage required** per OpenCode config
- **Test error paths** — malformed AI responses, timeouts, invalid input
- **Data providers** for validation edge cases

## Security

- **JWT authentication** via `lexik/jwt-authentication-bundle`
- **CORS configured** for frontend origin only
- **Input validation** — Symfony Validator constraints on all DTOs
- **No SQL injection** — Doctrine ORM + parameterized queries
- **Rate limiting** on triage endpoint (prevent abuse)
- **API keys in environment** — never committed, use `.env.local`
- **HTTPS in production** — enforced via Symfony security config

## Scheduler: Synthetic Case Generator

```php
// config/packages/scheduler.yaml
framework:
    scheduler:
        synthetic_case_generation:
            expression: '*/60 * * * *'  // Every 60 seconds
            enabled: true
```

```php
// Scheduled task handler
#[AsCronTask('*/60 * * * *')]
final class GenerateSyntheticCaseTask
{
    public function __construct(
        private PlatformInterface $aiPlatform,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(): void
    {
        $symptoms = $this->aiPlatform->request(new Request(
            model: 'deepseek-chat',
            messages: [[
                'role' => 'user',
                'content' => 'Generate 1 realistic but synthetic patient symptom set as JSON array of strings. Vary medical domains (cardiology, dermatology, neurology, etc).',
            ]],
            temperature: 0.7,
        ));

        // Create TriageSubmission with isSynthetic=true
        // Dispatch to Messenger for processing
    }
}
```

## Related Files

- `../agents.md` — Master project configuration
- `../.opencode/config.json` — OpenCode configuration
- `../.opencode/skills/triageflow/SKILL.md` — Domain conventions
- `../frontend/agents.md` — Frontend rules
- https://github.com/CodelyTV/php-ddd-example — Architecture inspiration
- https://github.com/symfony/ai — symfony/ai package
- https://symfony.com/doc/current/scheduler.html — Scheduler docs
- https://symfony.com/doc/current/messenger.html — Messenger docs
