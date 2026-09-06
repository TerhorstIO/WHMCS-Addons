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
     * Fallback der CoreAPI, wenn kein eigener virtueller Nameserver (vNS) gesetzt ist.
     * Nur mit diesen Hosts funktioniert die DNS-Verwaltung über dns/listRecords.
     */
    public const INTERNAL_NAMESERVERS = [
        'ns1.mainns.de',
        'ns2.mainns.eu',
        'ns3.mainns.net',
    ];

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
        $settings = is_array($domainDetails['settings'] ?? null) ? $domainDetails['settings'] : [];
        $candidates = [
            $domainDetails['nameserver'] ?? null,
            $domainDetails['nameservers'] ?? null,
            $settings['nameserver'] ?? null,
            $settings['nameservers'] ?? null,
        ];

        foreach ($candidates as $nameserverData) {
            $result = self::collectNsHostnames($nameserverData);
            if ($result !== []) {
                return $result;
            }
        }

        return [];
    }

    /**
     * @param array $payload nameserver[{nameserver: host}] oder Hostnamen
     * @return array<int, string>
     */
    public static function hostnamesFromNsPayload(array $payload): array
    {
        return self::collectNsHostnames($payload);
    }

    /**
     * Nur belegte Nameserver. Leere ns4/ns5 löst in WHMCS
     * "An unknown error occurred." aus (IDNA-Validierung).
     *
     * @param array<int|string, string> $nameservers
     * @return array<string, string>
     */
    public static function toWhmcsNsFields(array $nameservers): array
    {
        $hosts = [];
        foreach ($nameservers as $value) {
            if (!is_string($value)) {
                continue;
            }
            $normalized = self::normalizeNsHostname($value);
            if ($normalized === '') {
                continue;
            }
            $hosts[] = $normalized;
        }

        $result = [];
        foreach ($hosts as $i => $hostname) {
            if ($i >= 5) {
                break;
            }
            $result['ns' . ($i + 1)] = $hostname;
        }

        return $result;
    }

    /**
     * INTERNAL | EXTERNAL | leer
     *
     * @param array $domainDetails
     * @return string
     */
    public static function nameserverMode(array $domainDetails): string
    {
        $mode = strtoupper(trim((string) ($domainDetails['nameserverMode'] ?? '')));
        if (in_array($mode, ['INTERNAL', 'EXTERNAL'], true)) {
            return $mode;
        }

        $redirect = strtolower(trim((string) ($domainDetails['redirectMode'] ?? '')));
        if (in_array($redirect, ['external', 'nameserver'], true)) {
            return 'EXTERNAL';
        }
        if ($redirect !== '') {
            return 'INTERNAL';
        }

        return '';
    }

    /**
     * @param array $zone dns/getZoneDetails
     * @return array<int, string>
     */
    public static function extractNameserversFromZone(array $zone): array
    {
        $vns = $zone['vns'] ?? [];
        if (is_array($vns)) {
            $hosts = self::normalizeNsSet((array) ($vns['hostname'] ?? []));
            if ($hosts !== []) {
                return $hosts;
            }
        }

        $fromRecords = [];
        foreach ((array) ($zone['records'] ?? []) as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (strtoupper((string) ($record['type'] ?? '')) !== 'NS') {
                continue;
            }
            $content = trim((string) ($record['content'] ?? ''));
            if ($content !== '') {
                $fromRecords[] = $content;
            }
        }

        return self::normalizeNsSet($fromRecords);
    }

    /**
     * @param array<int, string> $hostnames
     * @param array<int, array<int, string>> $knownSets zusätzliche vNS-Sets
     * @return bool
     */
    public static function isInternalNameserverSet(array $hostnames, array $knownSets = []): bool
    {
        $hostnames = self::normalizeNsSet($hostnames);
        if ($hostnames === []) {
            return false;
        }

        $sets = $knownSets;
        $sets[] = self::INTERNAL_NAMESERVERS;

        foreach ($sets as $set) {
            $set = self::normalizeNsSet((array) $set);
            if ($set === []) {
                continue;
            }
            if (array_diff($hostnames, $set) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string|array> $hostnames
     * @return array<int, string>
     */
    public static function normalizeNsSet(array $hostnames): array
    {
        return self::collectNsHostnames($hostnames);
    }

    /**
     * @param mixed $nameserverData
     * @return array<int, string>
     */
    private static function collectNsHostnames($nameserverData): array
    {
        if (is_string($nameserverData)) {
            $normalized = self::normalizeNsHostname($nameserverData);
            return $normalized === '' ? [] : [$normalized];
        }

        if (!is_array($nameserverData) || $nameserverData === []) {
            return [];
        }

        if (self::isList($nameserverData)) {
            return self::hostnamesFromEntries($nameserverData);
        }

        foreach (['LIVE', 'PENDING', 'OPEN'] as $state) {
            $bucket = $nameserverData[$state] ?? $nameserverData[strtolower($state)] ?? null;
            if (!is_array($bucket)) {
                continue;
            }
            $result = self::hostnamesFromEntries($bucket);
            if ($result !== []) {
                return $result;
            }
        }

        $result = [];
        foreach ($nameserverData as $value) {
            $result = array_merge($result, self::collectNsHostnames($value));
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array $entries
     * @return array<int, string>
     */
    private static function hostnamesFromEntries(array $entries): array
    {
        $result = [];

        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $normalized = self::normalizeNsHostname($entry);
                if ($normalized !== '') {
                    $result[] = $normalized;
                }
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            foreach (['nameserver', 'hostname', 'name', 'host', 'ns'] as $key) {
                if (empty($entry[$key]) || !is_string($entry[$key])) {
                    continue;
                }
                $normalized = self::normalizeNsHostname($entry[$key]);
                if ($normalized !== '') {
                    $result[] = $normalized;
                }
                break;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param string $hostname
     * @return string
     */
    public static function normalizeNsHostname(string $hostname): string
    {
        return strtolower(rtrim(trim($hostname), '.'));
    }

    /**
     * @param array $array
     * @return bool
     */
    private static function isList(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
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
        foreach (['expireDate', 'dateExpire', 'expiryDate', 'expires', 'nextBillingDate', 'nextPaymentDate'] as $field) {
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
        $found = self::findTransferLockValue($domainDetails, 0);
        if ($found !== null) {
            return $found;
        }

        foreach (['hasDomainSafe', 'hasRegistryLock', 'domainSafe'] as $field) {
            if (array_key_exists($field, $domainDetails)) {
                return self::toBool($domainDetails[$field]);
            }
        }

        return null;
    }

    /**
     * Key paths that look like lock flags, for the module log.
     *
     * @param array $data
     * @return array<string, mixed>
     */
    public static function collectLockHints(array $data, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        $hints = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $name = strtolower((string) $key);
            if (str_contains($name, 'lock') || str_contains($name, 'hold') || str_contains($name, 'prohibited') || $name === 'status') {
                $hints[$path] = is_array($value) ? array_keys($value) : $value;
            }
            if (is_array($value) && !in_array((string) $key, ['tldInfo', 'tldExotic', 'nameserver', 'handles', 'dnssec', 'hostObjects', 'price'], true)) {
                $hints += self::collectLockHints($value, $path, $depth + 1);
            }
        }

        return $hints;
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

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), [
            '1',
            'true',
            'yes',
            'on',
            'locked',
            'enabled',
            'active',
        ], true);
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
     * @param array $data
     * @param int $depth
     * @return bool|null
     */
    private static function findTransferLockValue(array $data, int $depth): ?bool
    {
        if ($depth > 4) {
            return null;
        }

        foreach (['transferLock', 'TransferLock', 'transfer_lock', 'clientTransferProhibited'] as $field) {
            if (array_key_exists($field, $data)) {
                return self::toBool($data[$field]);
            }
        }

        if ($depth === 0 && self::hasEppTransferLock($data)) {
            return true;
        }

        foreach ($data as $key => $value) {
            if (!is_array($value) || in_array((string) $key, ['tldInfo', 'tldExotic', 'nameserver', 'handles', 'dnssec', 'hostObjects', 'restorable', 'price'], true)) {
                continue;
            }

            $found = self::findTransferLockValue($value, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array $data
     * @return bool
     */
    private static function hasEppTransferLock(array $data): bool
    {
        $statuses = $data['eppStatus'] ?? $data['statuses'] ?? $data['status'] ?? null;
        if (!is_array($statuses)) {
            return false;
        }

        $flat = [];
        array_walk_recursive($statuses, static function ($value) use (&$flat): void {
            if (is_string($value) || is_int($value)) {
                $flat[] = strtolower((string) $value);
            }
        });

        return in_array('clienttransferprohibited', $flat, true)
            || in_array('servertransferprohibited', $flat, true);
    }

    /**
     * @param array $recordsResponse
     * @return array<int, array<string, mixed>>
     */
    public static function mapDnsRecordsForWhmcs(array $recordsResponse): array
    {
        $records = [];
        $rawRecords = $recordsResponse['records'] ?? [];
        $domain = strtolower(trim((string) ($recordsResponse['domain'] ?? '')));

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

            $content = (string) ($record['content'] ?? '');
            if ($content === '' && isset($record['data']['uri'])) {
                $content = (string) $record['data']['uri'];
            }

            $hostname = self::dnsNameToWhmcsHostname(
                (string) ($record['name'] ?? $record['hostname'] ?? $record['host'] ?? ''),
                $domain
            );

            $records[] = [
                'hostname' => $hostname,
                'host' => $hostname,
                'type' => $type,
                'address' => $content,
                'priority' => (int) ($record['priority'] ?? 0),
                'ttl' => (int) ($record['ttl'] ?? 3600),
            ];
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $whmcsRecords
     * @param string $domain
     * @return array<int, array<string, mixed>>
     */
    public static function mapDnsRecordsForApi(array $whmcsRecords, string $domain = ''): array
    {
        $records = [];

        foreach ($whmcsRecords as $record) {
            if (!is_array($record)) {
                continue;
            }

            $type = strtoupper(trim((string) ($record['type'] ?? '')));
            $content = trim((string) ($record['address'] ?? $record['value'] ?? $record['content'] ?? ''));
            if ($type === '' || $content === '') {
                continue;
            }

            $host = trim((string) ($record['hostname'] ?? $record['host'] ?? ''));
            $entry = [
                'name' => self::whmcsHostnameToDnsName($host, $domain),
                'type' => $type,
                'content' => $content,
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

    /**
     * Apex als leer (WHMCS-Konvention), Subdomains als Relativname (www).
     *
     * @param string $name
     * @param string $domain
     * @return string
     */
    public static function dnsNameToWhmcsHostname(string $name, string $domain = ''): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($name === '' || $name === '@' || $name === '[domain]' || ($domain !== '' && $name === $domain)) {
            return '';
        }

        if ($domain !== '' && str_ends_with($name, '.' . $domain)) {
            return substr($name, 0, -strlen('.' . $domain));
        }

        return $name;
    }

    /**
     * @param string $hostname
     * @param string $domain
     * @return string
     */
    public static function whmcsHostnameToDnsName(string $hostname, string $domain = ''): string
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($hostname === '' || $hostname === '@' || $hostname === '[domain]' || ($domain !== '' && $hostname === $domain)) {
            return $domain !== '' ? $domain : '[DOMAIN]';
        }

        return $hostname;
    }
}
