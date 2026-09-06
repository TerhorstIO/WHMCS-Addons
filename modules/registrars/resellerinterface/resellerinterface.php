<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Carbon;
use WHMCS\Domain\Registrar\Domain;
use WHMCS\Module\Registrar\Resellerinterface\CoreApiClient;
use WHMCS\Module\Registrar\Resellerinterface\DomainHelper;
use WHMCS\Module\Registrar\Resellerinterface\HandleManager;

require_once __DIR__ . '/lib/CoreApiClient.php';
require_once __DIR__ . '/lib/DomainHelper.php';
require_once __DIR__ . '/lib/HandleManager.php';
require_once __DIR__ . '/lib/ModuleConfig.php';
require_once __DIR__ . '/lib/TldPricing.php';

/**
 * @param array $params
 * @return CoreApiClient
 */
function resellerinterface_client(array $params): CoreApiClient
{
    return new CoreApiClient($params);
}

/**
 * @param array $params
 * @return HandleManager
 */
function resellerinterface_handles(array $params): HandleManager
{
    return new HandleManager(resellerinterface_client($params), $params);
}

/**
 * Ablaufdatum aus Domain-Objekt oder billing/listForRenewal.
 *
 * @param \WHMCS\Module\Registrar\Resellerinterface\CoreApiClient $client
 * @param string $domain
 * @param array $details
 * @return string|null Y-m-d
 */
function resellerinterface_resolveExpiry($client, string $domain, array $details): ?string
{
    $direct = DomainHelper::extractExpiryDate($details);
    if ($direct) {
        return $direct;
    }

    try {
        $parts = explode('.', $domain);
        $tld = (string) array_pop($parts);
        $sld = implode('.', $parts);
        $listed = $client->request('domain/list', [
            'filter' => [
                'sld' => $sld,
                'tld' => $tld,
            ],
            'limit' => 5,
        ]);
        foreach ((array) ($listed['list'] ?? []) as $item) {
            if (strcasecmp((string) ($item['domain'] ?? ''), $domain) !== 0) {
                continue;
            }
            $fromList = DomainHelper::extractExpiryDate($item);
            if ($fromList) {
                return $fromList;
            }
        }
    } catch (Exception $e) {
        // Continue with billing lookup.
    }

    try {
        $renewals = $client->request('billing/listForRenewal', [
            'days' => 400,
            'search' => $domain,
            'limit' => 20,
        ]);
        foreach ((array) ($renewals['list'] ?? []) as $item) {
            if (strcasecmp((string) ($item['name'] ?? ''), $domain) !== 0) {
                continue;
            }
            $fromBilling = DomainHelper::extractExpiryDate($item);
            if ($fromBilling) {
                return $fromBilling;
            }
        }
    } catch (Exception $e) {
        // No expiry available.
    }

    return null;
}

/**
 * domain/details enthält die Transfersperre oft nicht.
 * Fallback: domain/list inkl. settings.
 *
 * @param \WHMCS\Module\Registrar\Resellerinterface\CoreApiClient $client
 * @param string $domain
 * @return array<string, mixed>
 */
function resellerinterface_domainDetails($client, string $domain): array
{
    try {
        $response = $client->request('domain/details', [
            'domain' => $domain,
            'include' => ['settings'],
        ]);
    } catch (Exception $e) {
        $response = $client->request('domain/details', ['domain' => $domain]);
    }
    $details = (array) ($response['domain'] ?? []);
    foreach (['status', 'settings', 'locks', 'flags'] as $extraKey) {
        if (isset($response[$extraKey]) && !isset($details[$extraKey])) {
            $details[$extraKey] = $response[$extraKey];
        }
    }

    if (DomainHelper::extractTransferLock($details) !== null) {
        return $details;
    }

    $parts = explode('.', $domain);
    $tld = (string) array_pop($parts);
    $sld = implode('.', $parts);

    try {
        $listed = $client->request('domain/list', [
            'filter' => [
                'sld' => $sld,
                'tld' => $tld,
            ],
            'include' => ['settings'],
            'limit' => 5,
        ]);
        foreach ((array) ($listed['list'] ?? []) as $item) {
            if (!is_array($item) || strcasecmp((string) ($item['domain'] ?? ''), $domain) !== 0) {
                continue;
            }

            return $item + $details;
        }
    } catch (Exception $e) {
        // details ohne Lock-Feld weiterverwenden
    }

    return $details;
}

