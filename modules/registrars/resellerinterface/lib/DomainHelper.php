<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\Resellerinterface;

use Exception;

/**
 * Domain-related helper methods.
 */
class DomainHelper
{
    /**
     * @param array $params
     * @return string
     */
    public static function getDomainName(array $params): string
    {
        $sld = $params['sld'] ?? '';
        $tld = ltrim($params['tld'] ?? '', '.');

        return strtolower($sld . '.' . $tld);
    }

    /**
     * @param array $params
     * @return array
     */
    public static function buildNameserverPayload(array $params): array
    {
        $nameservers = [];

        for ($i = 1; $i <= 5; $i++) {
            $ns = trim($params['ns' . $i] ?? '');
            if ($ns === '') {
                continue;
            }

            $entry = ['nameserver' => $ns];

            $ipv4 = trim($params['ns' . $i . 'ip'] ?? '');
            if ($ipv4 !== '') {
                $entry['glueRecordIpv4'] = $ipv4;
            }

            $ipv6 = trim($params['ns' . $i . 'ip6'] ?? '');
            if ($ipv6 !== '') {
                $entry['glueRecordIpv6'] = $ipv6;
            }

            $nameservers[] = $entry;
        }

        return $nameservers;
    }

    /**
     * @param array $domainDetails
     * @return array<int, string>
     */
    public static function extractNameservers(array $domainDetails): array
    {
        $result = [];
        $nameserverData = $domainDetails['nameserver'] ?? [];

        if (!is_array($nameserverData)) {
            return $result;
        }

        foreach (['LIVE', 'PENDING', 'OPEN'] as $state) {
            if (empty($nameserverData[$state]) || !is_array($nameserverData[$state])) {
                continue;
            }

            foreach ($nameserverData[$state] as $entry) {
                if (!empty($entry['nameserver'])) {
                    $result[] = $entry['nameserver'];
                }
            }

            if ($result !== []) {
                break;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array $handles
     * @param string $role owner|admin|tech|zone
     * @return string|null
     */
    public static function extractHandleAlias(array $handles, string $role): ?string
    {
        if (empty($handles[$role])) {
            return null;
        }

        $roleData = $handles[$role];
        if (is_string($roleData)) {
            return $roleData;
        }

        if (!is_array($roleData)) {
            return null;
        }

        foreach (['LIVE', 'PENDING', 'OPEN'] as $state) {
            if (!empty($roleData[$state])) {
                if (is_string($roleData[$state])) {
                    return $roleData[$state];
                }
                if (is_array($roleData[$state]) && !empty($roleData[$state]['alias'])) {
                    return $roleData[$state]['alias'];
                }
            }
        }

        if (!empty($roleData['alias']) && is_string($roleData['alias'])) {
            return $roleData['alias'];
        }

        return null;
    }

    /**
     * @param array $domainDetails
     * @return string
     */
    public static function mapRegistrationStatus(array $domainDetails): string
    {
        $state = strtoupper((string) ($domainDetails['state'] ?? ''));

        switch ($state) {
            case 'ACTIVE':
                return 'Active';
            case 'PENDING':
            case 'PREORDER':
            case 'OPEN':
                return 'Pending';
            case 'FAILED':
            case 'INACTIVE':
                return 'Cancelled';
            default:
                return 'Pending';
        }
    }

    /**
     * CoreAPI hat in domain/details kein expireDate.
     * Mögliche Quellen: nextBillingDate (List/Sort), expireDate (billing/listForRenewal),
     * cancellationDate (nur bei Kündigung).
     *
     * @param array $source
     * @return string|null Y-m-d
     */
    public static function extractExpiryDate(array $source): ?string
    {
        foreach (['expireDate', 'nextBillingDate', 'nextPaymentDate'] as $field) {
            $parsed = self::normalizeDate($source[$field] ?? null);
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return string|null Y-m-d
     */
    public static function normalizeDate($value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value) && (int) $value > 1000000000) {
            return gmdate('Y-m-d', (int) $value);
        }

        $raw = trim((string) $value);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return substr($raw, 0, 10);
        }

        return null;
    }

    /**
     * @param array $domainDetails
     * @return bool|null
     */
    public static function extractTransferLock(array $domainDetails): ?bool
    {
        foreach (['transferLock', 'updateLock'] as $field) {
            if (array_key_exists($field, $domainDetails)) {
                return self::toBool($domainDetails[$field]);
            }
        }

        if (!empty($domainDetails['status']) && is_array($domainDetails['status'])) {
            if (array_key_exists('transferLock', $domainDetails['status'])) {
                return self::toBool($domainDetails['status']['transferLock']);
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'locked'], true);
    }

    /**
     * @param array $hostObjectsResponse
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeHostObjects(array $hostObjectsResponse): array
    {
        $objects = [];

        if (!empty($hostObjectsResponse['hostObjects']) && is_array($hostObjectsResponse['hostObjects'])) {
            $source = $hostObjectsResponse['hostObjects'];
        } else {
            $source = $hostObjectsResponse;
        }

        foreach (['ACTIVE', 'PENDING', 'LIVE'] as $state) {
            if (empty($source[$state]) || !is_array($source[$state])) {
                continue;
            }

            foreach ($source[$state] as $entry) {
                if (empty($entry['hostname'])) {
                    continue;
                }

                $objects[] = [
                    'hostname' => $entry['hostname'],
                    'ipV4Addresses' => array_values(array_filter((array) ($entry['ipV4Addresses'] ?? []))),
                    'ipV6Addresses' => array_values(array_filter((array) ($entry['ipV6Addresses'] ?? []))),
                ];
            }

            if ($objects !== []) {
                break;
            }
        }

        return $objects;
    }

    /**
     * @param array $recordsResponse
     * @return array<int, array<string, mixed>>
     */
    public static function mapDnsRecordsForWhmcs(array $recordsResponse): array
    {
        $records = [];
        $rawRecords = $recordsResponse['records'] ?? [];

        if (!is_array($rawRecords)) {
            return $records;
        }

        foreach ($rawRecords as $record) {
            if (!is_array($record)) {
                continue;
            }

            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === '' || in_array($type, ['NS', 'SOA'], true)) {
                continue;
            }

            $host = (string) ($record['name'] ?? '@');
            if ($host === '' || $host === ($recordsResponse['domain'] ?? '')) {
                $host = '@';
            }

            $records[] = [
                'host' => $host,
                'type' => $type,
                'address' => (string) ($record['content'] ?? ''),
                'priority' => (int) ($record['priority'] ?? 0),
                'ttl' => (int) ($record['ttl'] ?? 3600),
            ];
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $whmcsRecords
     * @return array<int, array<string, mixed>>
     */
    public static function mapDnsRecordsForApi(array $whmcsRecords): array
    {
        $records = [];

        foreach ($whmcsRecords as $record) {
            $host = trim((string) ($record['host'] ?? '@'));
            $name = $host === '@' ? '[DOMAIN]' : $host;

            $entry = [
                'name' => $name,
                'type' => strtoupper((string) ($record['type'] ?? 'A')),
                'content' => (string) ($record['address'] ?? $record['value'] ?? ''),
            ];

            if (!empty($record['ttl'])) {
                $entry['ttl'] = (int) $record['ttl'];
            }

            if (!empty($record['priority'])) {
                $entry['priority'] = (int) $record['priority'];
            }

            $records[] = $entry;
        }

        return $records;
    }
}
