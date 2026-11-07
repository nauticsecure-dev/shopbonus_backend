<?php

namespace Tests\Feature\Qa;

use Tests\TestCase;

class QaAcceptanceSourceTest extends TestCase
{
    public function test_recovered_matrix_preserves_every_original_step_and_cannot_claim_acceptance(): void
    {
        $matrix = json_decode((string) file_get_contents(base_path('requirements/QA_ACCEPTANCE.json')), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('source_mapped', $matrix['state']);
        $this->assertSame(hash_file('sha256', base_path('requirements/'.$matrix['source']['path'])), $matrix['source']['sha256']);
        $this->assertSame(hash_file('sha256', base_path('requirements/'.$matrix['source']['text_path'])), $matrix['source']['text_sha256']);
        $source = explode('3. Fifty review tips', (string) file_get_contents(base_path('requirements/QA_ACCEPTANCE_SOURCE_V2.txt')))[0];
        preg_match_all('/\[\s*\]\s+(SB-\d{2}\.\d{2})\s+(.*?)(?=\n\s*\[\s*\]\s+SB-|\n\s*PASS:)/s', $source, $matches, PREG_SET_ORDER);
        $this->assertCount(175, $matches);
        $this->assertCount(175, $matrix['checks']);
        $this->assertCount(60, $matrix['provider_subruns']);
        $checks = array_column($matrix['checks'], null, 'id');
        foreach ($matches as $match) {
            $this->assertSame(trim(preg_replace('/\s+/u', ' ', $match[2])), $checks[$match[1]]['source_assertion']);
            $this->assertNull($checks[$match[1]]['test_key']);
            $this->assertNull($checks[$match[1]]['review_evidence_id']);
        }
        $this->assertArrayHasKey('policy_deviation', $checks['SB-12.01']);
        foreach ($matrix['provider_subruns'] as $subrun) {
            foreach ($subrun['derived_from'] as $id) {
                $this->assertArrayHasKey($id, $checks);
            }
        }
    }
}