/**
 * @return array<string, mixed>
 */
function resellerinterface_MetaData(): array
{
    return [
        'DisplayName' => 'ResellerInterface (CoreAPI)',
        'APIVersion' => '1.1',
        'RequiresServer' => false,
        'DefaultNonTLDFields' => [],
        'DefaultTLDFields' => [],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function resellerinterface_getConfigArray(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'ResellerInterface CoreAPI',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Domain-Registrar-Modul für die ResellerInterface CoreAPI.',
        ],
        'Username' => [
            'FriendlyName' => 'API-Benutzername',
            'Type' => 'text',
            'Size' => '40',
            'Description' => '<span id="ri-config-root"></span>Benutzername für /stable/reseller/login',
        ],
        'Password' => [
            'FriendlyName' => 'API-Passwort',
            'Type' => 'password',
            'Size' => '40',
        ],
        'TotpCode' => [
            'FriendlyName' => '2FA TOTP (optional)',
            'Type' => 'password',
            'Size' => '10',
            'Description' => 'Nur ausfüllen, wenn 2FA per TOTP aktiviert ist. Alternativ temporär für Login.',
        ],
        'ResellerId' => [
            'FriendlyName' => 'Reseller-ID',
            'Type' => 'text',
            'Size' => '10',
            'Description' => 'Pflicht für den Login: $client->login(username, password, resellerId)',
        ],
        'DefaultHandleTag' => [
            'FriendlyName' => 'Handle-Tag',
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'whmcs',
            'Description' => 'Tag für neu erstellte Handles',
        ],
        'DefaultRedirectMode' => [
            'FriendlyName' => 'Standard Redirect-Modus',
            'Type' => 'dropdown',
            'Options' => [
                'external' => 'Externe Nameserver',
                'unconfigured' => 'Unkonfiguriert (interne DNS)',
            ],
            'Default' => 'external',
            'Description' => 'Verwendet bei Registrierung/Transfer, wenn keine Nameserver gesetzt sind',
        ],
        'AutoDnssec' => [
            'FriendlyName' => 'Auto-DNSSEC',
            'Type' => 'yesno',
            'Description' => 'Bei Registrierung/Transfer autoDnssec setzen (interne Nameserver)',
        ],
        'UseTrustee' => [
            'FriendlyName' => 'Trustee standardmäßig',
            'Type' => 'yesno',
            'Description' => 'Bei Registrierung/Transfer einen Trustee mitbestellen',
        ],
        'DebugMode' => [
            'FriendlyName' => 'Debug-Modus',
            'Type' => 'yesno',
            'Description' => 'API-Aufrufe im WHMCS Module Log speichern',
        ],
        'ConnectionTest' => [
            'FriendlyName' => 'Verbindungstest',
            'Type' => 'text',
            'Size' => '1',
            'Default' => '',
            'Description' => '<div id="ri-test-wrap" style="display:inline-block;vertical-align:middle;margin-left:8px;">'
                . '<button type="button" class="btn btn-default" id="ri-test-connection">'
                . '<i class="fas fa-plug"></i> Verbindung testen</button>'
                . '<div id="ri-test-result" style="margin-top:10px;display:none;"></div>'
                . '</div>'
                . '<style>#ri-test-wrap + input, input[name="ConnectionTest"], '
                . 'input[name="fields[ConnectionTest]"], '
                . 'input[name="fields[resellerinterface][ConnectionTest]"] { display:none !important; }</style>',
        ],
    ];
}

/**
 * @param array $params
 * @return array<string, string>
 */
