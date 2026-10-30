<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Larena\Core\Contracts\SiteBinding;
use Larena\Core\Enums\SiteBindingRole;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Exceptions\SiteBindingRejected;
use Larena\Core\Scope\ScopeRef;

/**
 * The rules of a site's domain list, checked before anything is stored: a lowercase DNS host (or `*.` and one),
 * punycode for international names, no scheme or port; a folder of lowercase path segments without a trailing
 * slash; the first entry is the main one; one main per language and environment, where an entry without a language
 * stands for every language; a host and folder once per environment.
 */
final class SiteBindingRules
{
    public const MAX_BINDINGS = 200;
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    private const FOLDER = '~\A(?:/[a-z0-9_-]{1,64})+\z~D';
    private const LOCALE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})?\z/D';

    /**
     * @param array<mixed> $entries what an editor sent: checked here, not trusted
     * @return list<SiteBinding>
     */
    public static function normalize(ScopeRef $site, array $entries): array
    {
        if (!array_is_list($entries) || count($entries) > self::MAX_BINDINGS) {
            throw new SiteBindingRejected(SiteBindingRejected::LIST_INVALID);
        }
        $bindings = [];
        $addresses = [];
        foreach ($entries as $position => $entry) {
            if (!is_array($entry)) {
                throw new SiteBindingRejected(SiteBindingRejected::LIST_INVALID, $position);
            }
            $binding = new SiteBinding(
                $site,
                $position,
                self::host($entry['host'] ?? null, $position),
                self::folder($entry['folder'] ?? null, $position),
                self::locale($entry['locale'] ?? null, $position),
                $position === 0 ? SiteBindingRole::Main : self::role($entry['role'] ?? null, $position),
                self::environment($entry['environment'] ?? null, $position),
            );
            $address = self::address($binding);
            if (isset($addresses[$address])) {
                throw new SiteBindingRejected(SiteBindingRejected::DUPLICATE, $position);
            }
            $addresses[$address] = true;
            $bindings[] = $binding;
        }
        self::requireOneMain($bindings);

        return $bindings;
    }

    public static function isHost(string $host): bool
    {
        $name = str_starts_with($host, '*.') ? substr($host, 2) : $host;

        return strlen($host) <= 253 && preg_match('/\A'.self::LABEL.'(?:\.'.self::LABEL.')*\z/D', $name) === 1;
    }

    /** One key per host, folder and environment: what may belong to one site only. */
    public static function address(SiteBinding $binding): string
    {
        return $binding->environment->value.' '.$binding->host.($binding->folder ?? '');
    }

    /** @param list<SiteBinding> $bindings */
    private static function requireOneMain(array $bindings): void
    {
        $mains = [];
        $groups = [];
        foreach ($bindings as $binding) {
            $group = $binding->environment->value.' '.($binding->locale ?? '');
            $groups[$group] ??= $binding->position;
            if ($binding->role !== SiteBindingRole::Main) {
                continue;
            }
            if (isset($mains[$group])) {
                throw new SiteBindingRejected(SiteBindingRejected::MAIN_DUPLICATE, $binding->position);
            }
            $mains[$group] = true;
        }
        foreach ($groups as $group => $position) {
            // A language without its own main uses the environment's main for every language.
            $everyLanguage = substr($group, 0, (int) strpos($group, ' ')).' ';
            if (!isset($mains[$group]) && !isset($mains[$everyLanguage])) {
                throw new SiteBindingRejected(SiteBindingRejected::MAIN_MISSING, $position);
            }
        }
    }

    private static function host(mixed $value, int $position): string
    {
        $host = is_string($value) ? strtolower(trim($value)) : '';
        if ($host === '' || !self::isHost($host)) {
            throw new SiteBindingRejected(SiteBindingRejected::HOST_INVALID, $position);
        }

        return $host;
    }

    private static function folder(mixed $value, int $position): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 191 || preg_match(self::FOLDER, $value) !== 1) {
            throw new SiteBindingRejected(SiteBindingRejected::FOLDER_INVALID, $position);
        }

        return $value;
    }

    private static function locale(mixed $value, int $position): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match(self::LOCALE, $value) !== 1) {
            throw new SiteBindingRejected(SiteBindingRejected::LOCALE_INVALID, $position);
        }

        return $value;
    }

    private static function role(mixed $value, int $position): SiteBindingRole
    {
        $role = $value === null ? SiteBindingRole::Alias : (is_string($value) ? SiteBindingRole::tryFrom($value) : null);

        return $role ?? throw new SiteBindingRejected(SiteBindingRejected::ROLE_INVALID, $position);
    }

    private static function environment(mixed $value, int $position): SiteEnvironment
    {
        $environment = $value === null ? SiteEnvironment::Production : (is_string($value) ? SiteEnvironment::tryFrom($value) : null);

        return $environment ?? throw new SiteBindingRejected(SiteBindingRejected::ENVIRONMENT_INVALID, $position);
    }
}
