<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final readonly class IsolateAuthenticationSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = config('app.sysadmin_domain');

        if (! $domain) {
            return $next($request);
        }

        if (self::context($request) !== 'sysadmin') {
            $request->cookies->remove(Auth::guard('sysadmin')->getRecallerName());

            return $next($request);
        }

        abort_unless(
            $request->route()?->getDomain() === $domain
            || ($request->routeIs('loginLinkLogin') && app()->isLocal()
                && $request->input('guard') === 'sysadmin'
                && $request->input('user_model') === config('auth.providers.system_administrators.model'))
            || $request->routeIs(
                'livewire.*',
                'default-livewire.update',
                'filament.exports.*',
                'filament.imports.*',
                'media.show',
                'blog.preview',
            ),
            404,
        );
        $original = config('session');
        $guard = config('auth.defaults.guard');
        $session = array_replace($original, config('system-admin.session'));

        throw_unless(
            in_array($session['driver'], ['database', 'redis', 'file'], true),
            InvalidArgumentException::class,
            'Staff sessions require the database, redis, or file driver.',
        );

        if ($request->isSecure()) {
            $session['cookie'] = '__Host-'.$session['cookie'];
            $session['secure'] = true;
        }

        if ($session['driver'] === 'file') {
            File::ensureDirectoryExists($session['files']);
        }

        $this->configure($session);
        Auth::shouldUse('sysadmin');
        $request->cookies->remove(Auth::guard('web')->getRecallerName());

        try {
            return $next($request);
        } finally {
            $this->configure($original);
            Auth::shouldUse($guard);
        }
    }

    public static function context(Request $request): string
    {
        return $request->getHost() === config('app.sysadmin_domain') ? 'sysadmin' : 'web';
    }

    /** @param array<string, mixed> $config */
    private function configure(array $config): void
    {
        config()->set('session', $config);
        resolve(SessionManager::class)->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        app()->forgetInstance('auth.driver');
        Cookie::setDefaultPathAndDomain($config['path'], $config['domain'], $config['secure'], $config['same_site']);
    }
}
