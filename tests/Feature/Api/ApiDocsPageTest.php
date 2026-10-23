<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * The browsable docs at /docs render the committed docs/api files.
 */
class ApiDocsPageTest extends TestCase
{
    public function test_docs_pages_render_the_committed_spec_and_reference(): void
    {
        $this->get('/docs')->assertOk()->assertSee('redoc', false)->assertSee(route('docs.spec'), false);
        $this->get('/docs/openapi.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=UTF-8')
            ->assertSee('openapi: 3.1', false);
        $this->get('/docs/reference')->assertOk()->assertSee('<h2', false)->assertSee('X-Request-Id');
        $this->getJson('/')->assertOk()->assertJsonPath('docs', url('/docs'));
    }

    public function test_reference_escapes_raw_html_from_the_markdown(): void
    {
        $this->assertStringNotContainsString('<script', $this->get('/docs/reference')->getContent());
    }
}
