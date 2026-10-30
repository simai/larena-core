# Sites and domains

Core keeps each site's domains and finds the site of a request, so this works with no admin or storage package
(Larena Headless). Sites plan, stage 2 (larena-specs `docs/architecture/2026-10-29-sites-work-plan.md`).

## Domains of a site

`Larena\Core\Contracts\SiteBindings` (bound to `Site\DatabaseSiteBindings`, table `larena_core_site_bindings`):

- `list(site)`, `all(environment)`: a site's domains in its order.
- `replace(site, entries, actor)`: the whole list in one validated write. An entry has `host` and may have `folder`,
  `locale`, `role` (`main`, `alias`, `redirect`; default `alias`) and `environment` (`production`, `staging`,
  `development`; default `production`).
- `defaultSite()`, `setDefaultSite(site, actor)`: the site a request gets when no domain matches
  (table `larena_core_default_site`, one row). With none marked, the product's root scope
  (`ScopeBaselineInstaller::defaultSiteRef()`, `site:main` for the CMS) stands in.

Refusals are `Exceptions\SiteBindingRejected` with a stable code and the entry's position:

| Code | When |
|---|---|
| `site_binding_site_unknown` / `site_binding_site_archived` | not a site scope, or archived |
| `site_binding_list_invalid` | not a list of entries, or more than 200 |
| `site_binding_host_invalid` | not a lowercase DNS name (`*.` allowed as the first label; punycode for international names; no scheme, port or path) |
| `site_binding_folder_invalid` | not empty or `/seg(/seg)*` of `[a-z0-9_-]`, or with a trailing slash |
| `site_binding_locale_invalid` / `site_binding_role_invalid` / `site_binding_environment_invalid` | unknown value |
| `site_binding_duplicate` | the same host and folder twice in one environment |
| `site_binding_taken` | the host and folder belong to another site in that environment |
| `site_binding_main_duplicate` / `site_binding_main_missing` | not exactly one main per language and environment |

The first entry is always the main one. An entry without a language stands for every language: a language without
its own main uses the environment's main for every language.

## Environment

`larena-core.environment` (`LARENA_ENVIRONMENT`) names it. Empty follows `APP_ENV`: `production` is production,
`local` and `testing` are development, anything else is staging. Only this environment's domains are served.

## Finding the site

`Site\SiteResolver` reads a compiled map (`Site\SiteMap`) of this environment: exact host with its longest folder,
then the closest `*.` host with its longest folder (`*.example.ru` takes subdomains of any depth, never
`example.ru`), then the default site. Folders match on a segment boundary: `/shop` takes `/shop/x`, not
`/shopping`. An exact host whose folders do not match falls through to `*.` hosts, then to the default site.

The map lives in the process for a few seconds, in the exported file, in the configured cache
(`larena-core.sites.cache_store`, empty is the default store) and only then comes from the database. Every write
of domains or of the default site clears all of them, the exported file included. `php artisan larena:sites:cache`
exports the map to `bootstrap/cache/larena-sites.php`; `--clear` removes it and the cached one. Without a
database (the installer) or before the migration, every request gets the default site.

## Middleware

The core provider adds `Site\ResolveSite` as global HTTP middleware, before routing, so a redirect domain is sent to
the main one for any path (the router answers 404 before a group's middleware runs); `larena-core.sites.middleware = false` leaves it to the application. It binds
`Site\CurrentSite` (site, domain or null, language, folder, how it matched) in the container and as the
`larena.site` request attribute. Outside a request `CurrentSite` is the default site.

A GET or HEAD on a `redirect` domain answers 301 to the site's main domain for the same language (or every
language) and environment, with the folder swapped and the rest of the path and the query kept. The target comes
from stored domains only, never from the Host header; the scheme is `larena-core.sites.scheme` or follows the
request. Paths under `larena-core.sites.redirect_except` (default `admin`) and other methods are served where they
arrive: the admin lives on a site's domain too, and a form post is never turned into a GET.
