<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class RemoveForeignGuardSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.sysadmin_domain')) {
            $guard = Auth::guard(IsolateAuthenticationSession::context($request) === 'sysadmin' ? 'web' : 'sysadmin');
            $request->session()->forget($guard->getName());
            $guard->forgetUser();
        }

        return $next($request);
    }
}
