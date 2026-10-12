<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        abort_if(strlen($request->getContent()) > config('lab.webhook_limit'), 413);
        abort_unless(in_array($provider, ['stripe', 'paypal', 'mollie'], true), 404);

        return response()->json(['error' => 'Sandbox webhooks are not connected. Simulations use a separate internal inbox.'], 503);
    }
}
