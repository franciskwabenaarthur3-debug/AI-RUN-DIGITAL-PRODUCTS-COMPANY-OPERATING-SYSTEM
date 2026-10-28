<?php

namespace App\Ai\Providers;

use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Ai\ProviderError;

/**
 * One model vendor. Adapters translate between the provider's API and the
 * gateway's neutral types, and must not assume features another provider
 * has: capabilities are declared per model in the catalog.
 */
interface ModelProvider
{
    public function name(): string;

    /**
     * @throws ProviderError
     */
    public function complete(ModelRequest $request): ModelResponse;

    /** @return array<string, mixed> */
    public function userMessage(string $text): array;

    /** @return array<string, mixed> */
    public function assistantMessage(ModelResponse $response): array;

    /**
     * @param  list<array{id: string, content: string, is_error: bool}>  $results
     * @return array<string, mixed>
     */
    public function toolResultsMessage(array $results, ?string $note = null): array;
}
