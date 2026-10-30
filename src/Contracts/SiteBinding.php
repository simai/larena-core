<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\SiteBindingRole;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Scope\ScopeRef;

/**
 * One domain of a site: a host (`example.ru` or `*.example.ru`), an optional folder (`/shop`), a language (null is
 * every language), a role and an environment, at its position in the site's list.
 */
final readonly class SiteBinding
{
    public function __construct(
        public ScopeRef $site,
        public int $position,
        public string $host,
        public ?string $folder,
        public ?string $locale,
        public SiteBindingRole $role,
        public SiteEnvironment $environment,
    ) {
    }

    public function isWildcard(): bool
    {
        return str_starts_with($this->host, '*.');
    }

    /** @return array{site_ref:string,position:int,host:string,folder:?string,locale:?string,role:string,environment:string} */
    public function toArray(): array
    {
        return [
            'site_ref' => $this->site->toString(),
            'position' => $this->position,
            'host' => $this->host,
            'folder' => $this->folder,
            'locale' => $this->locale,
            'role' => $this->role->value,
            'environment' => $this->environment->value,
        ];
    }

    /** @param array{site_ref:string,position:int,host:string,folder:?string,locale:?string,role:string,environment:string} $row */
    public static function fromArray(array $row): self
    {
        return new self(
            ScopeRef::parse($row['site_ref']),
            $row['position'],
            $row['host'],
            $row['folder'],
            $row['locale'],
            SiteBindingRole::from($row['role']),
            SiteEnvironment::from($row['environment']),
        );
    }
}
