<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/**
 * The four things two solutions can collide on.
 *
 * The set is closed. A collision nobody named is a collision discovered halfway
 * through an install, which is the failure mode the planner exists to prevent.
 */
enum SolutionConflictClass: string
{
    case Scope = 'scope';
    case Plane = 'plane';
    case StructureRole = 'structure_role';
    case Package = 'package';

    public function reasonCode(): string
    {
        return $this->value . '_already_owned';
    }
}
