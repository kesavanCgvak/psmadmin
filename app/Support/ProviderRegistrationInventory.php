<?php

namespace App\Support;

/**
 * Default inventory_master products seeded for new provider companies at registration.
 * Resolved by psm_code so renames of the product model do not break seeding.
 */
final class ProviderRegistrationInventory
{
    public const TEST_PRODUCT_1_PSM_CODE = 'PSM19627';

    public const TEST_PRODUCT_2_PSM_CODE = 'PSM19626';

    /** @return list<string> */
    public static function defaultProductPsmCodes(): array
    {
        return [
            self::TEST_PRODUCT_1_PSM_CODE,
            self::TEST_PRODUCT_2_PSM_CODE,
        ];
    }
}
