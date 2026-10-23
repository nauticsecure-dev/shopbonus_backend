<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Consumer\CommunicationPreferences;
use App\Services\Discovery\PublicCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Email notification switches, preferred categories and favourite
 * webshops (Trello #10 §5 and §8, #18). Each is stored separately, so
 * changing one never erases another.
 */
class PreferenceController extends Controller
{
    public function __construct(private readonly CommunicationPreferences $preferences, private readonly PublicCatalog $catalog, private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notifications' => ['sometimes', 'array:'.implode(',', array_keys(CommunicationPreferences::SWITCHES))],
            'notifications.*' => ['boolean'],
            'categories' => ['sometimes', 'array', 'max:12'],
            'categories.*' => ['string', Rule::in(array_keys(config()->array('shopbonus.discovery.categories')))],
        ], ['notifications.array' => 'Unknown notification setting. Allowed: '.implode(', ', array_keys(CommunicationPreferences::SWITCHES)).'.']);

        /** @var User $user */
        $user = $request->user();
        if (isset($data['notifications'])) {
            $this->preferences->update($user, array_map(fn ($v) => (bool) $v, $data['notifications']));
        }
        if (array_key_exists('categories', $data)) {
            DB::transaction(function () use ($user, $data) {
                DB::table('consumer_category_preferences')->where('user_id', $user->id)->delete();
                DB::table('consumer_category_preferences')->insert(array_map(fn ($c) => ['user_id' => $user->id, 'category' => $c], array_values(array_unique($data['categories']))));
            });
        }

        return response()->json(['data' => $this->present($user)]);
    }

    public function favorites(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->cardsFor($this->favoriteIds($request->user()))]);
    }

    public function addFavorite(Request $request, string $slug): JsonResponse
    {
        $webshopId = $this->catalog->listedWebshopId($slug) ?? throw new ApiException('This webshop is not available.', 404, 'webshop_not_found');
        $user = $request->user();

        $added = DB::table('consumer_favorite_webshops')->insertOrIgnore(['user_id' => $user->id, 'webshop_id' => $webshopId, 'created_at' => now()]);
        if ($added > 0) {
            $this->audit->recordSafely('consumer.favorite_added', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id);
        }

        return response()->json(['data' => $this->catalog->cardsFor($this->favoriteIds($user))], $added > 0 ? 201 : 200);
    }

    public function removeFavorite(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        $webshopId = DB::table('webshop_public_profiles')->where('slug', $slug)->value('webshop_id');
        DB::table('consumer_favorite_webshops')->where('user_id', $user->id)->where('webshop_id', $webshopId ?? 0)->delete();

        return response()->json(['data' => $this->catalog->cardsFor($this->favoriteIds($user))]);
    }

    /** @return list<int> */
    private function favoriteIds(User $user): array
    {
        return DB::table('consumer_favorite_webshops')->where('user_id', $user->id)->pluck('webshop_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'notifications' => $this->preferences->current($user),
            'categories' => DB::table('consumer_category_preferences')->where('user_id', $user->id)->orderBy('category')->pluck('category')->all(),
            'mandatory_mail' => 'Account, security and transaction emails are always sent and do not depend on these settings.',
        ];
    }
}
