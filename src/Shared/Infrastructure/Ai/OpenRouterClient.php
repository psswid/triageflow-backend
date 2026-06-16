<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Low-level HTTP client wrapper for the OpenRouter chat completion API.
 *
 * Handles authentication headers, request formatting, retry logic on network
 * failures, and response parsing. Does NOT contain triage-specific logic —
 * that belongs in TriageAnalyzer.
 *
 * @see https://openrouter.ai/docs/quickstart
 */
final readonly class OpenRouterClient implements OpenRouterClientInterface
{
    private const int MAX_RETRIES = 3;
    private const int RETRY_DELAY_SECONDS = 2;
    private const int RETRY_AFTER_CAP_SECONDS = 30;
    private const int BACKOFF_BASE_MS = 2_000;
    private const int BACKOFF_CAP_MS = 30_000;
    private const int BACKOFF_JITTER_MS = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $defaultModel,
        private string $fallbackModel,
        private string $apiKey,
        private int $timeout,
        private int $maxTokens,
        private float $temperature,
        private ?LoggerInterface $logger = null,
        private string $httpReferer = 'http://localhost',
        private string $xTitle = 'TriageFlow',
    ) {}

    /**
     * Send messages to the OpenRouter chat completion endpoint.
     *
     * Retry strategy:
     *   - HTTP 429 (rate limited):   Switch to $fallbackModel and retry immediately
     *                                 (free retry, no attempt consumed). Subsequent
     *                                 429s on fallback model retry up to MAX_RETRIES
     *                                 with exponential backoff + Retry-After awareness.
     *   - TransportExceptionInterface: Retry up to MAX_RETRIES times with
     *                                 exponential backoff (2s, 4s, ...).
     *   - Other HTTP errors (4xx, 5xx): Throw immediately — caller must
     *                                   handle those separately.
     *
     * @param array<int, array{role: string, content: string}> $messages The conversation messages in OpenAI-compatible format
     * @param string|null $model Override the default model (uses $defaultModel when null)
     *
     * @return string The assistant's content extracted from choices[0].message.content
     *
     * @throws OpenRouterException When all retries or fallback model are exhausted
     */
    public function chat(array $messages, ?string $model = null): string
    {
        $currentModel = $model ?? $this->defaultModel;
        $startTime = microtime(true);
        $attempts = 0;
        $lastException = null;
        $triedFallback = false;

        while ($attempts < self::MAX_RETRIES) {
            try {
                $response = $this->httpClient->request(
                    'POST',
                    $this->baseUrl . '/chat/completions',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->apiKey,
                            'HTTP-Referer' => $this->httpReferer,
                            'X-Title' => $this->xTitle,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => [
                            'model' => $currentModel,
                            'messages' => $messages,
                            'max_tokens' => $this->maxTokens,
                            'temperature' => $this->temperature,
                        ],
                        'timeout' => $this->timeout,
                    ],
                );

                /** @var array{choices: array<int, array{message: array{content: string}}>} $data */
                $data = $response->toArray();

                $logContext = [
                    'model' => $currentModel,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                    'success' => true,
                    'attempts' => $attempts + 1,
                    'token_usage' => $data['usage'] ?? null,
                ];

                if ($attempts > 0) {
                    $this->logger?->notice('OpenRouter API call succeeded after retry', $logContext);
                } else {
                    $this->logger?->info('OpenRouter API call completed', $logContext);
                }

                return $data['choices'][0]['message']['content'];
            } catch (\Throwable $e) {
                // Don't wrap fatal errors (e.g. TypeError from bad response structure)
                if ($e instanceof \Error) {
                    throw $e;
                }

                // ── Rate limited (429): try fallback model ──
                if ($e instanceof HttpExceptionInterface && $e->getResponse()->getStatusCode() === 429) {
                    if (!$triedFallback && $currentModel !== $this->fallbackModel) {
                        $this->logger?->warning('OpenRouter API rate limited on primary model, switching to fallback', [
                            'model' => $currentModel,
                            'fallback_model' => $this->fallbackModel,
                            'duration_ms' => round((microtime(true) - $startTime) * 1000),
                        ]);

                        $currentModel = $this->fallbackModel;
                        $triedFallback = true;
                        // Don't consume a retry attempt — fallback switch is a free retry
                        sleep(self::RETRY_DELAY_SECONDS);

                        continue;
                    }

                    // ── Fallback model 429: retry with exponential backoff ──
                    $lastException = $e;
                    $retryAfter = $this->parseRetryAfter($e->getResponse());
                    $attempts++;

                    if ($attempts >= self::MAX_RETRIES) {
                        $this->logger?->error('OpenRouter API rate limited after all retries', [
                            'model' => $currentModel,
                            'attempts' => $attempts,
                            'duration_ms' => round((microtime(true) - $startTime) * 1000),
                            'success' => false,
                            'error' => 'rate_limited_exhausted',
                        ]);

                        throw new OpenRouterException(
                            sprintf(
                                'OpenRouter API rate limited after %d retries on fallback model "%s"',
                                $attempts,
                                $currentModel,
                            ),
                            previous: $e,
                        );
                    }

                    $backoffMs = $this->calculateBackoff($attempts, $retryAfter);

                    if ($backoffMs < 0) {
                        throw new OpenRouterException(
                            sprintf(
                                'OpenRouter API rate limited with Retry-After %ds (exceeds %ds cap)',
                                $retryAfter,
                                self::RETRY_AFTER_CAP_SECONDS,
                            ),
                            previous: $e,
                        );
                    }

                    $this->logger?->warning('OpenRouter API rate limited on fallback model, retrying with backoff', [
                        'model' => $currentModel,
                        'attempt' => $attempts,
                        'delay_ms' => $backoffMs,
                        'retry_after' => $retryAfter,
                        'duration_ms' => round((microtime(true) - $startTime) * 1000),
                    ]);

                    \usleep($backoffMs * 1_000);

                    continue;
                }

                // ── Non-429 HTTP errors — throw immediately ──
                if (!$e instanceof TransportExceptionInterface) {
                    $this->logger?->error('OpenRouter API non-retryable error', [
                        'model' => $currentModel,
                        'duration_ms' => round((microtime(true) - $startTime) * 1000),
                        'success' => false,
                        'error' => $e->getMessage(),
                        'error_class' => $e::class,
                    ]);

                    throw new OpenRouterException(
                        sprintf('OpenRouter API error: %s', $e->getMessage()),
                        previous: $e,
                    );
                }

                // ── Network-level failures — retry with backoff ──
                $lastException = $e;
                $attempts++;

                if ($attempts < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY_SECONDS * $attempts);
                }
            }
        }

        $this->logger?->error('OpenRouter API call failed after all retries', [
            'model' => $currentModel,
            'duration_ms' => round((microtime(true) - $startTime) * 1000),
            'success' => false,
            'error' => $lastException?->getMessage() ?? 'unknown error',
            'error_class' => $lastException ? $lastException::class : null,
            'attempts' => self::MAX_RETRIES,
        ]);

        throw new OpenRouterException(
            sprintf(
                'OpenRouter API call failed after %d attempts: %s',
                self::MAX_RETRIES,
                $lastException?->getMessage() ?? 'unknown error',
            ),
            previous: $lastException,
        );
    }

    /**
     * Parse the Retry-After header from an OpenRouter response.
     *
     * Handles both numeric seconds (e.g. "15") and HTTP-date
     * (e.g. "Wed, 21 Oct 2024 07:28:00 GMT") formats.
     *
     * @param ResponseInterface $response The HTTP response
     *
     * @return int|null Seconds to wait, or null if header is absent/unparseable
     */
    private function parseRetryAfter(ResponseInterface $response): ?int
    {
        $headers = $response->getHeaders(false);

        if (!isset($headers['retry-after']) || $headers['retry-after'] === []) {
            return null;
        }

        $value = trim($headers['retry-after'][0]);

        if (is_numeric($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * Calculate the backoff delay in milliseconds using exponential backoff
     * with jitter, respecting the server's Retry-After header.
     *
     * @param int      $attempt          The current retry attempt (1-based)
     * @param int|null $retryAfterSeconds The Retry-After value in seconds, if available
     *
     * @return int Delay in milliseconds. Returns -1 if Retry-After exceeds cap (signal to give up).
     */
    private function calculateBackoff(int $attempt, ?int $retryAfterSeconds): int
    {
        $exponentialDelay = (int) min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * 2 ** ($attempt - 1));
        $jitter = \random_int(0, self::BACKOFF_JITTER_MS);
        $ourDelay = $exponentialDelay + $jitter;

        if ($retryAfterSeconds !== null) {
            if ($retryAfterSeconds > self::RETRY_AFTER_CAP_SECONDS) {
                $this->logger?->warning('OpenRouter Retry-After exceeds cap, giving up', [
                    'retry_after' => $retryAfterSeconds,
                    'cap' => self::RETRY_AFTER_CAP_SECONDS,
                ]);

                return -1; // Signal: do not retry
            }

            return max($ourDelay, $retryAfterSeconds * 1_000);
        }

        return $ourDelay;
    }
}
