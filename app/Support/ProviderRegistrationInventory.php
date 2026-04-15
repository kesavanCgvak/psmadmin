<?php

namespace App\Support;

/**
 * Default inventory_master.model values seeded for new provider companies at registration.
 */
final class ProviderRegistrationInventory
{
    public const TEST_PRODUCT_1_NAME = 'TEST PRODUCT 1';

    public const TEST_PRODUCT_2_NAME = 'TEST PRODUCT 2';

    /** @return list<string> */
    public static function defaultProductModelNames(): array
    {
        return [
            self::TEST_PRODUCT_1_NAME,
            self::TEST_PRODUCT_2_NAME,
        ];
    }
}
