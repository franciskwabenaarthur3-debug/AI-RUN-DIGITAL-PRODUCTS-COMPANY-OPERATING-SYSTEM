<?php

namespace Tests\Unit;

use App\Agents\OutputSchema;
use App\Enums\AgentRole;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutputSchemaTest extends TestCase
{
    public function test_valid_output_passes(): void
    {
        $out = OutputSchema::validate(AgentRole::Sales, ['kind' => 'draft', 'summary' => 'Draft email', 'items' => ['Hi']]);

        $this->assertSame('draft', $out['kind']);
    }

    public static function invalid(): array
    {
        return [
            'not an object' => ['just text'],
            'list' => [['a', 'b']],
            'empty' => [[]],
            'missing summary' => [['kind' => 'draft']],
            'claims completion' => [['kind' => 'completed_action', 'summary' => 'Proposal sent']],
            'unknown field' => [['kind' => 'draft', 'summary' => 'x', 'sent' => true]],
            'bad source' => [['kind' => 'analysis', 'summary' => 'x', 'sources' => [['reference' => 'a']]]],
            'too long' => [['kind' => 'draft', 'summary' => str_repeat('a', 5001)]],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_output_is_rejected(mixed $output): void
    {
        $this->expectException(ValidationException::class);
        OutputSchema::validate(AgentRole::Sales, $output);
    }

    public function test_research_output_needs_sources_or_assumptions(): void
    {
        try {
            OutputSchema::validate(AgentRole::Research, ['kind' => 'analysis', 'summary' => 'Market is growing']);
            $this->fail('Unsourced research should be rejected.');
        } catch (ValidationException) {
        }

        $this->assertIsArray(OutputSchema::validate(AgentRole::Research, [
            'kind' => 'analysis',
            'summary' => 'x',
            'sources' => [['reference' => 'https://example.com', 'retrieved_at' => '2026-10-27']],
        ]));
        $this->assertIsArray(OutputSchema::validate(AgentRole::Research, [
            'kind' => 'analysis', 'summary' => 'x', 'assumptions' => ['Unverified'],
        ]));
    }
}
