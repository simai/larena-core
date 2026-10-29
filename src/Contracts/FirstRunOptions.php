<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * A first-run step that offers the person choices beside the form's fields. Each
 * option is off unless the person turns it on; the step reads the choice from
 * FirstRunPayload::option() when it applies.
 */
interface FirstRunOptions
{
    /** @return array<string, string> Option key ([a-z][a-z0-9_]*) => its label in the current locale. */
    public function options(): array;
}
