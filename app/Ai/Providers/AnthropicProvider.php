<?php

namespace App\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Ai\ProviderError;
use App\Ai\ToolCall;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface;

/**
 * Anthropic Messages API through the official PHP SDK.
 *
 * - Thinking is left at the model default (adaptive); depth is set with
 *   the catalog's effort level.
 * - Server-side refusal fallbacks are requested ("default"), so a policy
 *   decline is retried on the provider's chosen fallback model. Usage is
 *   priced by the model that actually served the response.
 * - Web search runs on Anthropic's side and is only offered when the
 *   catalog says the model supports it and the agent is permitted.
 */
class AnthropicProvider implements ModelProvider
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $baseUrl = null,
        private readonly int $maxRetries = 2,
        private readonly ?ClientInterface $transporter = null,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(ModelRequest $request): ModelResponse
    {
        $tools = array_map(fn (array $t) => [
            'name' => $t['name'],
            'description' => $t['description'],
            'inputSchema' => $t['input_schema'],
        ], $request->tools);

        if ($request->webSearchMaxUses > 0) {
            $tools[] = ['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => $request->webSearchMaxUses];
        }

        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $request->maxTokens,
                messages: $request->messages,
                model: $request->model->model,
                system: $request->system,
                tools: $tools === [] ? null : $tools,
                outputConfig: $request->model->effort ? ['effort' => $request->model->effort] : null,
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
                requestOptions: ['timeout' => $request->timeoutSeconds, 'maxRetries' => $this->maxRetries],
            );
        } catch (APIException $e) {
            throw $this->normalize($e);
        }

        $text = '';
        $calls = [];
        $searches = [];
        foreach ($message->content as $block) {
            match ($block->type) {
                'text' => $text .= $block->text,
                'tool_use' => $calls[] = new ToolCall($block->id, $block->name, (array) $block->input),
                'server_tool_use' => $block->name === 'web_search' ? $searches[] = (string) (((array) $block->input)['query'] ?? '') : null,
                default => null,
            };
        }

        $usage = $message->usage;

        return new ModelResponse(
            stopReason: match ($message->stopReason) {
                'end_turn', 'stop_sequence' => ModelResponse::END,
                'tool_use' => ModelResponse::TOOL_USE,
                'max_tokens' => ModelResponse::MAX_TOKENS,
                'refusal' => ModelResponse::REFUSAL,
                'pause_turn' => ModelResponse::PAUSE,
                default => ModelResponse::OTHER,
            },
            text: $text,
            toolCalls: $calls,
            webSearches: $searches,
            webSearchRequests: (int) ($usage->serverToolUse?->webSearchRequests ?? 0),
            inputTokens: $usage->inputTokens + (int) $usage->cacheCreationInputTokens + (int) $usage->cacheReadInputTokens,
            outputTokens: $usage->outputTokens,
            servedModel: (string) $message->model,
            requestId: $message->id,
            raw: $message->content,
        );
    }

    public function userMessage(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    public function assistantMessage(ModelResponse $response): array
    {
        // The full content, thinking blocks included, goes back unchanged.
        return ['role' => 'assistant', 'content' => $response->raw];
    }

    public function toolResultsMessage(array $results, ?string $note = null): array
    {
        $content = array_map(fn (array $r) => [
            'type' => 'tool_result',
            'toolUseID' => $r['id'],
            'content' => $r['content'],
            'isError' => $r['is_error'],
        ], $results);

        if ($note !== null) {
            $content[] = ['type' => 'text', 'text' => $note];
        }

        return ['role' => 'user', 'content' => $content];
    }

    private function client(): Client
    {
        return new Client(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl ?: null,
            requestOptions: $this->transporter ? ['transporter' => $this->transporter] : null,
            openTelemetry: false,
        );
    }

    private function normalize(APIException $e): ProviderError
    {
        // Provider messages can echo request content; keep them short.
        $detail = Str::limit($e->getMessage(), 300);

        return match (true) {
            $e instanceof RateLimitException => new ProviderError('rate_limited', 'The provider rate limit was reached.', true),
            $e instanceof InternalServerException && $e->type?->value === 'overloaded_error' => new ProviderError('overloaded', 'The provider is overloaded.', true),
            $e instanceof InternalServerException => new ProviderError('provider_error', 'The provider returned a server error.', true),
            $e instanceof APITimeoutException => new ProviderError('timeout', 'The provider did not respond in time.', true),
            $e instanceof APIConnectionException => new ProviderError('connection', 'Could not reach the provider.', true),
            $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => new ProviderError('auth', 'The provider rejected the API key or its permissions.', false),
            $e instanceof BadRequestException, $e instanceof NotFoundException, $e instanceof UnprocessableEntityException => new ProviderError('rejected', "The provider rejected the request: {$detail}", false),
            $e instanceof APIStatusException => new ProviderError('provider_error', "The provider returned HTTP {$e->status}.", in_array($e->status, [408, 409], true) || $e->status >= 500),
            default => new ProviderError('provider_error', 'The provider call failed.', false),
        };
    }
}
