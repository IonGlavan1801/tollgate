<?php

namespace App\Http\Controllers;

use App\Application\SandboxBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SandboxController extends Controller
{
    public function checkout(Request $request, string $order, SandboxBilling $billing): RedirectResponse
    {
        $id = $request->session()->get('lab_session');
        abort_unless(is_string($id), 401);
        try {
            $result = $billing->checkout($id, $order);

            return redirect()->away($result['url']);
        } catch (\DomainException $exception) {
            $request->session()->put('sandbox_selected_order', $order);

            return redirect()->route('lab')->with('sandbox_error', $exception->getMessage());
        }
    }

    public function returned(Request $request, string $order, SandboxBilling $billing): RedirectResponse
    {
        $id = $request->session()->get('lab_session');
        abort_unless(is_string($id), 401);
        $billing->owned($id, $order);
        $request->session()->put('sandbox_selected_order', $order);

        return redirect()->route('lab');
    }
}
