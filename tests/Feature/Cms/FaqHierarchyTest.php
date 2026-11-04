<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Models\FaqCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class FaqHierarchyTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->asAdmin();
    }

    private function asAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function category(string $key, ?string $parent = null): string
    {
        return $this->postJson('/api/v1/admin/cms/faq/categories', ['key' => $key, 'names' => ['nl' => $key, 'en' => $key, 'de' => 'DE '.$key], 'parent_id' => $parent])->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');
    }

    public function test_subcategory_localization_public_filtering_and_optimistic_updates(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', $root);
        foreach (['public', 'consumers'] as $audience) {
            $this->postJson('/api/v1/admin/cms/faq/items', ['category_id' => $child, 'audience' => $audience, 'published' => true, 'translations' => ['nl' => ['question' => 'Question '.$audience, 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Answer']]]]]]]])->assertCreated();
        }
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$child, ['revision' => 1, 'position' => 4])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.parent_id', $root);
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$child, ['revision' => 1, 'position' => 3])->assertConflict();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/faq?locale=de')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.items')->assertJsonPath('data.0.name', 'DE child')->assertJsonPath('data.0.parent_name', 'DE root')->assertJsonPath('data.0.name_fallback', false)->assertJsonPath('data.0.items.0.audience', 'public');
    }

    public function test_depth_cycles_and_reparenting_a_parent_with_children_are_rejected(): void
    {
        $root = $this->category('root');
        $child = $this->category('child', $root);
        $other = $this->category('other');
        $this->postJson('/api/v1/admin/cms/faq/categories', ['key' => 'deep', 'names' => ['nl' => 'Deep', 'en' => 'Deep'], 'parent_id' => $child])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$child, ['revision' => 1, 'parent_id' => $child])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$root, ['revision' => 1, 'parent_id' => $child])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$root, ['revision' => 1, 'parent_id' => $other])->assertConflict();
        $this->assertDatabaseCount('faq_categories', 3);
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$child, ['revision' => 1, 'parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
    }

    public function test_invalid_german_names_missing_parent_and_non_admin_cannot_mutate(): void
    {
        $root = $this->category('root');
        $this->postJson('/api/v1/admin/cms/faq/categories', ['key' => 'bad-de', 'names' => ['nl' => 'Valid', 'en' => 'Valid', 'de' => ['unsafe']]])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$root, ['revision' => 1, 'names' => []])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$root, ['revision' => 1, 'parent_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/v1/admin/cms/faq/categories/'.$root, ['revision' => 1, 'position' => 0])->assertForbidden();
        $this->assertSame(1, FaqCategory::where('public_id', $root)->sole()->revision);
    }
}
