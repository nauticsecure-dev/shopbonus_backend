<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Consumer\CommunicationPreferences;
use App\Services\Consumer\ConsumerFiles;
use App\Services\Consumer\EmailChange;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in consumer's own profile (Trello #10 §3–4). The user always
 * comes from the session; no route or field can address another account.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmailChange $emailChange,
        private readonly CommunicationPreferences $preferences,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:60', "regex:/^[\\pL\\pM][\\pL\\pM' .-]*$/u"],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'locale' => ['sometimes', Rule::in(User::SUPPORTED_LOCALES)],
        ]);

        /** @var User $user */
        $user = $request->user();
        if (array_key_exists('phone', $data) && $data['phone'] !== null) {
            $data['phone'] = PhoneNumber::normalize($data['phone'])
                ?? throw ValidationException::withMessages(['phone' => 'Enter a valid phone number with country code, e.g. +31 6 1234 5678.']);
        }

        $user->forceFill($data)->save();
        if ($data !== []) {
            $this->audit->recordSafely('consumer.profile_updated', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id, metadata: ['fields' => array_keys($data)]);
        }

        return response()->json(['data' => $this->present($user)]);
    }

    public function uploadAvatar(Request $request, ConsumerFiles $files): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp',
            'max:2048', 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000']]);

        return response()->json(['data' => $this->present($files->replaceAvatar($request->user(), $request->file('avatar')))]);
    }

    public function deleteAvatar(Request $request, ConsumerFiles $files): JsonResponse
    {
        return response()->json(['data' => $this->present($files->removeAvatar($request->user()))]);
    }

    public function avatar(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->avatar_path === null || ! Storage::disk(ConsumerFiles::DISK)->exists($user->avatar_path)) {
            throw new ApiException('No profile photo.', 404, 'not_found');
        }

        return Storage::disk(ConsumerFiles::DISK)->response($user->avatar_path, 'avatar', [
            'Content-Type' => (string) $user->avatar_mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        $pending = $this->emailChange->pending($user);

        return [
            'id' => $user->public_id,
            'first_name' => $user->first_name,
            'email' => $user->email,
            'email_verification' => $user->email_verified_at !== null ? 'verified' : 'required',
            'pending_email' => $pending?->new_email,
            'phone' => $user->phone,
            'locale' => $user->preferredLocale(),
            'avatar_url' => $user->avatar_path === null ? null : route('api.v1.consumer.profile.avatar.show', ['v' => substr(md5($user->avatar_path), 0, 8)]),
            // Factual security information only (Trello #10 "Security status").
            'security' => [
                'email_verified' => $user->email_verified_at !== null,
                'two_factor' => 'not_available',
            ],
            'notifications' => $this->preferences->current($user),
        ];
    }
}
