<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingVersion;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlatformSettingController extends Controller
{
    public function index(Request $request, PlatformSettings $settings): JsonResponse
    {
        $data = $request->validate(['key' => ['sometimes', Rule::in(PlatformSettings::KEYS)], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = PlatformSettingVersion::query()->when($data['key'] ?? null,
            fn ($q, $key) => $q->where('setting_id', DB::table('platform_settings')->where('key', $key)->value('id') ?? 0))->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (PlatformSettingVersion $v) => $this->present($v))->all(),
            'published' => $settings->published(), 'allowed_keys' => PlatformSettings::KEYS,
            'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request, PlatformSettings $settings): JsonResponse
    {
        $data = $request->validate(['key' => ['required', Rule::in(PlatformSettings::KEYS)], 'value' => ['present'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $this->present($settings->draft($request->user(), $data['key'], $data['value'], $data['reason']))], 201);
    }

    public function submit(Request $request, string $version, PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $this->present($settings->submit($this->find($version), $request->user()))]);
    }

    public function publish(Request $request, string $version, PlatformSettings $settings): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']])['note'];

        return response()->json(['data' => $this->present($settings->publish($this->find($version), $request->user(), $note))]);
    }

    public function rollback(Request $request, string $version, PlatformSettings $settings): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        return response()->json(['data' => $this->present($settings->rollback($this->find($version), $request->user(), $reason))], 201);
    }

    /** @return array<string, mixed> */
    private function present(PlatformSettingVersion $v): array
    {
        $setting = DB::table('platform_settings')->where('id', $v->setting_id)->first();

        return ['id' => $v->public_id, 'key' => $setting->key, 'version' => $v->version, 'value' => $v->value, 'checksum' => $v->checksum,
            'status' => $v->status, 'reason' => $v->reason, 'created_by' => User::findOrFail($v->created_by)->public_id,
            'reviewed_by' => $v->reviewed_by === null ? null : User::findOrFail($v->reviewed_by)->public_id,
            'review_note' => $v->review_note, 'published_at' => $v->published_at?->toIso8601String(),
            'is_current' => $setting->published_version_id !== null && (int) $setting->published_version_id === $v->id,
            'rollback_of' => $v->rollback_of_id === null ? null : PlatformSettingVersion::findOrFail($v->rollback_of_id)->public_id];
    }

    private function find(string $id): PlatformSettingVersion
    {
        return PlatformSettingVersion::where('public_id', Str::lower($id))->first() ?? throw new NotFoundHttpException;
    }
}
