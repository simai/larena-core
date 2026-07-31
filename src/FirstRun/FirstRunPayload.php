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
    ) {
    }
}
