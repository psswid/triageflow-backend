<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
     *   - HTTP 429 (rate limited):   Switch to $fallbackModel and retry immediately.
     *                                 If both models are rate limited, throw.
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

                    $this->logger?->error('OpenRouter API rate limited on all models', [
                        'model' => $currentModel,
                        'duration_ms' => round((microtime(true) - $startTime) * 1000),
                        'success' => false,
                        'error' => 'rate_limited_all_models',
                    ]);

                    throw new OpenRouterException(
                        'OpenRouter API rate limited on both default and fallback models',
                        previous: $e,
                    );
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
}
