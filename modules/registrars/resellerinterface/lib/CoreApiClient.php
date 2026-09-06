<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\Resellerinterface;

use Exception;

/**
 * HTTP client matching resellerinterface/api-client-php.
 */
class CoreApiClient
{
    private const SUCCESS_STATES = [1000, 1001, 1002, 1003];
    private const DEFAULT_API_URL = 'https://core.resellerinterface.de/';
    private const DEFAULT_API_PREFIX = 'stable';

    /** @var string|null */
    private static $sessionId;

    /** @var array */
    private $params;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $version;

    /** @var bool */
    private $debug;

    public function __construct(array $params)
    {
        $this->params = $params;
        $this->debug = !empty($params['DebugMode']);

        $baseUrl = trim((string) ($params['ApiUrl'] ?? self::DEFAULT_API_URL));
        if ($baseUrl === '' || !self::isAllowedApiHost($baseUrl)) {
            $baseUrl = self::DEFAULT_API_URL;
        }
        $baseUrl = rtrim($baseUrl, '/') . '/';

        $version = trim((string) ($params['ApiPrefix'] ?? self::DEFAULT_API_PREFIX), '/');
        if ($version === '') {
            $version = self::DEFAULT_API_PREFIX;
        }

        if (preg_match('#/(stable|latest|dev|v\d+)/?$#', $baseUrl, $matches)) {
            $version = $matches[1];
            $baseUrl = preg_replace('#/(stable|latest|dev|v\d+)/?$#', '/', $baseUrl) ?: $baseUrl;
        }

        $this->baseUrl = $baseUrl;
        $this->version = $version;
    }

    /**
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function request(string $endpoint, array $data = []): array
    {
        $this->ensureAuthenticated();

        return $this->send($endpoint, $data);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function testConnection(): array
    {
        self::$sessionId = null;
        $this->ensureAuthenticated();

        return $this->request('domain/list', ['limit' => 1]);
    }

    /**
     * @throws Exception
     */
    private function ensureAuthenticated(): void
    {
        if (self::$sessionId) {
            return;
        }

        $username = trim((string) ($this->params['Username'] ?? ''));
        $password = (string) ($this->params['Password'] ?? '');
        $resellerId = trim((string) ($this->params['ResellerId'] ?? ''));

        if ($username === '' || $password === '') {
            throw new Exception('API-Benutzername oder Passwort fehlt in der Modulkonfiguration.');
        }

        if ($resellerId === '') {
            throw new Exception(
                'Reseller-ID fehlt. Der Login erwartet username, password und resellerId '
                . '(siehe CoreAPI: $client->login("username", "password", 23456)).'
            );
        }

        $loginData = [
            'username' => $username,
            'password' => $password,
            'resellerId' => $resellerId,
        ];

        if (!empty($this->params['TotpCode'])) {
            $loginData['totp'] = $this->params['TotpCode'];
        }

        $this->send('reseller/login', $loginData);

        if (!self::$sessionId) {
            throw new Exception('Login erfolgreich, aber Session-Cookie (coreSID) nicht erhalten.');
        }
    }

    /**
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws Exception
     */
    private function send(string $endpoint, array $data): array
    {
        $action = trim($endpoint, '/');
        $url = $this->baseUrl . $this->version . '/' . $action;
        $postFields = $this->buildPostArray($data);

        $responseHeaders = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_USERAGENT, 'api-client-php/1.0.6 php/' . PHP_VERSION);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($client, $headerLine) use (&$responseHeaders) {
            $responseHeaders .= $headerLine;
            return strlen($headerLine);
        });

        if (self::$sessionId) {
            curl_setopt($ch, CURLOPT_COOKIE, 'coreSID=' . self::$sessionId);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('API-Verbindungsfehler: ' . $curlError);
        }

        if (preg_match('/^Set-Cookie:\scoreSID=*([^;]*)/mi', $responseHeaders, $cookie)) {
            self::$sessionId = $cookie[1];
        }

        if ($this->debug && function_exists('logModuleCall')) {
            logModuleCall(
                'resellerinterface',
                $action,
                ['url' => $url, 'effectiveUrl' => $effectiveUrl, 'fields' => array_keys($postFields)],
                'HTTP ' . $httpCode . "\n" . $responseHeaders . "\n" . $response,
                null,
                [$this->params['Password'] ?? '', self::$sessionId ?? '']
            );
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new Exception($this->invalidResponseMessage($url, $httpCode, $contentType, (string) $response));
        }

        $state = (int) ($decoded['state'] ?? 0);
        if ($state && !in_array($state, self::SUCCESS_STATES, true)) {
            $message = $decoded['stateName'] ?? 'Unbekannter Fehler';
            if (!empty($decoded['stateParam'])) {
                $message .= ' (' . $decoded['stateParam'] . ')';
            }
            throw new Exception('API-Fehler [' . $state . ']: ' . $message);
        }

        if (!empty($decoded['coreSID'])) {
            self::$sessionId = (string) $decoded['coreSID'];
        }

        return $decoded;
    }

    /**
     * Only ResellerInterface CoreAPI hosts are accepted.
     */
    public static function isAllowedApiHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, [
            'core.resellerinterface.de',
            'core.do.de',
            'core.domainreselling.de',
        ], true);
    }

    /**
     * Same structure as resellerinterface/api-client-php Client::buildPostArray().
     *
     * @param array $current
     * @param array $return
     * @param string $prefix
     * @return array<string, string>
     */
    private function buildPostArray(array $current, array $return = [], string $prefix = ''): array
    {
        foreach ($current as $key => $value) {
            $newPrefix = $prefix !== '' ? $prefix . '[' . $key . ']' : (string) $key;

            if (is_array($value)) {
                $return = $this->buildPostArray($value, $return, $newPrefix);
                continue;
            }

            if ($value === true) {
                $value = 'true';
            } elseif ($value === false) {
                $value = 'false';
            } elseif ($value === null) {
                continue;
            }

            $return[$newPrefix] = (string) $value;
        }

        return $return;
    }

    /**
     * @param string $url
     * @param int $httpCode
     * @param string $contentType
     * @param string $response
     * @return string
     */
    private function invalidResponseMessage(string $url, int $httpCode, string $contentType, string $response): string
    {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($response)) ?? '');
        if (strlen($snippet) > 220) {
            $snippet = substr($snippet, 0, 220) . '…';
        }

        $message = 'Ungültige API-Antwort von ' . $url . ' (HTTP ' . $httpCode . ')';
        if ($contentType !== '') {
            $message .= ', Content-Type: ' . $contentType;
        }
        if ($snippet !== '') {
            $message .= ': ' . $snippet;
        }

        if (stripos($snippet, 'XML') !== false || stripos($contentType, 'xml') !== false) {
            $message .= ' Hinweis: In ResellerInterface unter Verwaltung → Einstellungen → API-Zugriff '
                . 'die IPv4-Adresse dieses WHMCS-Servers freigeben. Reseller-ID muss gesetzt sein.';
        }

        return $message;
    }
}
