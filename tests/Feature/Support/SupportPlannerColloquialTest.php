<?php

namespace Tests\Feature\Support;

use App\Services\Support\SupportPlanner;
use App\Services\Support\SupportPolicy;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SupportPlannerColloquialTest extends TestCase
{
    public function test_typo_context_and_natural_order_references_are_bounded(): void
    {
        $p = app(SupportPlanner::class);
        $reward = $p->plan('My reword is missing for FS-20482', false);
        $this->assertSame('reward', $reward['intent']);
        $this->assertSame('FS-20482', $reward['reference']);
        $followup = $p->plan('And its status?', false, null, $reward);
        $this->assertSame('reward', $followup['intent']);
        $date = $p->plan('When will it arrive?', false, null, $reward);
        $this->assertSame('release_date', $date['intent']);
        $this->assertSame('FS-20482', $date['reference']);
        $this->assertSame('get_my_order_reward_status', $date['tool']);
        $points = $p->plan('points balance', false);
        $this->assertSame('points', $p->plan('And its status?', false, null, $points)['unit']);
        $this->assertSame('FS-20482', $followup['reference']);
        $this->assertSame('ORDER_STATUS', $p->plan('order status FS-20482', false)['category']);
        $this->assertSame('PAYMENT_QUESTION', $p->plan('payment FS-20482', false)['category']);
        $this->assertTrue($p->plan('order status', false)['missing_reference']);
        $this->assertTrue($p->plan('order status FS-20482', true)['wrong_scope']);
        $this->assertNull($p->plan('wallet balance', false, null, $reward)['reference']);
        $this->assertSame('unknown', $p->plan('write me a poem', false, null, $reward)['intent']);
    }

    public function test_unsupported_settings_are_rejected_and_supported_display_controls_validate(): void
    {
        $defaults = SupportPolicy::defaults();
        foreach (['tone' => 'warm', 'empathy' => 'high', 'max_answer_words' => 25, 'fallback_model' => 'another-model', 'daily_cost_limit_eur' => 1, 'stream' => true, 'tool_choice' => 'none'] as $key => $value) {
            $validator = Validator::make(['value' => array_replace($defaults, [$key => $value])], SupportPolicy::rules());
            $this->assertTrue($validator->fails(), $key);
        }
        $this->assertFalse(Validator::make(['value' => array_replace($defaults, ['show_progress_updates' => false, 'suggest_relevant_actions' => false])], SupportPolicy::rules())->fails());
    }

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

    public function test_webshop_order_references_are_recognised_not_only_ulids(): void
    {
        $planner = app(SupportPlanner::class);

        $this->assertSame('FS-20482', $planner->plan('Waar blijft mijn beloning voor FS-20482?', false)['reference']);
        $this->assertSame('1042', $planner->plan('Mijn tientje voor order #1042 is er nog niet', false)['reference']);
        $this->assertSame('77812', $planner->plan('Beloning voor bestelling 77812?', false)['reference']);
        $this->assertSame('01m673qmnj823zd5jyx2bd1ts3', $planner->plan('Voucher 01M673QMNJ823ZD5JYX2BD1TS3 werkt niet', false)['reference']);
        $this->assertNull($planner->plan('Ik heb 3 vouchers, welke verlopen?', false)['reference']);
    }
}
