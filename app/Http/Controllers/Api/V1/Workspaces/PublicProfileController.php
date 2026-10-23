<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Http\Controllers\Controller;
use App\Services\Discovery\PublicProfileService;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The webshop's public discovery profile (Trello #18). Plain text only:
 * HTML is refused rather than stored, so nothing can turn into stored XSS.
 */
class PublicProfileController extends Controller
{
    private const NO_HTML = 'not_regex:/<[^>]*>/';

    public function __construct(private readonly PublicProfileService $profiles) {}

    public function show(CurrentWorkspace $current): JsonResponse
    {
        $webshop = $current->webshop();

        return response()->json(['data' => $this->profiles->present($webshop, $webshop->publicProfile)]);
    }

    public function update(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:120', self::NO_HTML],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:160', self::NO_HTML],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000', self::NO_HTML],
            'website_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'categories' => ['sometimes', 'array', 'max:3'],
            'categories.*' => ['string', Rule::in(array_keys(config('shopbonus.discovery.categories')))],
            'slug' => ['sometimes', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'published' => ['sometimes', 'boolean'],
        ]);

        $profile = $this->profiles->save($current->membership(), $data);

        return response()->json(['data' => $this->profiles->present($current->webshop(), $profile->fresh())]);
    }

    public function uploadLogo(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $request->validate(['logo' => [
            'required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp',
            'max:'.config('shopbonus.discovery.logo_max_kb'), 'dimensions:min_width=32,min_height=32,max_width=2000,max_height=2000',
        ]]);

        $profile = $this->profiles->replaceLogo($current->membership(), $request->file('logo'));

        return response()->json(['data' => $this->profiles->present($current->webshop(), $profile)]);
    }

    public function deleteLogo(CurrentWorkspace $current): JsonResponse
    {
        $profile = $this->profiles->removeLogo($current->membership());

        return response()->json(['data' => $this->profiles->present($current->webshop(), $profile)]);
    }
}
