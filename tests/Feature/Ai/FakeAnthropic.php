<?php

namespace Tests\Feature\Ai;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 transport for the Anthropic SDK that returns queued responses in
 * the Messages API wire format and records every request, so tests run
 * the real SDK serialization without network access.
 */
class FakeAnthropic implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $queue = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->queue) ?? throw new RuntimeException('No fake response queued.');
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, int>  $usage
     */
    public function message(array $content, string $stopReason = 'end_turn', array $usage = [], string $model = 'claude-opus-5-5'): self
    {
        static $n = 0;
        $n++;

        $this->queue[] = new Response(200, ['content-type' => 'application/json'], json_encode([
            'id' => "msg_test_{$n}",
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'content' => $content,
            'usage' => $usage + ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
        ]));

        return $this;
    }

    public function toolUse(string $name, array $input, string $id = ''): self
    {
        return $this->message([['type' => 'tool_use', 'id' => $id ?: 'toolu_'.uniqid(), 'name' => $name, 'input' => $input]], 'tool_use');
    }

    public function submit(array $output): self
    {
        return $this->toolUse('submit_result', $output);
    }

    public function error(int $status, string $type, string $message = 'error'): self
    {
        $this->queue[] = new Response($status, ['content-type' => 'application/json'], json_encode([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ]));

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(int $i): array
    {
        $request = $this->requests[$i];
        $request->getBody()->rewind();

        return json_decode((string) $request->getBody(), true);
    }
}
