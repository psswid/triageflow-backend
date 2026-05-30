<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Ai;

/**
 * Contract for AI chat completion clients.
 *
 * Extracted to enable test mocking without requiring the concrete
 * implementation to be non-final — PHPUnit cannot mock final classes
 * but can mock interfaces.
 */
interface OpenRouterClientInterface
{
    /**
     * Send messages to a chat completion endpoint.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param string|null $model Override the default model (uses default when null)
     *
     * @return string The assistant's response content
     *
     * @throws OpenRouterException When the API call fails
     */
    public function chat(array $messages, ?string $model = null): string;
}
