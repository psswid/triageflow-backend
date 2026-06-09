<?php

declare(strict_types=1);

namespace App\Tests\Synthetic\Infrastructure;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;

/**
 * Test double that returns a fixed symptom description for synthetic case tests.
 * Avoids real API calls in the test environment.
 */
final class TestOpenRouterClient implements OpenRouterClientInterface
{
    public function chat(array $messages, ?string $model = null): string
    {
        return 'I have been experiencing sharp chest pain on the left side for the past 3 days. It gets worse when I take deep breaths.';
    }
}
