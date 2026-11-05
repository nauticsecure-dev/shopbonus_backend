<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsPage;
use App\Models\User;
use Illuminate\Support\Str;

/** Compatibility envelope for old page editors; queued results use the canonical proposal API. */
class CmsQueuedCopy
{
    public function __construct(private readonly CmsSuggestions $suggestions) {}

    public function assertConfigured(string $key): void
    {
        if (! config('cms.ai_enabled') || (string) config('cms.api_key') === '' || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', (string) config('cms.model'))) {
            $code = match ($key) {
                'homepage' => 'homepage_ai_not_configured', 'over-ons' => 'about_us_ai_not_configured', default => 'merchant_landing_ai_not_configured'
            };
            throw new ApiException('CMS AI suggestions are not configured.', 503, $code);
        }
    }

    /** @return array<string,mixed> */
    public function request(CmsPage $page, User $actor, string $locale, string $section, ?string $focus, ?string $key): array
    {
        $context = ['section' => $section];
        if ($focus !== null) {
            $context['focus'] = $focus;
        }
        try {
            $s = $this->suggestions->request($page, 'improve', $locale, $locale, $key ?? ('legacy-'.Str::ulid()), $actor, $context);
        } catch (ApiException $e) {
            if ($e->errorCode === 'cms_ai_not_configured') {
                $code = match ($page->key) {
                    'homepage' => 'homepage_ai_not_configured', 'over-ons' => 'about_us_ai_not_configured', default => 'merchant_landing_ai_not_configured'
                };
                throw new ApiException('CMS AI suggestions are not configured.', 503, $code);
            }
            throw $e;
        }
        $rows = [];
        if ($s->status === 'proposed' && $s->proposed !== null) {
            foreach (CmsTextBindings::discover($s->proposed, $page->type, $page->key) as $binding) {
                if (str_starts_with($binding['path'], $section.'.')) {
                    $rows[] = ['field' => $binding['path'], 'suggestion' => $binding['text']];
                }
            }
        }

        return ['suggestions' => $rows, 'applied' => false, 'proposal' => ['id' => $s->public_id, 'status' => $s->status, 'section' => $section, 'poll_url' => route('api.v1.admin.cms.suggestions.show', ['suggestion' => $s->public_id]), 'independent_review_required' => true]];
    }
}
