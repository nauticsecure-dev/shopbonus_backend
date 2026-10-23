<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Browsable API documentation, rendered from the files in docs/api (the
 * single source of truth). Enabled by API_DOCS_ENABLED (default: every
 * environment except production).
 */
class ApiDocsController extends Controller
{
    public function index(): View
    {
        return view('docs.openapi');
    }

    public function spec(): Response
    {
        return response((string) file_get_contents(base_path('docs/api/openapi.yaml')), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function reference(): View
    {
        $html = Str::markdown((string) file_get_contents(base_path('docs/api/README.md')), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return view('docs.reference', ['html' => $html]);
    }
}
