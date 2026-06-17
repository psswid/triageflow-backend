<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Ai;

use App\Shared\Infrastructure\Ai\OpenRouterClient;
use App\Shared\Infrastructure\Ai\OpenRouterException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenRouterClientTest extends TestCase
{
    private const string BASE_URL = 'https://openrouter.ai/api/v1';
    private const string DEFAULT_MODEL = 'openrouter/free';
    private const string FALLBACK_MODEL = 'openai/gpt-oss-120b:free';
    private const string API_KEY = 'test-api-key';
    private const int TIMEOUT = 60;
    private const int MAX_TOKENS = 1000;
    private const float TEMPERATURE = 0.7;

    /**
     * Build a real (non-mocked) OpenRouterClient with a MockHttpClient.
     */
    private function createClient(HttpClientInterface $httpClient): OpenRouterClient
    {
        return new OpenRouterClient(
            httpClient: $httpClient,
            logger: null,
            baseUrl: self::BASE_URL,
            defaultModel: self::DEFAULT_MODEL,
            fallbackModel: self::FALLBACK_MODEL,
            apiKey: self::API_KEY,
            timeout: self::TIMEOUT,
            maxTokens: self::MAX_TOKENS,
            temperature: self::TEMPERATURE,
        );
    }

    // ─── HTTP Request Validation ─────────────────────────────────────

    public function testChatSendsPostToCorrectUrl(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'Test response']],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responseBody): MockResponse {
            // Assertions inside the callback verify request details
            $this->assertSame('POST', $method);
            $this->assertSame(self::BASE_URL . '/chat/completions', $url);

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $client = $this->createClient($httpClient);
        $client->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    public function testChatSendsCorrectHeaders(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'Test response']],
            ],
        ], JSON_THROW_ON_ERROR);

        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responseBody, &$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $client = $this->createClient($httpClient);
        $result = $client->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertSame('Test response', $result);
        $this->assertIsArray($capturedOptions);

        // Symfony HttpClient normalizes headers — check both raw and normalized formats
        $headers = $capturedOptions['headers'] ?? [];
        $normalizedHeaders = $capturedOptions['normalized_headers'] ?? [];

        $authBearer = $headers['Authorization']
            ?? $normalizedHeaders['authorization'][0]
            ?? $normalizedHeaders['Authorization'][0]
            ?? null;

        $this->assertNotNull($authBearer);
        // Normalized headers may include the key name as prefix
        $this->assertStringContainsString('Bearer ' . self::API_KEY, (string) $authBearer);
    }

    public function testChatSendsCorrectBodyWithDefaultModel(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'Test response']],
            ],
        ], JSON_THROW_ON_ERROR);

        $messages = [['role' => 'user', 'content' => 'Hello']];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responseBody, $messages): MockResponse {
            // HttpClient may serialize 'json' into 'body' — decode whichever is present
            $json = $options['json']
                ?? json_decode((string) ($options['body'] ?? '[]'), true);

            $this->assertSame(self::DEFAULT_MODEL, $json['model'] ?? null);
            $this->assertSame($messages, $json['messages'] ?? null);
            $this->assertSame(self::MAX_TOKENS, $json['max_tokens'] ?? null);
            $this->assertSame(self::TEMPERATURE, $json['temperature'] ?? null);

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $client = $this->createClient($httpClient);
        $client->chat($messages);
    }

    public function testChatUsesProvidedModelWhenSpecified(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'Test response']],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responseBody): MockResponse {
            $json = $options['json']
                ?? json_decode((string) ($options['body'] ?? '[]'), true);

            $this->assertSame('custom-model:free', $json['model'] ?? null);

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $client = $this->createClient($httpClient);
        $client->chat([['role' => 'user', 'content' => 'Hello']], 'custom-model:free');
    }

    // ─── Response Parsing ────────────────────────────────────────────

    public function testChatExtractsContentFromResponse(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'You should see a neurologist.',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));

        $client = $this->createClient($httpClient);
        $result = $client->chat([['role' => 'user', 'content' => 'My head hurts']]);

        $this->assertSame('You should see a neurologist.', $result);
    }

    public function testChatParsesMultiChoiceResponseCorrectly(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'First choice content',
                    ],
                ],
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Second choice content',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(new MockResponse($responseBody, ['http_code' => 200]));

        $client = $this->createClient($httpClient);
        $result = $client->chat([['role' => 'user', 'content' => 'Hello']]);

        // Should extract from first choice only
        $this->assertSame('First choice content', $result);
    }

    // ─── Retry Logic ─────────────────────────────────────────────────

    public function testChatRetriesOnTransportException(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;

            if ($callCount < 3) {
                throw new class ('Network error') extends \Exception implements TransportExceptionInterface {};
            }

            $responseBody = json_encode([
                'choices' => [
                    ['message' => ['content' => 'Success after retries']],
                ],
            ], JSON_THROW_ON_ERROR);

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $client = $this->createClient($httpClient);
        $result = $client->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertSame('Success after retries', $result);
        $this->assertSame(3, $callCount, 'Expected 3 calls (2 failures + 1 success)');
    }

    public function testChatThrowsOpenRouterExceptionAfterThreeFailures(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): never {
            $callCount++;
            throw new class ('Persistent network error') extends \Exception implements TransportExceptionInterface {};
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/failed after 3 attempts/i');

        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            $this->assertSame(3, $callCount, 'Expected exactly 3 call attempts');
        }
    }

    public function testChatRetriesOnlyOnTransportExceptionNotHttpErrors(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;

            // Return a 500 error — NOT a TransportException.
            // The client should NOT retry on HTTP errors; it should
            // re-throw immediately as OpenRouterException.
            $responseBody = json_encode([
                'choices' => [
                    ['message' => ['content' => 'Error response body']],
                ],
            ], JSON_THROW_ON_ERROR);

            return new MockResponse($responseBody, ['http_code' => 500]);
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/API error/i');

        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            // Key assertion: only 1 call was made — no retry on HTTP errors
            $this->assertSame(1, $callCount, 'Should not retry on HTTP 500 — only TransportException triggers retry');
        }
    }

    // ─── Rate Limiting (429) ─────────────────────────────────────────

    public function testChatSwitchesToFallbackModelOn429(): void
    {
        $callCount = 0;
        $modelsUsed = [];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$callCount, &$modelsUsed): MockResponse {
            $callCount++;
            $json = $options['json'] ?? json_decode((string) ($options['body'] ?? '[]'), true);
            $modelsUsed[] = $json['model'] ?? 'unknown';

            if ($callCount === 1) {
                return new MockResponse('{"error":"Rate limited"}', ['http_code' => 429]);
            }

            $responseBody = json_encode([
                'choices' => [['message' => ['content' => 'Fallback model response']]],
            ], JSON_THROW_ON_ERROR);

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $client = $this->createClient($httpClient);
        $result = $client->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertSame('Fallback model response', $result);
        $this->assertSame(2, $callCount);
        $this->assertSame(self::DEFAULT_MODEL, $modelsUsed[0]);
        $this->assertSame(self::FALLBACK_MODEL, $modelsUsed[1]);
    }

    public function testChatRetriesWithBackoffWhenBothModelsGet429(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;

            return new MockResponse('{"error":"Rate limited"}', ['http_code' => 429]);
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/rate limited after.*retries/i');

        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            // Call 1: default model 429 → free fallback switch (no attempt consumed)
            // Call 2-4: fallback model 429 → exponential backoff retries (attempts 1-3)
            $this->assertSame(4, $callCount, 'Expected 4 calls (default + 3 fallback backoff retries)');
        }
    }

    public function testChatRetriesWithBackoffOn429WhenFallbackAlreadyPassed(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;

            return new MockResponse('{"error":"Rate limited"}', ['http_code' => 429]);
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/rate limited after.*retries/i');

        // Pass the fallback model explicitly — no free switch possible, so
        // the client enters exponential backoff retries immediately.
        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']], self::FALLBACK_MODEL);
        } finally {
            // Call 1: fallback model 429 → attempt 1 → backoff
            // Call 2: fallback model 429 → attempt 2 → backoff
            // Call 3: fallback model 429 → attempt 3 → throw (MAX_RETRIES exhausted)
            $this->assertSame(3, $callCount, 'Expected 3 calls (3 backoff retries on fallback model)');
        }
    }

    public function test429WithRetryAfterNumericSecondsDecreasesBackoff(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;

            return new MockResponse('{"error":"Rate limited"}', [
                'http_code' => 429,
                'response_headers' => ['Retry-After' => '10'],
            ]);
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/rate limited after.*retries/i');

        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            // Call 1: default model 429 → free fallback switch (no attempt consumed)
            // Call 2: fallback model 429 → attempt=1 → backoff with Retry-After:10
            // Call 3: fallback model 429 → attempt=2 → backoff with Retry-After:10
            // Call 4: fallback model 429 → attempt=3 → throw
            $this->assertSame(4, $callCount, 'Expected 4 calls (default + 3 fallback retries with Retry-After)');
        }
    }

    public function test429WithRetryAfterHttpDateFormatIsParsedCorrectly(): void
    {
        $callCount = 0;
        $futureDate = gmdate('D, d M Y H:i:s \G\M\T', time() + 15);

        $httpClient = new MockHttpClient(function () use (&$callCount, $futureDate): MockResponse {
            $callCount++;

            return new MockResponse('{"error":"Rate limited"}', [
                'http_code' => 429,
                'response_headers' => ['Retry-After' => $futureDate],
            ]);
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/rate limited after.*retries/i');

        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            // Same 4-call pattern: default 429 → free switch, fallback ×3 → throw
            $this->assertSame(4, $callCount, 'Expected 4 calls with HTTP-date Retry-After header');
        }
    }

    public function test429WithRetryAfterExceedingCapReturnsMinusOne(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;

            return new MockResponse('{"error":"Rate limited"}', [
                'http_code' => 429,
                'response_headers' => ['Retry-After' => '300'],
            ]);
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/exceeds.*cap/i');

        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            // Call 1: default model 429 → free fallback switch
            // Call 2: fallback model 429 → retryAfter=300 > cap(30) → -1 → immediate throw
            $this->assertSame(2, $callCount, 'Expected 2 calls before cap-exceeded give-up');
        }
    }

    public function test429WithoutRetryAfterHeaderUsesExponentialBackoff(): void
    {
        $callCount = 0;

        $httpClient = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;

            return new MockResponse('{"error":"Rate limited"}', ['http_code' => 429]);
        });

        $client = $this->createClient($httpClient);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessageMatches('/rate limited after.*retries/i');

        try {
            $client->chat([['role' => 'user', 'content' => 'Hello']]);
        } finally {
            // Same 4-call pattern: pure exponential backoff with no Retry-After
            $this->assertSame(4, $callCount, 'Expected 4 calls with exponential backoff (no Retry-After)');
        }
    }

    // ─── Class Structure ─────────────────────────────────────────────

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(OpenRouterClient::class);

        $this->assertTrue($reflection->isFinal(), 'OpenRouterClient must be a final class');
    }

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(OpenRouterClient::class);

        $this->assertTrue(
            $reflection->isReadOnly(),
            'OpenRouterClient must be a readonly class'
        );
    }

    public function testFileHasStrictTypes(): void
    {
        $reflection = new \ReflectionClass(OpenRouterClient::class);
        $filePath = $reflection->getFileName();
        $this->assertIsString($filePath);

        $fileContents = file_get_contents($filePath);
        $this->assertStringContainsString('declare(strict_types=1)', (string) $fileContents);
    }

    // ─── Timeout Configuration ───────────────────────────────────────

    public function testChatIncludesTimeoutInRequest(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'OK']],
            ],
        ], JSON_THROW_ON_ERROR);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responseBody): MockResponse {
            $this->assertSame((float) self::TIMEOUT, $options['timeout'] ?? null);

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $client = $this->createClient($httpClient);
        $client->chat([['role' => 'user', 'content' => 'Hello']]);
    }
}