function resellerinterface_TestConnection(array $params): array
{
    $client = resellerinterface_client($params);

    try {
        $client->testConnection();

        return [
            'success' => 'Verbindung zur CoreAPI erfolgreich hergestellt.',
        ];
    } catch (Exception $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_RegisterDomain(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $handles = resellerinterface_handles($params);
        $domain = DomainHelper::getDomainName($params);
        $nameservers = DomainHelper::buildNameserverPayload($params);

        $payload = [
            'domain' => $domain,
            'handles' => $handles->buildHandlesForOrder($params),
            'fullyAsync' => false,
        ];

        if ($nameservers !== []) {
            $payload['redirectMode'] = 'external';
            $payload['nameserver'] = $nameservers;
        } else {
            $payload['redirectMode'] = $params['DefaultRedirectMode'] ?? 'external';
        }

        if (!empty($params['idprotection'])) {
            $payload['whoisPrivacy'] = true;
        }

        if (!empty($params['ResellerId'])) {
            $payload['resellerID'] = (int) $params['ResellerId'];
        }

        if (!empty($params['premiumEnabled']) && !empty($params['premiumCost'])) {
            $payload['premiumOK'] = true;
        }

        $payload = resellerinterface_applyOrderOptions($params, $payload);
        $client->request('domain/create', $payload);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_TransferDomain(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $handles = resellerinterface_handles($params);
        $domain = DomainHelper::getDomainName($params);
        $nameservers = DomainHelper::buildNameserverPayload($params);

        $payload = [
            'domain' => $domain,
            'handles' => $handles->buildHandlesForOrder($params),
            'authcode' => (string) ($params['eppcode'] ?? ''),
            'fullyAsync' => false,
        ];

        if ($nameservers !== []) {
            $payload['redirectMode'] = 'external';
            $payload['nameserver'] = $nameservers;
        } else {
            $payload['redirectMode'] = $params['DefaultRedirectMode'] ?? 'external';
        }

        if (!empty($params['idprotection'])) {
            $payload['whoisPrivacy'] = true;
        }

        if (!empty($params['ResellerId'])) {
            $payload['resellerID'] = (int) $params['ResellerId'];
        }

        $payload = resellerinterface_applyOrderOptions($params, $payload);
        $client->request('domain/transfer', $payload);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_RenewDomain(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $years = max(1, (int) ($params['regperiod'] ?? 1));

        $client->request('domain/renew', [
            'domain' => $domain,
            'runtime' => $years * 12,
            'fullyAsync' => false,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, string>
 */
function resellerinterface_GetNameservers(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $response = $client->request('domain/details', ['domain' => $domain]);
        $nameservers = DomainHelper::extractNameservers($response['domain'] ?? []);

        $result = [];
        for ($i = 0; $i < 5; $i++) {
            $result['ns' . ($i + 1)] = $nameservers[$i] ?? '';
        }

        return $result;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_SaveNameservers(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $nameservers = DomainHelper::buildNameserverPayload($params);

        if ($nameservers === []) {
            throw new Exception('Mindestens ein Nameserver muss angegeben werden.');
        }

        $client->request('domain/setNameserver', [
            'domain' => $domain,
            'nameserver' => $nameservers,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return Domain|array<string, string>
 */
function resellerinterface_GetDomainInformation(array $params)
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $details = resellerinterface_domainDetails($client, $domain);
        $nameservers = DomainHelper::extractNameservers($details);

        $status = Domain::STATUS_ACTIVE;
        $state = strtoupper((string) ($details['state'] ?? ''));

        if (in_array($state, ['PENDING', 'PREORDER', 'OPEN'], true)) {
            $status = Domain::STATUS_PENDING;
        } elseif (in_array($state, ['FAILED', 'INACTIVE'], true)) {
            $status = Domain::STATUS_CANCELLED;
        }

        $domainObj = (new Domain())
            ->setDomain($domain)
            ->setNameservers($nameservers)
            ->setRegistrationStatus($status);

        $lock = DomainHelper::extractTransferLock($details);
        $domainObj->setTransferLock($lock === true);

        $expiry = resellerinterface_resolveExpiry($client, $domain, $details);
        if ($expiry) {
            try {
                $domainObj->setExpiryDate(Carbon::createFromFormat('Y-m-d', $expiry));
            } catch (Exception $e) {
                // Ignore invalid date formats from API.
            }
        }

        return $domainObj;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, array<string, string>>|array<string, string>
 */
function resellerinterface_GetContactDetails(array $params)
{
    try {
        $handles = resellerinterface_handles($params);
        $domain = DomainHelper::getDomainName($params);

        return $handles->getContactDetails($domain);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_SaveContactDetails(array $params): array
{
    try {
        $handles = resellerinterface_handles($params);
        $domain = DomainHelper::getDomainName($params);
        $contactDetails = [];

        foreach (['Registrant', 'Admin', 'Technical', 'Billing'] as $role) {
            if (!empty($params['contactdetails'][$role])) {
                $contactDetails[$role] = $params['contactdetails'][$role];
            }
        }

        $handles->saveContactDetails($domain, $contactDetails);

        if (!empty($contactDetails['Registrant'])) {
            try {
                $client = resellerinterface_client($params);
                $client->request('domain/update', [
                    'domain' => $domain,
                    'tradeOK' => true,
                    'fullyAsync' => false,
                ]);
            } catch (Exception $e) {
                // setHandles kann ohne Trade ausreichen.
            }
        }

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_CheckAvailability(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $searchTerm = trim((string) ($params['searchTerm'] ?? ''));

        if ($searchTerm === '') {
            return [];
        }

        $tld = ltrim((string) ($params['tld'] ?? ''), '.');
        $domain = str_contains($searchTerm, '.') ? $searchTerm : $searchTerm . '.' . $tld;

        $response = $client->request('domain/check', [
            'domain' => [$domain],
        ]);

        $results = [];
        foreach ((array) ($response['results'] ?? []) as $item) {
            $checkedDomain = (string) ($item['domain'] ?? $domain);
            $result = strtolower((string) ($item['result'] ?? ''));

            $status = 'notregistered';
            if ($result === 'connect') {
                $status = 'registered';
            } elseif (in_array($result, ['invalid', 'syntax'], true)) {
                $status = 'invalid';
            } elseif (in_array($result, ['temporarily unavailable', 'maintenance'], true)) {
                $status = 'unavailable';
            } elseif ($result === 'unknown') {
                $status = 'unknown';
            }

            $results[] = [
                'domain' => $checkedDomain,
                'status' => $status,
            ];
        }

        return $results;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return string|array<string, string>
 */
function resellerinterface_GetRegistrarLock(array $params)
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $details = resellerinterface_domainDetails($client, $domain);
        $lock = DomainHelper::extractTransferLock($details);

        if ($lock === null) {
            try {
                $safe = $client->request('domain/getDomainSafeDetails', ['domain' => $domain]);
                if (DomainHelper::toBool($safe['active'] ?? false)) {
                    $lock = true;
                }
            } catch (Exception $e) {
                // Domain-Safe ist optional
            }
        }

        if (function_exists('logModuleCall')) {
            logModuleCall(
                'resellerinterface',
                'GetRegistrarLock',
                [
                    'domain' => $domain,
                    'keys' => array_keys($details),
                    'lockHints' => DomainHelper::collectLockHints($details),
                ],
                $lock === true ? 'locked' : 'unlocked'
            );
        }

        return $lock === true ? 'locked' : 'unlocked';
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_SaveRegistrarLock(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $requested = strtolower(trim((string) ($params['lockenabled'] ?? '')));
        $lockEnabled = in_array($requested, ['locked', 'on', '1', 'true', 'yes'], true);

        $details = [];
        try {
            $details = resellerinterface_domainDetails($client, $domain);
        } catch (Exception $e) {
            $details = [];
        }

        $apiDomain = $details['domainID'] ?? $domain;
        $client->request('domain/setStatus', [
            'domain' => $apiDomain,
            'transferLock' => $lockEnabled ? 1 : 0,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<int, array<string, mixed>>|array<string, string>
 */
function resellerinterface_GetDNS(array $params)
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $response = $client->request('dns/listRecords', ['domain' => $domain]);

        return DomainHelper::mapDnsRecordsForWhmcs($response);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_SaveDNS(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $records = DomainHelper::mapDnsRecordsForApi((array) ($params['dnsrecords'] ?? []));

        $client->request('dns/setRecords', [
            'domain' => $domain,
            'records' => $records,
            'backupZone' => true,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_IDProtectToggle(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);

        $client->request('domain/update', [
            'domain' => $domain,
            'whoisPrivacy' => !empty($params['protectenable']),
            'fullyAsync' => false,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_GetEPPCode(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);

        $response = $client->request('domain/showAuthcode', [
            'domain' => $domain,
        ]);

        if (empty($response['authcode'])) {
            $response = $client->request('domain/generateAuthcode', [
                'domain' => $domain,
                'waitForResponse' => true,
            ]);
        }

        if (empty($response['authcode'])) {
            throw new Exception('Authcode konnte nicht abgerufen werden.');
        }

        return [
            'eppcode' => (string) $response['authcode'],
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_RequestDelete(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);

        $client->request('domain/delete', [
            'domain' => $domain,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_RegisterNameserver(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $hostname = strtolower(trim((string) ($params['nameserver'] ?? '')));
        $ipAddress = trim((string) ($params['currentipaddress'] ?? ''));

        if ($hostname === '' || $ipAddress === '') {
            throw new Exception('Hostname und IP-Adresse sind erforderlich.');
        }

        $existing = $client->request('domain/listHostObjects', ['domain' => $domain]);
        $hostObjects = DomainHelper::normalizeHostObjects($existing);

        $entry = ['hostname' => $hostname, 'ipV4Addresses' => [], 'ipV6Addresses' => []];
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $entry['ipV6Addresses'][] = $ipAddress;
        } else {
            $entry['ipV4Addresses'][] = $ipAddress;
        }

        $hostObjects[] = $entry;

        $client->request('domain/setHostObjects', [
            'domain' => $domain,
            'hostObjects' => $hostObjects,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_ModifyNameserver(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $hostname = strtolower(trim((string) ($params['nameserver'] ?? '')));
        $newIp = trim((string) ($params['newipaddress'] ?? ''));

        $existing = $client->request('domain/listHostObjects', ['domain' => $domain]);
        $hostObjects = DomainHelper::normalizeHostObjects($existing);
        $updated = false;

        foreach ($hostObjects as &$entry) {
            if (strtolower((string) $entry['hostname']) !== $hostname) {
                continue;
            }

            $entry['ipV4Addresses'] = [];
            $entry['ipV6Addresses'] = [];

            if (filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $entry['ipV6Addresses'][] = $newIp;
            } else {
                $entry['ipV4Addresses'][] = $newIp;
            }

            $updated = true;
            break;
        }
        unset($entry);

        if (!$updated) {
            throw new Exception('Nameserver-Hostobjekt nicht gefunden: ' . $hostname);
        }

        $client->request('domain/setHostObjects', [
            'domain' => $domain,
            'hostObjects' => $hostObjects,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_DeleteNameserver(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $hostname = strtolower(trim((string) ($params['nameserver'] ?? '')));

        $existing = $client->request('domain/listHostObjects', ['domain' => $domain]);
        $hostObjects = DomainHelper::normalizeHostObjects($existing);
        $filtered = [];

        foreach ($hostObjects as $entry) {
            if (strtolower((string) $entry['hostname']) === $hostname) {
                continue;
            }
            $filtered[] = $entry;
        }

        $client->request('domain/setHostObjects', [
            'domain' => $domain,
            'hostObjects' => $filtered,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_Sync(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $response = $client->request('domain/details', ['domain' => $domain]);
        $details = $response['domain'] ?? [];

        $result = [
            'active' => strtoupper((string) ($details['state'] ?? '')) === 'ACTIVE',
        ];

        $expiry = resellerinterface_resolveExpiry($client, $domain, $details);
        if ($expiry) {
            $result['expirydate'] = $expiry;
        }

        return $result;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_TransferSync(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $domain = DomainHelper::getDomainName($params);
        $response = $client->request('domain/details', ['domain' => $domain]);
        $details = $response['domain'] ?? [];
        $state = strtoupper((string) ($details['state'] ?? ''));
        $subState = strtoupper((string) ($details['subState'] ?? ''));

        if ($state === 'ACTIVE') {
            return [
                'completed' => true,
                'expirydate' => resellerinterface_resolveExpiry($client, $domain, $details),
            ];
        }

        if (in_array($state, ['FAILED', 'INACTIVE'], true)) {
            return [
                'failed' => true,
                'reason' => 'Transfer fehlgeschlagen (Status: ' . $state . ')',
            ];
        }

        if ($subState === 'TRANSFER') {
            return ['pending' => true];
        }

        return ['pending' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

require_once __DIR__ . '/extras.php';

