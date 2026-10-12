<?php

namespace App\Http\Controllers;

use App\Application\Billing;
use App\Application\DemoSessions;
use App\Application\Scenarios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LabApiController extends Controller
{
    public function createSession(DemoSessions $sessions): JsonResponse
    {
        [$session, $token] = $sessions->create();

        return response()->json(['id' => $session->id, 'token' => $token, 'expires_at' => $session->expires_at, 'mode' => 'simulated'], 201);
    }

    private function session(Request $request): string
    {
        return $request->attributes->get('demo_session_id');
    }

    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key', '');
        abort_if(strlen($key) < 8 || strlen($key) > 100, 422, 'Idempotency-Key is required (8–100 characters).');

        return $key;
    }

    public function order(Request $request, Billing $billing): JsonResponse
    {
        $data = $request->validate(['price_id' => 'required|integer|min:1', 'provider' => 'required|in:stripe,paypal,mollie']);
        $order = $billing->createOrder($this->session($request), $data['price_id'], $data['provider'], $this->key($request));

        return response()->json(['order' => $order], 201);
    }

    public function checkout(Request $request, string $id, Billing $billing): JsonResponse
    {
        $data = $request->validate(['outcome' => 'required|in:succeeded,failed,requires_action,timeout']);

        return response()->json(['order' => $billing->checkout($this->session($request), $id, $data['outcome'], $this->key($request))], 202);
    }

    public function preview(Request $request, string $id, Billing $billing): JsonResponse
    {
        $data = $request->validate(['price_id' => 'required|integer|min:1']);

        return response()->json($billing->preview($this->session($request), $id, $data['price_id']));
    }

    public function change(Request $request, string $id, Billing $billing): JsonResponse
    {
        $data = $request->validate(['preview_id' => 'required|uuid']);
        $billing->confirmChange($this->session($request), $id, $data['preview_id']);

        return response()->json(['status' => 'applied']);
    }

    public function refund(Request $request, string $id, Billing $billing): JsonResponse
    {
        $data = $request->validate(['amount_minor' => 'required|integer|min:1|max:100000000']);

        return response()->json(['id' => $billing->refund($this->session($request), $id, $data['amount_minor'], $this->key($request))], 201);
    }

    public function scenario(Request $request, string $scenario, Scenarios $scenarios): JsonResponse
    {
        $data = $request->validate(['provider' => 'sometimes|in:stripe,paypal,mollie']);

        return response()->json(['order' => $scenarios->run($this->session($request), $scenario, $data['provider'] ?? 'stripe'), 'mode' => 'simulated'], 202);
    }

    public function events(Request $request, string $id): JsonResponse
    {
        abort_unless($id === $this->session($request), 404);
        $data = $request->validate(['after' => 'sometimes|integer|min:0']);
        $events = DB::table('audit_events')->where('demo_session_id', $id)->where('id', '>', $data['after'] ?? 0)->orderBy('id')->limit(100)->get(['id', 'correlation_id', 'action', 'status', 'details', 'created_at']);

        return response()->json(['events' => $events]);
    }
}
