<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/**
 * Who is shipping a solution.
 *
 * One schema serves all three. A SIMAI product, a partner's marketplace solution
 * and a customer's local customization differ in author and distribution and in
 * nothing else — because the moment a first-party solution gets a private
 * extension to the schema, no partner can build what SIMAI builds.
 */
enum SolutionDistribution: string
{
    case SimaiProduct = 'simai_product';
    case PartnerMarketplace = 'partner_marketplace';
    case LocalCustomization = 'local_customization';

    /**
     * A local customization never leaves the installation that wrote it, so it is
     * the one distribution that carries no marketplace identity.
     */
    public function isDistributed(): bool
    {
        return $this !== self::LocalCustomization;
    }
}
