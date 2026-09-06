# ResellerInterface (CoreAPI)

![ResellerInterface](logo.png)

WHMCS Domain-Registrar für die [ResellerInterface CoreAPI](https://core.resellerinterface.de/api) von [do.de](https://www.do.de) / Domain Offensive.

**Installationsziel:** `WHMCS_ROOT/modules/registrars/resellerinterface/`

Stellt Domains für WHMCS-Kunden bereit: Registrierung, Transfer, Verlängerung, Nameserver, Child-Nameserver, DNS, Kontakte, Authcode, Transfer-Lock und Sync.

## Installation

1. Diesen Ordner nach `WHMCS/modules/registrars/resellerinterface/` kopieren.
2. Admin: **Setup → Domain Registrars** (WHMCS 8: **Configuration → System Settings → Domain Registrars**).
3. **ResellerInterface CoreAPI** aktivieren und konfigurieren.
4. TLDs unter **Setup → Domain Pricing** anlegen und diesem Registrar zuweisen.

## Konfiguration

| Feld | Pflicht | Beschreibung |
| --- | --- | --- |
| API-Benutzername | ja | Benutzer für `/reseller/login` |
| API-Passwort | ja | Passwort des API-Users |
| 2FA TOTP | nein | Nur wenn TOTP für den API-User aktiv ist |
| Reseller-ID | ja | Dritter Login-Parameter (`resellerId`) |
| Handle-Tag | nein | Tag für neu angelegte Handles (Standard: `whmcs`) |
| Standard Redirect-Modus | nein | Externe Nameserver oder interne DNS |
| Debug-Modus | nein | Schreibt API-Aufrufe ins WHMCS Module Log |

API-Endpunkt ist fest: `https://core.resellerinterface.de/stable/…`

## Voraussetzungen im ResellerInterface

- API-Benutzer anlegen.
- **Reseller-ID** kennen (Login: `username`, `password`, `resellerId`).
- IP des WHMCS-Servers freigeben: **Verwaltung → Einstellungen → API-Zugriff** (IPv4 und ggf. IPv6).
- Rechte für Domain-Bestellung, Domain-Verwaltung, Handles und ggf. DNS.

## Verbindung testen

Auf der Konfigurationsseite gibt es den Button **Verbindung testen**. Der Test loggt sich ein und ruft `domain/list` auf.

## Unterstützte WHMCS-Funktionen

| WHMCS | CoreAPI |
| --- | --- |
| Registrierung | `domain/create` |
| Transfer | `domain/transfer` |
| Verlängerung | `domain/renew` |
| Nameserver lesen / speichern | `domain/details`, `domain/setNameserver` |
| Child-Nameserver (Glue) | `domain/listHostObjects`, `domain/setHostObjects` |
| DNS-Records | `dns/listRecords`, `dns/setRecords` |
| Kontakte (WHOIS) | `handle/create`, `handle/details`, `domain/setHandles` |
| Transfer-Lock | `domain/setStatus` |
| Authcode / EPP | `domain/showAuthcode`, `domain/generateAuthcode` |
| Domain löschen | `domain/delete` |
| Verfügbarkeit | `domain/check` |
| ID Protection | `domain/update` (`whoisPrivacy`) |
| Sync / Transfer-Sync | `domain/details`, `billing/listForRenewal` |
| TLD-Preise importieren | `prices/domains` |
| Restore | `domain/restore` |
| Löschung widerrufen | `domain/undelete` |
| Transfer abbrechen | `domain/cancelTransfer` |
| IRTP erneut senden | `domain/requestResendIrtpContactVerification` |
| DNSSEC an/aus | `dns/enableDnssec`, `dns/disableDnssec` |
| Domain-Safe | `domain/activateDomainSafe`, `domain/deactivateDomainSafe` |
| Trustee | `domain/addTrustee` / `trustee` bei Bestellung |
| Registrar-Tag (.uk) | `domain/setRegistrarTag` |
| Inhaberwechsel | `domain/update` (`tradeOK`) |
| Exotic-TLD-Felder | `tldExotic` aus WHMCS Additional Fields |

Admin-Buttons (Domain-Detail): Restore, Undelete, Transfer abbrechen, IRTP, DNSSEC, Domain-Safe, Trustee.

Nicht Teil dieses Registrar-Moduls: Webspace, SSL, E-Mail, Tickets, Finanzen außer Domain-Preisen.

## Bestehende Domains importieren

WHMCS hat im Kunden-Tab **Domains** keinen reinen „Domain hinzufügen“-Button. Bestehende Domains so zuweisen, **ohne** neu zu registrieren:

1. **Orders → Add New Order**, Kunde wählen, Domain eintragen.
2. Order Confirmation, Invoice und E-Mail deaktivieren.
3. Bestellung annehmen und **Registrierung nicht an den Registrar senden**.
4. Registrar auf **ResellerInterface CoreAPI** setzen, Status **Active**.

Danach funktionieren Nameserver, Kontakte und DNS über das Modul, weil die Domain bei ResellerInterface schon existiert.
