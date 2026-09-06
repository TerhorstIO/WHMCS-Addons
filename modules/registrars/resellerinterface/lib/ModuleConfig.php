<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\Resellerinterface;

use WHMCS\Database\Capsule;

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

        foreach ($fields as $key => $value) {
            if (self::shouldKeepStoredSecret($key, $value, $params)) {
                continue;
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * After save, WHMCS password inputs are empty or show placeholders.
     *
     * @param string $key
     * @param mixed $value
     * @param array $params
     * @return bool
     */
    private static function shouldKeepStoredSecret(string $key, $value, array $params): bool
    {
        if (!in_array($key, ['Password', 'TotpCode'], true)) {
            return false;
        }

        if (empty($params[$key])) {
            return false;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return true;
        }

        return (bool) preg_match('/^[\.●•*]+$/', $raw);
    }
}
