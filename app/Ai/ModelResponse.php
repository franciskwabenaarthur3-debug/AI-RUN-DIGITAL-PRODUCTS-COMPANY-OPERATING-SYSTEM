<?php

namespace App\Ai;

/**
 * A provider response normalized for the runtime. `raw` is the provider's
 * own assistant content, handed back unchanged on the next request.
 */
final class ModelResponse
{
    public const END = 'end';

    public const TOOL_USE = 'tool_use';

    public const MAX_TOKENS = 'max_tokens';

    public const REFUSAL = 'refusal';

    /** The provider paused a long server-side tool turn; send the conversation again to continue. */
    public const PAUSE = 'pause';

    public const OTHER = 'other';

    /**
     * @param  list<ToolCall>  $toolCalls  client tools the model wants run
     * @param  list<string>  $webSearches  queries the provider ran itself
     * @param  int  $webSearchRequests  searches billed, as reported by the provider
     */
    public function __construct(
        public readonly string $stopReason,
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $webSearches,
        public readonly int $webSearchRequests,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $servedModel,
        public readonly ?string $requestId,
        public readonly mixed $raw,
    ) {}
}
