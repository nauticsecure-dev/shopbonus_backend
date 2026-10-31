<?php

namespace Tests\Concerns;

use App\Models\CookieConsent;
use App\Models\CookiePolicy;
use App\Models\User;

trait TracksWithMarketingConsent
{
    protected function marketingConsentQuery(): string
    {
        $policy = CookiePolicy::current();
        if ($policy === null) {
            $actor = User::factory()->create();
            $policy = CookiePolicy::create([
                'version' => 'fixture-marketing-1', 'status' => 'published', 'requires_reconsent' => false,
                'created_by' => $actor->id, 'published_by' => $actor->id, 'published_at' => now(),
                'categories' => [
                    ['key' => 'necessary', 'required' => true, 'trackers' => []],
                    ['key' => 'marketing', 'required' => false, 'trackers' => []],
                ],
                'texts' => ['en' => ['title' => 'Test cookies', 'body' => 'Synthetic consent fixture']],
            ]);
        }
        $id = 'test-marketing-'.substr(hash('sha256', $policy->version), 0, 20);
        CookieConsent::firstOrCreate(['consent_id' => $id, 'policy_version' => $policy->version], [
            'choices' => ['necessary' => true, 'marketing' => true], 'action' => 'customize',
        ]);

        return '?consent_id='.$id;
    }
}
