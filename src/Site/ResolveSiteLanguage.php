<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Larena\Core\Contracts\SiteLanguages;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request's language in the container (CurrentLanguage, also the `larena.language` request attribute). It runs
 * after ResolveSite and before the redirects. The order (larena-specs docs/architecture/2026-10-29-sites-pages-languages.md
 * §4.4): the language of the domain the request came by; else the first segment of the path inside the site, when it is
 * a language of the site with a folder; else the site's main language.
 *
 * The site's languages are read only for a first segment shaped like a language code, so an ordinary request reads no
 * setting here.
 */
final class ResolveSiteLanguage
{
    private const CODE = '/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/D';

    public function __construct(private readonly SiteLanguages $languages, private readonly Container $container)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $current = $request->attributes->get(CurrentSite::ATTRIBUTE);
        if ($current instanceof CurrentSite) {
            $language = $this->of($current, $request->getPathInfo());
            $this->container->instance(CurrentLanguage::class, $language);
            $request->attributes->set(CurrentLanguage::ATTRIBUTE, $language);
        }

        return $next($request);
    }

    public function of(CurrentSite $current, string $path): CurrentLanguage
    {
        $site = $current->site->toString();
        $main = fn (): string => $this->languages->main($site);
        $inSite = $current->pathInSite('/' . ltrim($path, '/'));
        if (is_string($current->locale) && $current->locale !== '') {
            return new CurrentLanguage($current->locale, $inSite, '', $main);
        }
        $segment = explode('/', ltrim($inSite, '/'), 2)[0];
        if (preg_match(self::CODE, $segment) === 1 && in_array($segment, $this->languages->languages($site), true)
            && ($segment !== $this->languages->main($site) || $this->languages->mainInFolder($site))) {
            $rest = substr($inSite, strlen($segment) + 1);

            return new CurrentLanguage($segment, $rest === '' ? '/' : $rest, '/' . $segment, $main);
        }

        return new CurrentLanguage(null, $inSite, '', $main);
    }
}
