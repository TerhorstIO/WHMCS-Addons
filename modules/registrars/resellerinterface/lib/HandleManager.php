<?php

declare(strict_types=1);

namespace WHMCS\Module\Registrar\Resellerinterface;

use Exception;

/**
 * Handle (contact) management for ResellerInterface.
 */
class HandleManager
{
    /** @var CoreApiClient */
    private $client;

    /** @var array */
    private $params;

    public function __construct(CoreApiClient $client, array $params)
    {
        $this->client = $client;
        $this->params = $params;
    }

    /**
     * Build handle map for domain registration/transfer.
     *
     * @param array $params WHMCS module parameters
     * @return array<string, string>
     * @throws Exception
     */
    public function buildHandlesForOrder(array $params): array
    {
        $tag = $this->getDefaultTag();

        return [
            'owner' => $this->createOrGetHandle($this->extractContact($params, ''), 'owner', $tag),
            'admin' => $this->createOrGetHandle($this->extractContact($params, 'admin'), 'admin', $tag),
            'tech' => $this->createOrGetHandle($this->extractContact($params, 'admin'), 'tech', $tag),
            'zone' => $this->createOrGetHandle($this->extractContact($params, ''), 'zone', $tag),
        ];
    }

    /**
     * @param string $domain
     * @return array<string, array<string, string>>
     * @throws Exception
     */
    public function getContactDetails(string $domain): array
    {
        $response = $this->client->request('domain/details', ['domain' => $domain]);
        $details = $response['domain'] ?? [];
        $handles = $details['handles'] ?? [];

        $roles = [
            'Registrant' => 'owner',
            'Admin' => 'admin',
            'Technical' => 'tech',
            'Billing' => 'zone',
        ];

        $contacts = [];

        foreach ($roles as $whmcsRole => $apiRole) {
            $alias = DomainHelper::extractHandleAlias(is_array($handles) ? $handles : [], $apiRole);
            if (!$alias) {
                continue;
            }

            $handle = $this->client->request('handle/details', ['alias' => $alias]);
            $data = $handle['handle'] ?? [];

            $contacts[$whmcsRole] = [
                'First Name' => (string) ($data['firstname'] ?? ''),
                'Last Name' => (string) ($data['lastname'] ?? ''),
                'Company Name' => (string) ($data['company'] ?? ''),
                'Email Address' => (string) ($data['email'] ?? ''),
                'Address 1' => (string) ($data['street'] ?? ''),
                'Address 2' => '',
                'City' => (string) ($data['city'] ?? ''),
                'State' => '',
                'Postcode' => (string) ($data['postcode'] ?? ''),
                'Country' => (string) ($data['country'] ?? ''),
                'Phone Number' => (string) ($data['telephone'] ?? ''),
                'Fax Number' => (string) ($data['fax'] ?? ''),
            ];
        }

        return $contacts;
    }

    /**
     * @param string $domain
     * @param array<string, array<string, string>> $contactDetails
     * @throws Exception
     */
    public function saveContactDetails(string $domain, array $contactDetails): void
    {
        $tag = $this->getDefaultTag();
        $handles = [];

        $map = [
            'Registrant' => 'owner',
            'Admin' => 'admin',
            'Technical' => 'tech',
            'Billing' => 'zone',
        ];

        foreach ($map as $whmcsRole => $apiRole) {
            if (empty($contactDetails[$whmcsRole])) {
                continue;
            }

            $handles[$apiRole] = $this->createOrGetHandle(
                $this->normalizeWhmcsContact($contactDetails[$whmcsRole]),
                $apiRole,
                $tag
            );
        }

        if ($handles === []) {
            throw new Exception('Keine Kontaktdaten zum Speichern vorhanden.');
        }

        $this->client->request('domain/setHandles', [
            'domain' => $domain,
            'handles' => $handles,
        ]);
    }

