<?php

namespace App\Http\Resources;

use App\Models\AgreementTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgreementTemplate
 */
class AgreementTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AgreementTemplate $t */
        $t = $this->resource;

        return [
            'id' => $t->public_id,
            'key' => $t->key,
            'version' => $t->version,
            'locale' => $t->locale,
            'title' => $t->title,
            'body' => $t->body,
            'status' => $t->status->value,
            'requires_reacceptance' => $t->requires_reacceptance,
            'published_at' => $t->published_at?->toIso8601String(),
            'retired_at' => $t->retired_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
        ];
    }
}
