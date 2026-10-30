<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Larena\Core\Contracts\SiteBinding;
use Larena\Core\Enums\SiteBindingRole;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Enums\SiteMatch;
use Larena\Core\Scope\ScopeRef;

/**
 * Every site's domains in one environment, compiled for lookup by host: exact hosts, `*.` suffixes (any depth of
 * subdomain, never the bare domain), each host's folders longest first, the main domain of each site and language,
 * and the default site. It is a plain array underneath, so it caches and exports as is.
 *
 * @phpstan-type Entry array{site_ref:string,position:int,host:string,folder:?string,locale:?string,role:string,environment:string}
 * @phpstan-type Compiled array{schema_version:int,environment:string,default_site:?string,exact:array<string,list<Entry>>,wildcard:array<string,list<Entry>>,mains:array<string,array<string,Entry>>}
 */
final class SiteMap
{
    public const SCHEMA_VERSION = 1;

    /** @param Compiled $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param list<SiteBinding> $bindings */
    public static function compile(SiteEnvironment $environment, array $bindings, ?ScopeRef $defaultSite): self
    {
        $exact = [];
        $wildcard = [];
        $mains = [];
        foreach ($bindings as $binding) {
            if ($binding->environment !== $environment) {
                continue;
            }
            $entry = $binding->toArray();
            if ($binding->isWildcard()) {
                $wildcard[substr($binding->host, 2)][] = $entry;
            } else {
                $exact[$binding->host][] = $entry;
            }
            if ($binding->role === SiteBindingRole::Main) {
                $mains[$binding->site->toString()][$binding->locale ?? ''] = $entry;
            }
        }
        $longestFolderFirst = static fn (array $a, array $b): int => [strlen((string) $b['folder']), $a['position']] <=> [strlen((string) $a['folder']), $b['position']];
        foreach ($exact as $host => $entries) {
            usort($entries, $longestFolderFirst);
            $exact[$host] = $entries;
        }
        foreach ($wildcard as $suffix => $entries) {
            usort($entries, $longestFolderFirst);
            $wildcard[$suffix] = $entries;
        }

        return new self([
            'schema_version' => self::SCHEMA_VERSION,
            'environment' => $environment->value,
            'default_site' => $defaultSite?->toString(),
            'exact' => $exact,
            'wildcard' => $wildcard,
            'mains' => $mains,
        ]);
    }

    public static function empty(SiteEnvironment $environment): self
    {
        return self::compile($environment, [], null);
    }

    /** A cached or exported map, or null when it is not one this code wrote for this environment. */
    public static function fromArray(mixed $data, SiteEnvironment $environment): ?self
    {
        if (!is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($data['environment'] ?? null) !== $environment->value
            || !is_array($data['exact'] ?? null) || !is_array($data['wildcard'] ?? null) || !is_array($data['mains'] ?? null)
            || !(($data['default_site'] ?? null) === null || is_string($data['default_site']))) {
            return null;
        }

        /** @var Compiled $data */
        return new self($data);
    }

    /** @return Compiled */
    public function toArray(): array
    {
        return $this->data;
    }

    public function environment(): SiteEnvironment
    {
        return SiteEnvironment::from($this->data['environment']);
    }

    public function defaultSite(): ?ScopeRef
    {
        return $this->data['default_site'] === null ? null : ScopeRef::tryParse($this->data['default_site']);
    }

    public function isEmpty(): bool
    {
        return $this->data['exact'] === [] && $this->data['wildcard'] === [];
    }

    /**
     * The domain a request lands on: its exact host with the longest folder that matches, else the closest `*.`
     * host with the longest folder, else none (the default site).
     *
     * @return array{0:SiteBinding,1:SiteMatch}|null
     */
    public function match(string $host, string $path): ?array
    {
        $host = rtrim(strtolower($host), '.');
        $entry = self::firstFolder($this->data['exact'][$host] ?? [], $path);
        if ($entry !== null) {
            return [SiteBinding::fromArray($entry), SiteMatch::Exact];
        }
        // `*.example.ru` takes a.example.ru and a.b.example.ru, never example.ru: the suffix starts after a label.
        $suffix = $host;
        while (($dot = strpos($suffix, '.')) !== false) {
            $suffix = substr($suffix, $dot + 1);
            $entry = self::firstFolder($this->data['wildcard'][$suffix] ?? [], $path);
            if ($entry !== null) {
                return [SiteBinding::fromArray($entry), SiteMatch::Wildcard];
            }
        }

        return null;
    }

    /** The main domain of a site for a language, falling back to its main for every language. */
    public function mainOf(ScopeRef $site, ?string $locale): ?SiteBinding
    {
        $mains = $this->data['mains'][$site->toString()] ?? [];
        $entry = ($locale !== null ? ($mains[$locale] ?? null) : null) ?? ($mains[''] ?? null);

        return $entry === null ? null : SiteBinding::fromArray($entry);
    }

    /** True when the path is the folder or lies under it, on a segment boundary (`/shop` takes `/shop/x`, not `/shopping`). */
    public static function inFolder(?string $folder, string $path): bool
    {
        if ($folder === null) {
            return true;
        }

        return $path === $folder || str_starts_with($path, $folder.'/');
    }

    /**
     * @param list<Entry> $entries
     * @return Entry|null
     */
    private static function firstFolder(array $entries, string $path): ?array
    {
        foreach ($entries as $entry) {
            if (self::inFolder($entry['folder'], $path)) {
                return $entry;
            }
        }

        return null;
    }
}
