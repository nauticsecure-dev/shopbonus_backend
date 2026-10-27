<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CmsAsset;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\User;
use App\Services\Cms\CmsCache;
use App\Services\Cms\CmsService;
use App\Services\Cms\RichText;
use App\Services\Identity\LegalConsents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Published content only (Trello #5): pages, page index, FAQ and images.
 * Drafts never appear here. FAQ audience restrictions are enforced here,
 * not just hidden in the frontend.
 */
class PublicContentController extends Controller
{
    public function legalRequirements(Request $request, LegalConsents $legal): JsonResponse
    {
        $data = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)]]);

        return response()->json(['data' => $legal->requirements($data['locale'] ?? 'nl')], 200, ['Cache-Control' => 'no-store']);
    }

    public function page(string $locale, string $slug): JsonResponse
    {
        if (! in_array($locale, CmsService::LOCALES, true)) {
            throw new ApiException('Page not found.', 404, 'not_found');
        }
        $data = CmsCache::remember("page:{$locale}:{$slug}", function () use ($locale, $slug) {
            $l = CmsPageLocale::where('locale', $locale)->where('slug', $slug)->whereNotNull('published_revision_id')->first();
            $page = $l === null ? null : CmsPage::whereKey($l->page_id)->whereNull('archived_at')->where('audience', 'public')->first();
            if ($l === null || $page === null) {
                return null;
            }
            $r = CmsRevision::findOrFail($l->published_revision_id);
            $alternates = CmsPageLocale::where('page_id', $page->id)->whereNotNull('published_revision_id')->pluck('slug', 'locale')->all();

            return ['key' => $page->key, 'type' => $page->type, 'locale' => $locale, 'slug' => $slug, 'content' => $this->publicContent($r->content),
                'legal' => $r->legal_version === null ? null : ['version' => $r->legal_version, 'effective_date' => $r->effective_date?->format('Y-m-d')],
                'revision' => $r->number, 'published_at' => $r->created_at->toIso8601String(), 'alternates' => $alternates];
        });

        return response()->json(['data' => $data ?? throw new ApiException('Page not found.', 404, 'not_found')], 200, ['Cache-Control' => 'public, max-age=60']);
    }

    public function pages(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)], 'type' => ['sometimes', Rule::in(CmsPage::TYPES)]]);
        $locale = $data['locale'] ?? 'nl';

        $rows = CmsCache::remember('pages:'.$locale.':'.($data['type'] ?? '*'), fn (): array => DB::table('cms_page_locales')->join('cms_pages', 'cms_pages.id', '=', 'cms_page_locales.page_id')
            ->join('cms_revisions', 'cms_revisions.id', '=', 'cms_page_locales.published_revision_id')
            ->where('cms_page_locales.locale', $locale)->whereNull('cms_pages.archived_at')->where('cms_pages.audience', 'public')
            ->when(isset($data['type']), fn ($q) => $q->where('cms_pages.type', $data['type']))->orderBy('cms_pages.key')
            ->get(['cms_pages.key', 'cms_pages.type', 'cms_page_locales.slug', 'cms_revisions.content'])
            ->map(fn (object $row): array => ['key' => $row->key, 'type' => $row->type, 'slug' => $row->slug, 'title' => json_decode((string) $row->content, true)['title'] ?? null])->all());

        return response()->json(['data' => $rows]);
    }

    public function faq(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)], 'search' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $locale = $data['locale'] ?? 'nl';
        /** @var User|null $user */
        $user = $request->user('sanctum');
        $audiences = ['public'];
        if ($user !== null && $user->email_verified_at !== null) {
            $audiences[] = 'consumers';
            if (DB::table('webshop_memberships')->where('user_id', $user->id)->where('status', 'active')->exists()) {
                $audiences[] = 'merchants';
            }
        }
        $search = mb_strtolower(trim((string) ($data['search'] ?? '')));

        $categories = FaqCategory::orderBy('position')->get()->map(function (FaqCategory $c) use ($locale, $audiences, $search) {
            $items = FaqItem::where('category_id', $c->id)->where('published', true)->whereIn('audience', $audiences)->orderBy('position')->get()
                ->map(function (FaqItem $i) use ($locale) {
                    $t = $i->translations[$locale] ?? $i->translations['nl'] ?? null;

                    return $t === null ? null : ['id' => $i->public_id, 'question' => $t['question'], 'answer' => $t['answer'], 'audience' => $i->audience, 'fallback' => ! isset($i->translations[$locale])];
                })->filter()
                ->filter(fn ($i) => $search === '' || str_contains(mb_strtolower($i['question'].' '.RichText::plainText($i['answer'])), $search))->values();

            return ['key' => $c->key, 'name' => $c->names[$locale] ?? $c->names['nl'], 'items' => $items->all()];
        })->filter(fn ($c) => $c['items'] !== [])->values();

        return response()->json(['data' => $categories->all()], 200, ['Cache-Control' => count($audiences) > 1 ? 'private, no-store' : 'public, max-age=60']);
    }

    public function asset(string $id): Response
    {
        $asset = CmsAsset::where('public_id', $id)->first() ?? throw new ApiException('Image not found.', 404, 'not_found');

        return Storage::disk('local')->response($asset->path, 'image', ['Content-Type' => $asset->mime, 'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"], 'inline');
    }

    /**
     * Resolves image asset IDs to URLs for rendering.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function publicContent(array $content): array
    {
        $walk = function (array $node) use (&$walk): array {
            if (($node['type'] ?? null) === 'image') {
                $node['attrs']['src'] = route('api.v1.public.content-assets.show', ['id' => $node['attrs']['asset_id']]);
            }
            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = array_map(fn ($c) => is_array($c) ? $walk($c) : $c, $node['content']);
            }

            return $node;
        };
        $content['body'] = $walk($content['body']);

        return $content;
    }
}