    /**
     * @param array<string, string> $contact
     * @param string $role
     * @param string $tag
     * @return string
     * @throws Exception
     */
    private function createOrGetHandle(array $contact, string $role, string $tag): string
    {
        $payload = [
            'type' => !empty($contact['company']) ? 'org' : 'person',
            'firstname' => $contact['firstname'],
            'lastname' => $contact['lastname'],
            'street' => $contact['street'],
            'city' => $contact['city'],
            'postcode' => $contact['postcode'],
            'country' => $contact['country'],
            'telephone' => $contact['telephone'],
            'email' => $contact['email'],
            'tag' => $tag,
            'additionalParams' => [],
            'useExisting' => true,
        ];

        if (!empty($contact['company'])) {
            $payload['company'] = $contact['company'];
        }

        if (!empty($contact['fax'])) {
            $payload['fax'] = $contact['fax'];
        }

        if (!empty($this->params['ResellerId'])) {
            $payload['resellerID'] = (int) $this->params['ResellerId'];
        }

        $response = $this->client->request('handle/create', $payload);

        if (empty($response['handleName'])) {
            throw new Exception('Handle für Rolle "' . $role . '" konnte nicht erstellt werden.');
        }

        return (string) $response['handleName'];
    }

    /**
     * @param array $params
     * @param string $prefix
     * @return array<string, string>
     */
    private function extractContact(array $params, string $prefix): array
    {
        if ($prefix === '') {
            return [
                'firstname' => (string) ($params['firstname'] ?? ''),
                'lastname' => (string) ($params['lastname'] ?? ''),
                'company' => (string) ($params['companyname'] ?? ''),
                'email' => (string) ($params['email'] ?? ''),
                'street' => trim(((string) ($params['address1'] ?? '')) . ' ' . ((string) ($params['address2'] ?? ''))),
                'city' => (string) ($params['city'] ?? ''),
                'postcode' => (string) ($params['postcode'] ?? ''),
                'country' => (string) ($params['countrycode'] ?? ''),
                'telephone' => (string) ($params['fullphonenumber'] ?? $params['phonenumber'] ?? ''),
                'fax' => '',
            ];
        }

        return [
            'firstname' => (string) ($params[$prefix . 'firstname'] ?? ''),
            'lastname' => (string) ($params[$prefix . 'lastname'] ?? ''),
            'company' => (string) ($params[$prefix . 'companyname'] ?? ''),
            'email' => (string) ($params[$prefix . 'email'] ?? ''),
            'street' => trim(((string) ($params[$prefix . 'address1'] ?? '')) . ' ' . ((string) ($params[$prefix . 'address2'] ?? ''))),
            'city' => (string) ($params[$prefix . 'city'] ?? ''),
            'postcode' => (string) ($params[$prefix . 'postcode'] ?? ''),
            'country' => (string) ($params[$prefix . 'country'] ?? ''),
            'telephone' => (string) ($params[$prefix . 'fullphonenumber'] ?? $params[$prefix . 'phonenumber'] ?? ''),
            'fax' => '',
        ];
    }

    /**
     * @param array<string, string> $contact
     * @return array<string, string>
     */
    private function normalizeWhmcsContact(array $contact): array
    {
        return [
            'firstname' => (string) ($contact['First Name'] ?? ''),
            'lastname' => (string) ($contact['Last Name'] ?? ''),
            'company' => (string) ($contact['Company Name'] ?? ''),
            'email' => (string) ($contact['Email Address'] ?? ''),
            'street' => trim(((string) ($contact['Address 1'] ?? '')) . ' ' . ((string) ($contact['Address 2'] ?? ''))),
            'city' => (string) ($contact['City'] ?? ''),
            'postcode' => (string) ($contact['Postcode'] ?? ''),
            'country' => (string) ($contact['Country'] ?? ''),
            'telephone' => (string) ($contact['Phone Number'] ?? ''),
            'fax' => (string) ($contact['Fax Number'] ?? ''),
        ];
    }

    /**
     * @return string
     */
    private function getDefaultTag(): string
    {
        $tag = trim((string) ($this->params['DefaultHandleTag'] ?? ''));

        return $tag !== '' ? $tag : 'whmcs';
    }
}
