<?php

namespace Tests\Unit;

use App\Enums\LeadStatus;
use App\Support\Domain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LeadStatusTest extends TestCase
{
    public function test_happy_path_is_allowed(): void
    {
        $path = [LeadStatus::New, LeadStatus::Researched, LeadStatus::Qualified, LeadStatus::OutreachDrafted, LeadStatus::Contacted, LeadStatus::Replied, LeadStatus::Converted];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue($path[$i]->canTransitionTo($path[$i + 1]), "{$path[$i]->value} → {$path[$i + 1]->value}");
        }
    }

    public function test_skipping_steps_is_refused(): void
    {
        $this->assertFalse(LeadStatus::New->canTransitionTo(LeadStatus::Contacted));
        $this->assertFalse(LeadStatus::New->canTransitionTo(LeadStatus::Converted));
        $this->assertFalse(LeadStatus::Researched->canTransitionTo(LeadStatus::OutreachDrafted));
    }

    public function test_terminal_states_have_no_exits(): void
    {
        $this->assertSame([], LeadStatus::Unsubscribed->allowedTransitions());
        $this->assertSame([], LeadStatus::Converted->allowedTransitions());
    }

    public function test_converted_and_unsubscribed_are_never_manual_targets(): void
    {
        foreach (LeadStatus::cases() as $status) {
            $this->assertNotContains(LeadStatus::Converted, $status->manualTransitions());
            $this->assertNotContains(LeadStatus::Unsubscribed, $status->manualTransitions());
        }
    }

    public static function domains(): array
    {
        return [
            ['https://www.Example.com/about?x=1', 'example.com'],
            ['example.com', 'example.com'],
            ['http://shop.example.co.uk:8080', 'shop.example.co.uk'],
            ['WWW.EXAMPLE.COM.', 'example.com'],
            ['', null],
            [null, null],
        ];
    }

    #[DataProvider('domains')]
    public function test_domain_normalization(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Domain::fromUrl($input));
    }
}
