# Changelog

- Several sites (sites plan, steps 2.8 and 2.9). `Site\SiteScopes` makes a site: the scope `site:<key>` and the data reference `scope:<key>` it stores under, the key `[a-z][a-z0-9_-]{1,62}` and `site` reserved; `Exceptions\SiteCreationRejected` (`site_key_invalid`, `site_key_taken`, `site_name_invalid`). `Contracts\SiteBindings` gains `planAdd()` and `add()`: the domains a pack carries are added beside the site's own, each checked as `replace()` checks them, and a domain another site holds refuses the plan by name. `Site\SiteBindingsArtifact` is those domains in a SitePack (`artifacts/site/bindings.jsonl`, one line a domain: host, folder, language, role, environment). `Site\ResolveSite` forgets the request-scoped instances when the site of a request differs from the one before it, so a process that serves several sites never answers one with another's; `SiteResolver::markedDefault()` tells the site marked default from the product's root scope.
- Sites and domains (sites plan, stages 2.1 and 2.2; `docs/sites.md`): `Contracts\SiteBindings` keeps each site's domains (`larena_core_site_bindings`: host with `*.` allowed, optional folder, language, role main/alias/redirect, environment) and the default site (`larena_core_default_site`), replaced as one validated list with `site_binding_*` codes. `Site\SiteResolver` finds a request's site from a compiled map (exact host, then `*.` host, longest folder on a segment boundary, then the default site, else the root scope), kept in the process, the configured cache and `bootstrap/cache/larena-sites.php` (`larena:sites:cache`, `--clear`), cleared by every write. `Site\ResolveSite` is global HTTP middleware (before routing, so any path of an old domain is redirected), binds `Site\CurrentSite` and answers 301 from redirect domains to the main one (GET/HEAD, not under `/admin`). `larena-core.environment` follows APP_ENV when empty. Without domains nothing changes.
- A first-run step may offer options beside the form (`Contracts\FirstRunOptions`, owner decision 2026-10-29): `FirstRunCoordinator::options()` lists them in step order and refuses a key offered twice; the person's choices travel in `FirstRunPayload::$options`, read with `option()`, each off unless turned on.
## Unreleased

- Each product declares its first run (owner decision 2026-10-29): `larena-core.first_run.steps` (default the Minimal CMS `auth, setting, site`) is the exact contributor list `FirstRunCoordinator` requires, so a product without storage completes its first run with `auth, setting`; `auth` is always a step (`FirstRunCoordinator::declaredSteps()`). `larena-core.first_run.root_scope` and `root_scope_label` (default `site:main`, `Main site`) name the root scope `ScopeBaselineInstaller` creates with its Groups plane and Administrators node. No change for the CMS.
### Added

- Shared external-process runner: `Larena\Core\Process\ProcessExecutor` with a shell-free
  `proc_open` implementation (argv only, exact environment, stdin bytes or stream, timeout with
  SIGTERM then SIGKILL, output caps with a truncation flag, exit code and duration in an immutable
  result) and a recording executor for tests. Bound in the container.
- Generic external command gateway contract: `Larena\Core\Gateway\GatewayAdapter` declares a
  manifest (id, protocol, protocol version, allowed commands); `GatewayRunner` refuses undeclared
  commands, unfinished processes and answers in another protocol or version.

### Changed

- Added a deployment-time route receipt for immutable asset bundles so HTTP
  requests can fail closed on the exact renderable files without recursively
  hashing the complete published source trees on every page load.
- Removed Access, Audit and Licensing from Core's mandatory Composer runtime; they remain development-only compatibility fixtures until B4 purifies historical integration code.
- Replaced remaining upper-package source imports with Core-owned fail-closed adapter and lifecycle-hook contracts.
- Made the runtime-security smoke exercise only Core operation contracts and sanitization.

### Documentation

- Bind the continuation repository to the accepted Minimal CMS v1 Core role and dependency-free boundary.

### Non-claims

- No Accepted Target State, package version or mandatory dependency change is introduced by B4.
