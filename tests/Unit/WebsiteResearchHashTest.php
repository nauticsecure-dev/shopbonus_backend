<?php

namespace Tests\Unit;

use App\Services\Enrichment\WebsiteResearcher;
use Tests\TestCase;

class WebsiteResearchHashTest extends TestCase
{
    public function test_object_key_order_does_not_change_evidence_hash(): void
    {
        $researcher = app(WebsiteResearcher::class);
        $first = ['title' => 'Shop', 'evidence' => ['E1' => ['value' => 'Verified title', 'fact' => 'title']], 'categories' => ['home', 'gifts']];
        $reordered = ['categories' => ['home', 'gifts'], 'evidence' => ['E1' => ['fact' => 'title', 'value' => 'Verified title']], 'title' => 'Shop'];

        $this->assertSame($researcher->hash($first), $researcher->hash($reordered));
        $this->assertNotSame($researcher->legacyHash($first), $researcher->legacyHash($reordered));
        $reordered['evidence']['E1']['value'] = 'Altered title';
        $this->assertNotSame($researcher->hash($first), $researcher->hash($reordered));
    }

    public function test_list_order_and_scalar_types_remain_part_of_the_hash(): void
    {
        $researcher = app(WebsiteResearcher::class);

        $this->assertNotSame($researcher->hash(['values' => ['first', 'second']]), $researcher->hash(['values' => ['second', 'first']]));
        $this->assertNotSame($researcher->hash(['value' => 1]), $researcher->hash(['value' => '1']));
    }
}
