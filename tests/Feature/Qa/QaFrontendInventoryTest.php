<?php

namespace Tests\Feature\Qa;

use App\Services\Qa\QaFrontendInventory;
use Tests\TestCase;

class QaFrontendInventoryTest extends TestCase
{
    public function test_candidate_subsets_and_unobserved_or_stale_runtime_contexts_are_rejected(): void
    {
        $sha = str_repeat('a', 40);
        $observed = array_fill_keys(QaFrontendInventory::KINDS, ['synthetic']);
        $context = ['case_version' => 1, 'id' => 'context', 'git_sha' => $sha, 'locale' => 'en', 'actor_role' => 'consumer', 'ui_state' => 'loaded', 'route' => '/synthetic',
            'viewport' => ['width' => 390, 'height' => 844], 'test_key' => 'Synthetic::test', 'run_id' => 'run', 'result_id' => 'result', 'observed' => $observed,
            'evidence' => ['screenshot' => ['sha256' => str_repeat('b', 64)], 'trace' => ['sha256' => str_repeat('c', 64)]]];
        $inventory = $observed + ['schema_version' => 2, 'git_sha' => $sha, 'source_sha256' => str_repeat('d', 64), 'verification' => 'reviewed_runtime_dispositions', 'review_reference' => 'Synthetic structural fixture',
            'source_candidates' => $observed, 'contexts' => [$context], 'dispositions' => array_fill_keys(QaFrontendInventory::KINDS, [['id' => 'synthetic', 'decision' => 'covered', 'reference' => 'Synthetic only', 'context_ids' => ['context']]])];
        $service = new QaFrontendInventory;
        $this->assertTrue($service->valid($inventory));
        $subset = $inventory;
        $subset['source_candidates']['actions'][] = 'missing';
        $this->assertFalse($service->valid($subset));
        $stale = $inventory;
        $stale['contexts'][0]['git_sha'] = str_repeat('e', 40);
        $this->assertFalse($service->valid($stale));
        $unseen = $inventory;
        $unseen['contexts'][0]['observed']['components'] = [];
        $this->assertFalse($service->valid($unseen));
        $this->assertFalse($service->valid($observed + ['git_sha' => $sha]));
    }
}
