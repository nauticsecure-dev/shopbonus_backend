<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ContactRequest;
use App\Jobs\DeliverContactMail;
use App\Services\Admin\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContactController extends Controller
{
    public function __invoke(ContactRequest $request, PlatformSettings $settings): JsonResponse
    {
        $requestId = (string) $request->attributes->get('request_id');
        $support = $settings->published()['values']['public.support_email'] ?? null;
        if (! is_string($support) || ! filter_var($support, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(__('contact.unavailable'), 503, 'contact_unavailable');
        }
        try {
            DeliverContactMail::dispatch($request->safe()->only(['name', 'email', 'subject', 'message']), $support, app()->getLocale(), $requestId);
        } catch (Throwable) {
            Log::error('contact.queue_failed', ['request_id' => $requestId]);
            throw new ApiException(__('contact.unavailable'), 503, 'contact_unavailable');
        }
        Log::info('contact.accepted', ['request_id' => $requestId]);

        return response()->json(['data' => ['accepted' => true, 'request_id' => $requestId]], 202, ['Cache-Control' => 'no-store']);
    }
}
