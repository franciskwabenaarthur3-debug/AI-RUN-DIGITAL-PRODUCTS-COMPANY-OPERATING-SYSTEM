<?php

namespace Tests\Feature\Ai;

use App\Ai\ModelCatalog;
use App\Ai\ModelRequest;
use App\Ai\ModelResponse;
use App\Ai\ProviderError;
use App\Ai\Providers\AnthropicProvider;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    private FakeAnthropic $fake;

    private AnthropicProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeAnthropic;
        $this->provider = new AnthropicProvider('sk-test-secret', null, 0, $this->fake);
    }

    private function request(int $webSearch = 0): ModelRequest
    {
        return new ModelRequest(
            model: app(ModelCatalog::class)->find('claude-sonnet-5-5'),
            system: 'Be careful.',
            messages: [$this->provider->userMessage('Hello')],
            tools: [['name' => 'create_lead', 'description' => 'Create a lead.', 'input_schema' => ['type' => 'object', 'properties' => ['company_name' => ['type' => 'string']]]]],
            maxTokens: 4000,
            timeoutSeconds: 30,
            webSearchMaxUses: $webSearch,
        );
    }

    public function test_request_is_serialized_in_the_wire_format(): void
    {
        $this->fake->message([['type' => 'text', 'text' => 'Hi']]);

        $this->provider->complete($this->request(2));

        $body = $this->fake->body(0);
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(4000, $body['max_tokens']);
        $this->assertSame('Be careful.', $body['system']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['effort' => 'medium'], $body['output_config']);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertSame('object', $body['tools'][0]['input_schema']['type']);
        $this->assertSame(['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 2], $body['tools'][1]);
        $this->assertStringContainsString(AnthropicProvider::FALLBACK_BETA, $this->fake->requests[0]->getHeaderLine('anthropic-beta'));
    }

    public function test_tool_results_use_snake_case_on_the_wire(): void
    {
        $this->fake->message([['type' => 'text', 'text' => 'ok']]);
        $request = $this->request();
        $messages = [...$request->messages, $this->provider->toolResultsMessage([['id' => 'toolu_1', 'content' => '{"ok":true}', 'is_error' => true]], 'Note.')];

        $this->provider->complete(new ModelRequest($request->model, $request->system, $messages, [], 1000, 30));

        $result = $this->fake->body(0)['messages'][1]['content'];
        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"ok":true}', 'is_error' => true], $result[0]);
        $this->assertSame(['type' => 'text', 'text' => 'Note.'], $result[1]);
        $this->assertArrayNotHasKey('tools', $this->fake->body(0));
    }

    public function test_response_is_parsed_into_text_tool_calls_searches_and_usage(): void
    {
        $this->fake->message([
            ['type' => 'text', 'text' => 'Looking. '],
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'bakeries']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
            ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'create_lead', 'input' => ['company_name' => 'Acme']],
        ], 'tool_use', ['input_tokens' => 10, 'output_tokens' => 5, 'cache_creation_input_tokens' => 20, 'cache_read_input_tokens' => 30, 'server_tool_use' => ['web_search_requests' => 1, 'web_fetch_requests' => 0]], 'claude-sonnet-5');

        $response = $this->provider->complete($this->request(1));

        $this->assertSame(ModelResponse::TOOL_USE, $response->stopReason);
        $this->assertSame('Looking. ', $response->text);
        $this->assertSame('create_lead', $response->toolCalls[0]->name);
        $this->assertSame(['company_name' => 'Acme'], $response->toolCalls[0]->input);
        $this->assertSame(['bakeries'], $response->webSearches);
        $this->assertSame(1, $response->webSearchRequests);
        $this->assertSame(60, $response->inputTokens, 'Cached input is counted.');
        $this->assertSame(5, $response->outputTokens);
        $this->assertSame('claude-sonnet-5', $response->servedModel);
    }

    public function test_stop_reasons_are_mapped(): void
    {
        foreach (['end_turn' => ModelResponse::END, 'max_tokens' => ModelResponse::MAX_TOKENS, 'refusal' => ModelResponse::REFUSAL, 'pause_turn' => ModelResponse::PAUSE] as $wire => $expected) {
            $this->fake->message([], $wire);
            $this->assertSame($expected, $this->provider->complete($this->request())->stopReason);
        }
    }

    public function test_errors_are_normalized_without_leaking_the_key(): void
    {
        $cases = [
            [429, 'rate_limit_error', 'rate_limited', true],
            [529, 'overloaded_error', 'overloaded', true],
            [500, 'api_error', 'provider_error', true],
            [401, 'authentication_error', 'auth', false],
            [403, 'permission_error', 'auth', false],
            [400, 'invalid_request_error', 'rejected', false],
        ];

        foreach ($cases as [$status, $type, $code, $retryable]) {
            $this->fake->error($status, $type, 'bad key sk-test-secret');
            try {
                $this->provider->complete($this->request());
                $this->fail("HTTP {$status} did not throw.");
            } catch (ProviderError $e) {
                $this->assertSame($code, $e->errorCode, "HTTP {$status}");
                $this->assertSame($retryable, $e->retryable, "HTTP {$status}");
                if ($code === 'auth') {
                    $this->assertStringNotContainsString('sk-test-secret', $e->getMessage());
                }
            }
        }
    }
}
