<?php

declare(strict_types=1);

use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Module\Registrar\Resellerinterface\DomainHelper;
use WHMCS\Module\Registrar\Resellerinterface\TldPricing;
use WHMCS\Results\ResultsList;

/**
 * @param array $params
 * @param array $payload
 * @return array
 */
function resellerinterface_applyOrderOptions(array $params, array $payload): array
{
    if (!empty($params['AutoDnssec'])) {
        $payload['autoDnssec'] = true;
    }

    $additional = (array) ($params['additionalfields'] ?? []);
    if (!empty($params['UseTrustee']) || !empty($additional['Trustee']) || !empty($additional['trustee'])) {
        $payload['trustee'] = true;
    }

    unset($additional['Trustee'], $additional['trustee']);
    if ($additional !== []) {
        $payload['tldExotic'] = $additional;
    }

    return $payload;
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_RestoreDomain(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $years = max(1, (int) ($params['regperiod'] ?? 1));
        $client->request('domain/restore', [
            'domain' => DomainHelper::getDomainName($params),
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
 * @return array<string, mixed>
 */
function resellerinterface_UndeleteDomain(array $params): array
{
    return resellerinterface_simpleDomainCall($params, 'domain/undelete');
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_CancelTransfer(array $params): array
{
    return resellerinterface_simpleDomainCall($params, 'domain/cancelTransfer');
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_ResendIRTPVerificationEmail(array $params): array
{
    return resellerinterface_simpleDomainCall($params, 'domain/requestResendIrtpContactVerification');
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_EnableDnssec(array $params): array
{
    return resellerinterface_simpleDomainCall($params, 'dns/enableDnssec');
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_DisableDnssec(array $params): array
{
    return resellerinterface_simpleDomainCall($params, 'dns/disableDnssec');
}

/**
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_ActivateDomainSafe(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $client->request('domain/activateDomainSafe', [
            'domain' => DomainHelper::getDomainName($params),
            'revocationAccepted' => true,
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
function resellerinterface_DeactivateDomainSafe(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $client->request('domain/deactivateDomainSafe', [
            'domain' => DomainHelper::getDomainName($params),
            'instantCancel' => true,
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
function resellerinterface_AddTrustee(array $params): array
{
    try {
        $client = resellerinterface_client($params);
        $client->request('domain/addTrustee', [
            'domain' => DomainHelper::getDomainName($params),
            'paymentAccepted' => true,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * IPS-Tag / Registrar-Tag (.uk u. a.).
 *
 * @param array $params
 * @return array<string, mixed>
 */
function resellerinterface_ReleaseDomain(array $params): array
{
    try {
        $tag = trim((string) ($params['transfertag'] ?? $params['newtag'] ?? ''));
        if ($tag === '') {
            throw new Exception('Registrar-Tag fehlt.');
        }

        $client = resellerinterface_client($params);
        $client->request('domain/setRegistrarTag', [
            'domain' => DomainHelper::getDomainName($params),
            'registrarTag' => $tag,
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @param array $params
 * @return ResultsList|array<string, string>
 */
function resellerinterface_GetTldPricing(array $params)
{
    try {
        if (!class_exists(ResultsList::class) || !class_exists(ImportItem::class)) {
            return ['error' => 'WHMCS TLD-Importklassen sind in dieser Version nicht verfügbar.'];
        }

        $client = resellerinterface_client($params);
        $results = new ResultsList();
        $offset = 0;
        $limit = 100;

        do {
            $response = $client->request('prices/domains', [
                'details' => true,
                'runtime' => 12,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            foreach (TldPricing::map($response) as $row) {
                $item = (new ImportItem())
                    ->setExtension($row['tld'])
                    ->setMinYears($row['minYears'])
                    ->setMaxYears($row['maxYears'])
                    ->setCurrency($row['currency']);

                if (!empty($row['register'][1])) {
                    $item->setRegisterPrice($row['register'][1]);
                }
                if (!empty($row['renew'][1])) {
                    $item->setRenewPrice($row['renew'][1]);
                }
                if (!empty($row['transfer'][1])) {
                    $item->setTransferPrice($row['transfer'][1]);
                }
                if (!empty($row['restore'])) {
                    $item->setRedemptionFeePrice($row['restore']);
                }

                $results[] = $item;
            }

            $fetched = count($response['tld'] ?? $response['list'] ?? []);
            $total = (int) ($response['total'] ?? $fetched);
            $offset += $limit;
        } while ($fetched === $limit && $offset < $total && $offset < 2000);

        return $results;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * @return array<string, string>
 */
function resellerinterface_AdminCustomButtonArray(): array
{
    return [
        'Verbindung testen' => 'TestConnection',
        'Domain wiederherstellen' => 'RestoreDomain',
        'Löschung widerrufen' => 'UndeleteDomain',
        'Transfer abbrechen' => 'CancelTransfer',
        'IRTP erneut senden' => 'ResendIRTPVerificationEmail',
        'DNSSEC aktivieren' => 'EnableDnssec',
        'DNSSEC deaktivieren' => 'DisableDnssec',
        'Domain-Safe an' => 'ActivateDomainSafe',
        'Domain-Safe aus' => 'DeactivateDomainSafe',
        'Trustee hinzufügen' => 'AddTrustee',
    ];
}

/**
 * @return array<string, string>
 */
function resellerinterface_ClientAreaCustomButtonArray(): array
{
    return [
        'Domain wiederherstellen' => 'RestoreDomain',
        'IRTP-Mail erneut senden' => 'ResendIRTPVerificationEmail',
        'DNSSEC aktivieren' => 'EnableDnssec',
        'DNSSEC deaktivieren' => 'DisableDnssec',
    ];
}

/**
 * @param array $params
 * @param string $endpoint
 * @return array<string, mixed>
 */
function resellerinterface_simpleDomainCall(array $params, string $endpoint): array
{
    try {
        $client = resellerinterface_client($params);
        $client->request($endpoint, [
            'domain' => DomainHelper::getDomainName($params),
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}
