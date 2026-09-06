<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\Resellerinterface;

/**
 * Maps prices/domains into WHMCS TLD import items.
 */
class TldPricing
{
    /**
     * @param array $apiResponse
     * @return array<int, array<string, mixed>>
     */
    public static function map(array $apiResponse): array
    {
        $items = [];

        foreach ((array) ($apiResponse['tld'] ?? $apiResponse['list'] ?? []) as $row) {
            if (!is_array($row) || empty($row['tld'])) {
                continue;
            }

            $products = (array) ($row['products'] ?? []);
            $register = self::yearPrices($products['create'] ?? []);
            $renew = self::yearPrices($products['renew'] ?? $products['renewal'] ?? []);
            $transfer = self::yearPrices($products['transfer'] ?? []);
            $restore = self::yearPrices($products['restore'] ?? []);

            if ($register === [] && $renew === [] && $transfer === []) {
                continue;
            }

            $years = array_values(array_unique(array_merge(
                array_keys($register),
                array_keys($renew),
                array_keys($transfer)
            )));
            sort($years);

            $items[] = [
                'tld' => ltrim((string) $row['tld'], '.'),
                'register' => $register,
                'renew' => $renew,
                'transfer' => $transfer,
                'restore' => $restore[1] ?? ($restore[reset($years) ?: 1] ?? null),
                'minYears' => $years !== [] ? (int) min($years) : 1,
                'maxYears' => $years !== [] ? (int) max($years) : 1,
                'currency' => self::currency($products),
            ];
        }

        return $items;
    }

    /**
     * @param array $product
     * @return array<int, float>
     */
    private static function yearPrices(array $product): array
    {
        $years = [];

        foreach ((array) ($product['prices'] ?? []) as $runtimeKey => $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $months = ($runtimeKey === 'any') ? 12 : (int) $runtimeKey;
            if ($months < 12) {
                continue;
            }

            $year = (int) floor($months / 12);
            $amount = $tier['runtime']['net'] ?? $tier['total']['net'] ?? $tier['onetime']['net'] ?? null;
            if ($amount === null) {
                continue;
            }

            $years[$year] = (float) $amount;
        }

        return $years;
    }

    /**
     * @param array $products
     * @return string
     */
    private static function currency(array $products): string
    {
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            foreach ((array) ($product['prices'] ?? []) as $tier) {
                if (is_array($tier) && !empty($tier['currency'])) {
                    return strtoupper((string) $tier['currency']);
                }
            }
        }

        return 'EUR';
    }
}
