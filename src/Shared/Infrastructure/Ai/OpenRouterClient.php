<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ai;

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

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $defaultModel,
        private string $fallbackModel,
        private string $apiKey,
        private int $timeout,
        private int $maxTokens,
        private float $temperature,
        private string $httpReferer = 'http://localhost',
        private string $xTitle = 'TriageFlow',
    ) {}

    /**
     * Send messages to the OpenRouter chat completion endpoint.
     *
     * Retries up to MAX_RETRIES times on TransportExceptionInterface (network-level
     * failures). HTTP errors (4xx, 5xx) are NOT retried — the caller must handle
     * those separately.
     *
     * @param array<int, array{role: string, content: string}> $messages The conversation messages in OpenAI-compatible format
     * @param string|null $model Override the default model (uses $defaultModel when null)
     *
     * @return string The assistant's content extracted from choices[0].message.content
     *
     * @throws OpenRouterException When all retry attempts are exhausted due to network failures
     */
    public function chat(array $messages, ?string $model = null): string
    {
        $selectedModel = $model ?? $this->defaultModel;
        $attempts = 0;
        $lastException = null;

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
                            'model' => $selectedModel,
                            'messages' => $messages,
                            'max_tokens' => $this->maxTokens,
                            'temperature' => $this->temperature,
                        ],
                        'timeout' => $this->timeout,
                    ],
                );

                /** @var array{choices: array<int, array{message: array{content: string}}>} $data */
                $data = $response->toArray();

                return $data['choices'][0]['message']['content'];
            } catch (\Throwable $e) {
                // Don't wrap fatal errors (e.g. TypeError from bad response structure)
                if ($e instanceof \Error) {
                    throw $e;
                }
                if (!$e instanceof TransportExceptionInterface) {
                    throw new OpenRouterException(
                        sprintf('OpenRouter API error: %s', $e->getMessage()),
                        previous: $e,
                    );
                }

                $lastException = $e;
                $attempts++;
            }
        }

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
