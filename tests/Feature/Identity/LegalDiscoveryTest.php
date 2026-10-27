<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Services\Cms\CmsService;
use App\Services\Identity\LegalConsents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_and_fallback_versions_are_discovered_without_invented_content(): void
    {
        config(['shopbonus.legal.terms_version' => null, 'shopbonus.legal.privacy_version' => null]);
        $this->getJson('/api/v1/public/legal-requirements')->assertOk()->assertJsonPath('data.registration_available', false)
            ->assertJsonPath('data.documents.terms.version', null);
        config(['shopbonus.legal.terms_version' => 'fallback-1', 'shopbonus.legal.privacy_version' => 'fallback-2']);
        $this->getJson('/api/v1/public/legal-requirements')->assertOk()->assertJsonPath('data.registration_available', true)
            ->assertJsonPath('data.documents_available', false)->assertJsonPath('data.documents.terms.source', 'configuration')
            ->assertJsonPath('data.documents.terms.document_url', null);
        $this->getJson('/api/v1/public/legal-requirements?locale=fr')->assertUnprocessable();
    }

    public function test_only_current_published_matching_translations_have_links(): void
    {
        $actor = User::factory()->create();
        $cms = app(CmsService::class);
        $content = ['title' => 'Sample', 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sample']]]]]];
        $page = $cms->create('test-terms', 'terms', 'public', ['nl' => ['slug' => 'terms', 'content' => $content], 'en' => ['slug' => 'terms-en', 'content' => $content]], $actor->id);
        $cms->publish($page, 'en', $actor->id, ['legal_version' => 'old', 'effective_date' => '2026-10-27', 'approve' => true]);
        $cms->publish($page, 'nl', $actor->id, ['legal_version' => 'current', 'effective_date' => '2026-10-27', 'approve' => true]);
        $this->getJson('/api/v1/public/legal-requirements?locale=en')->assertOk()->assertJsonPath('data.documents.terms.version', 'current')
            ->assertJsonPath('data.documents.terms.document_url', null);
        $this->getJson('/api/v1/public/legal-requirements')->assertOk()->assertJsonPath('data.documents.terms.document_url', url('/api/v1/public/pages/nl/terms'));
        $this->assertSame('current', app(LegalConsents::class)->requiredVersions()['terms']);
        $cms->publish($page, 'nl', $actor->id, ['legal_version' => 'next', 'effective_date' => '2026-10-27', 'approve' => true]);
        $this->getJson('/api/v1/public/legal-requirements')->assertOk()->assertJsonPath('data.documents.terms.version', 'next');
    }

    public function test_private_canonical_page_cannot_link_to_unrelated_public_text_with_the_same_version(): void
    {
        $actor = User::factory()->create();
        $cms = app(CmsService::class);
        $content = ['title' => 'Private legal text', 'body' => ['type' => 'doc', 'content' => []]];
        foreach (['public', 'merchants'] as $audience) {
            $page = $cms->create('terms-'.$audience, 'terms', $audience, ['nl' => ['slug' => 'terms-'.$audience, 'content' => $content]], $actor->id);
            $cms->publish($page, 'nl', $actor->id, ['legal_version' => 'same-version', 'effective_date' => '2026-10-27', 'approve' => true]);
        }
        $this->getJson('/api/v1/public/legal-requirements')->assertOk()->assertJsonPath('data.documents.terms.version', 'same-version')
            ->assertJsonPath('data.documents.terms.document_url', null)->assertJsonMissing(['title' => 'Private legal text']);
    }
}
