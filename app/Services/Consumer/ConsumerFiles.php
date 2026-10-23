<?php

namespace App\Services\Consumer;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profile photos (Trello #10 §3): private storage, served only to the owner
 * through the API, never as a storage path. The database points at the new
 * file only after it was stored, so a storage failure changes nothing.
 */
class ConsumerFiles
{
    public const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    public function replaceAvatar(User $user, UploadedFile $file): User
    {
        $name = Str::lower((string) Str::ulid()).'.'.$file->guessExtension();
        $dir = 'avatars/'.$user->public_id;

        if (Storage::disk(self::DISK)->putFileAs($dir, $file, $name) === false) {
            throw new ApiException('The photo could not be stored. Please try again.', 503, 'storage_unavailable');
        }

        $old = $user->avatar_path;
        $user->forceFill(['avatar_path' => $dir.'/'.$name, 'avatar_mime' => $file->getMimeType()])->save();
        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }
        $this->audit->recordSafely('consumer.avatar_replaced', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id);

        return $user;
    }

    public function removeAvatar(User $user): User
    {
        if ($user->avatar_path !== null) {
            Storage::disk(self::DISK)->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null, 'avatar_mime' => null])->save();
            $this->audit->recordSafely('consumer.avatar_removed', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id);
        }

        return $user;
    }
}
