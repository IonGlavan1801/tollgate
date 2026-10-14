<?php

namespace App\Http\Controllers;

use App\Application\SandboxWebhooks;
use App\Exceptions\SandboxRejected;
use App\Exceptions\SandboxUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        abort_if(strlen($request->getContent()) > config('lab.webhook_limit'), 413);
        abort_unless(in_array($provider, ['stripe', 'paypal', 'mollie'], true), 404);

        abort_unless(config('lab.sandbox_enabled'), 503, 'Sandbox webhooks are disabled.');
        try {
            $result = app(SandboxWebhooks::class)->ingest($provider, $request);
        } catch (SandboxUnavailable|SandboxRejected) {
            return response()->json(['error' => 'Provider verification is unavailable. Retry later.'], 503);
        }

        return response()->json($result, in_array($result['status'], ['rejected', 'rejected_conflict'], true) ? 400 : 200);
    }
}
