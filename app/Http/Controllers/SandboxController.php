<?php

namespace App\Http\Controllers;

use App\Application\SandboxBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        if (! is_string($id)) {
            return $this->expiredReturn();
        }
        try {
            $billing->owned($id, $order);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 401) {
                throw $exception;
            }

            return $this->expiredReturn();
        }
        $request->session()->put('sandbox_selected_order', $order);

        return redirect()->route('lab');
    }

    private function expiredReturn(): RedirectResponse
    {
        return redirect()->route('lab')->with('sandbox_return_notice', 'Your browser session expired. Provider webhooks still verify payment results. Start a new session to continue.');
    }
}
