<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

final class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $locale = $user->effectiveLocale();

            app()->setLocale($locale);
            Date::setLocale($locale);
            Number::useLocale($locale);
        }

        return $next($request);
    }
}
