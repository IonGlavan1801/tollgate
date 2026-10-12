<?php

namespace App\Http\Middleware;

use App\Application\DemoSessions;
use App\Models\DemoSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless($token && strlen($token) === 64, 401, 'A demo bearer token is required.');
        $session = DemoSession::where('token_hash', hash('sha256', $token))->first();
        abort_unless($session !== null, 401, 'Invalid demo token.');
        app(DemoSessions::class)->requireActive($session->id);
        $request->attributes->set('demo_session_id', $session->id);

        return $next($request);
    }
}
