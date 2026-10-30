<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Closure;

/**
 * The language of the current request (sites plan, step 4.4): its code, the address inside the site with the site's and
 * the language's folders taken off, and the language's folder. A request in no folder and on no domain of a language is
 * in the site's main language, read when somebody asks: most requests never do.
 */
final class CurrentLanguage
{
    /** The request attribute that carries it, beside the container binding. */
    public const ATTRIBUTE = 'larena.language';

    /** @param Closure(): string $main the site's main language */
    public function __construct(
        private ?string $locale,
        private readonly string $address,
        private readonly string $folder,
        private readonly Closure $main,
    ) {
    }

    public function locale(): string
    {
        return $this->locale ??= ($this->main)();
    }

    /** The address inside the site: `/shop/en/about` on a `/shop` domain is `/about`. */
    public function address(): string
    {
        return $this->address;
    }

    /** `/en` when the request came through a language's folder, otherwise nothing. */
    public function folder(): string
    {
        return $this->folder;
    }
}
