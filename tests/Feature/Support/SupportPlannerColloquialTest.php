<?php

namespace Tests\Feature\Support;

use App\Services\Support\SupportPlanner;
use Tests\TestCase;

class SupportPlannerColloquialTest extends TestCase
{
    public function test_informal_dutch_missing_reward_and_wallet_intents(): void
    {
        $planner = app(SupportPlanner::class);

        $tientje = $planner->plan('Waar blijft mijn tientje?', false);
        $this->assertSame('reward', $tientje['intent']);
        $this->assertSame('MISSING_REWARD', $tientje['category']);
        $this->assertFalse($tientje['missing_reference']);
        $this->assertSame('get_my_wallet', $tientje['tool']);

        $typo = $planner->plan('Waar blijft mjn €10 belonng?', false);
        $this->assertSame('reward', $typo['intent']);

        $balance = $planner->plan('Hoeveel tegoed heb ik?', false);
        $this->assertSame('wallet', $balance['intent']);
        $this->assertSame('WALLET_BALANCE', $balance['category']);

        $complaint = $planner->plan('Dit is belachelijk, mijn geld is weg!', false);
        $this->assertSame('wallet', $complaint['intent']);
        $this->assertSame('frustrated', $complaint['sentiment']);

        $human = $planner->plan('Ik wil iemand spreken', false);
        $this->assertSame('escalate', $human['intent']);
        $this->assertSame('HUMAN_SUPPORT', $human['category']);

        $shopify = $planner->plan('Waarom werkt Shopify niet?', true);
        $this->assertSame('integration', $shopify['intent']);
        $this->assertSame('INTEGRATION_PROBLEM', $shopify['category']);
        $this->assertFalse($shopify['wrong_scope']);
    }
}
