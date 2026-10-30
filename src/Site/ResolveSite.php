<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request's site in the container (CurrentSite, also the `larena.site` request attribute). A GET or HEAD
 * on a redirect domain answers 301 to the site's main domain, except under the paths listed in
 * `larena-core.sites.redirect_except` (the admin by default): the admin lives on a site's domain too and must stay
 * reachable on every one of them. Other methods are served where they arrive, so a form post is never turned into
 * a GET.
 */
final class ResolveSite
{
    /** @param list<string> $redirectExcept path prefixes, segment-bounded, without the leading slash */
    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly Container $container,
        private readonly array $redirectExcept = ['admin'],
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();
        $current = $this->resolver->resolve($request->getHost(), $path);
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $target = $this->excepted($path) ? null : $this->resolver->redirectTarget($current, $path, $request->getQueryString(), $request->isSecure());
            if ($target !== null) {
                return new RedirectResponse($target, 301);
            }
        }
        $this->container->instance(CurrentSite::class, $current);
        $request->attributes->set(CurrentSite::ATTRIBUTE, $current);

        return $next($request);
    }

    private function excepted(string $path): bool
    {
        $path = '/'.ltrim($path, '/');
        foreach ($this->redirectExcept as $prefix) {
            if (SiteMap::inFolder('/'.trim($prefix, '/'), $path)) {
                return true;
            }
        }

        return false;
    }
}
