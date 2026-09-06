<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\Resellerinterface;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/CoreApiClient.php';

/**
 * Loads registrar module settings for hooks and admin actions.
 */
class ModuleConfig
{
    private const MODULE = 'resellerinterface';

    /**
     * @return array<string, mixed>
     */
    public static function getStoredParams(): array
    {
        $params = [];

        try {
            $rows = Capsule::table('tblregistrars')
                ->where('registrar', self::MODULE)
                ->get(['setting', 'value']);

            foreach ($rows as $row) {
                $params[$row->setting] = decrypt($row->value);
            }
        } catch (\Throwable $e) {
            return $params;
        }

        return $params;
    }

    /**
     * Merge posted config form values with stored settings.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function getParamsFromRequest(array $request = []): array
    {
        $params = self::getStoredParams();
        $fields = [];

        if (!empty($request['fields']) && is_array($request['fields'])) {
            if (isset($request['fields'][self::MODULE]) && is_array($request['fields'][self::MODULE])) {
                $fields = $request['fields'][self::MODULE];
            } else {
                $fields = $request['fields'];
            }
        }

        $knownKeys = [
            'Username',
            'Password',
            'TotpCode',
            'ApiUrl',
            'ApiPrefix',
            'ResellerId',
            'DefaultHandleTag',
            'DefaultRedirectMode',
            'DebugMode',
        ];

        foreach ($knownKeys as $key) {
            $prefixed = self::MODULE . '_' . $key;
            if (isset($request[$prefixed])) {
                $fields[$key] = $request[$prefixed];
                continue;
            }
            if (isset($request['ri_' . $key])) {
                $fields[$key] = $request['ri_' . $key];
            }
        }

        if (isset($fields['ApiUrl']) && !CoreApiClient::isAllowedApiHost((string) $fields['ApiUrl'])) {
            unset($fields['ApiUrl']);
        }

        foreach ($fields as $key => $value) {
            if ($key === 'Password' && $value === '' && !empty($params['Password'])) {
                continue;
            }
            if ($key === 'TotpCode' && $value === '' && !empty($params['TotpCode'])) {
                continue;
            }
            $params[$key] = $value;
        }

        return $params;
    }
}
