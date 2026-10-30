<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Larena\Core\Contracts\SiteBinding;
use Larena\Core\SitePack\SitePackRejected;

/**
 * A site's domains in a SitePack (sites plan, step 2.8): the artifact `artifacts/site/bindings.jsonl`, one line per
 * domain in the site's order: `{host, folder, locale, role, environment}`. Any pack that moves a site may carry it; an
 * import adds them only when the person asks (SiteBindings::planAdd(), add()), because domains usually differ.
 */
final class SiteBindingsArtifact
{
    public const string ID = 'site.bindings';
    public const string PATH = 'artifacts/site/bindings.jsonl';
    public const int MAX_LINES = SiteBindingRules::MAX_BINDINGS;

    private const array FIELDS = ['host', 'folder', 'locale', 'role', 'environment'];

    /** @return array{host: string, folder: ?string, locale: ?string, role: string, environment: string} */
    public static function line(SiteBinding $binding): array
    {
        return ['host' => $binding->host, 'folder' => $binding->folder, 'locale' => $binding->locale,
            'role' => $binding->role->value, 'environment' => $binding->environment->value];
    }

    /**
     * A line read from a pack, as an entry SiteBindings takes; what it holds is checked there.
     *
     * @param array<mixed> $line
     * @return array<string, mixed>
     *
     * @throws SitePackRejected
     */
    public static function entry(array $line): array
    {
        $entry = [];
        foreach (self::FIELDS as $field) {
            $value = $line[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new SitePackRejected('sitepack_site_bindings_invalid');
            }
            $entry[$field] = $value;
        }
        if (!is_string($entry['host'])) {
            throw new SitePackRejected('sitepack_site_bindings_invalid');
        }

        return $entry;
    }
}
