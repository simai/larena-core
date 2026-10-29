<?php

declare(strict_types=1);

namespace Larena\Core\FirstRun;

final readonly class FirstRunPayload
{
    public function __construct(
        public string $displayName,
        public string $email,
        #[\SensitiveParameter]
        public string $plainPassword,
        public string $siteName,
        public string $locale,
        public string $timezone,
        /** @var array<string, bool> The options the person turned on (FirstRunOptions), by key. */
        public array $options = [],
    ) {
    }

    /** Whether the person turned the first-run option on; an option not offered is off. */
    public function option(string $key): bool
    {
        return ($this->options[$key] ?? false) === true;
    }
}
