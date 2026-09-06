# do-de-resellerinterface

WHMCS-Module für die [ResellerInterface CoreAPI](https://core.resellerinterface.de/api) (domainreselling.de / do.de).

## Repository-Inhalt

| Pfad | Beschreibung |
| --- | --- |
| `modules/registrars/resellerinterface/` | WHMCS-Registrar-Modul (aktuell das einzige Modul) |

Weitere WHMCS-Modularten (Provisioning, Addons, Gateways) sind in diesem Repo nicht enthalten.

---

## Modul: ResellerInterface CoreAPI

**Typ:** WHMCS Domain Registrar  
**Installationsziel:** `WHMCS_ROOT/modules/registrars/resellerinterface/`  
**Anzeigename:** ResellerInterface (CoreAPI)

Stellt Domains für WHMCS-Kunden über die ResellerInterface CoreAPI bereit: Registrierung, Transfer, Verlängerung, Nameserver, Child-Nameserver, DNS, Kontakte, Authcode, Transfer-Lock und Sync.

### Dateien

```
modules/registrars/resellerinterface/
├── resellerinterface.php   # WHMCS-Registrar-Funktionen
├── hooks.php               # Verbindungstest auf der Registrar-Seite
├── whmcs.json              # Modul-Metadaten
├── index.php               # Schutz-Redirect
└── lib/
    ├── CoreApiClient.php   # HTTP-Client (Login, Session, Requests)
    ├── DomainHelper.php    # Domain-, NS- und DNS-Hilfen
    ├── HandleManager.php   # Handles / WHOIS-Kontakte
    └── ModuleConfig.php    # Gespeicherte Registrar-Einstellungen
```

### Installation

1. Ordner `modules/registrars/resellerinterface/` nach `WHMCS/modules/registrars/` kopieren.
2. Admin: **Setup → Domain Registrars** (WHMCS 8: **Configuration → System Settings → Domain Registrars**).
3. **ResellerInterface CoreAPI** aktivieren und konfigurieren.
4. TLDs unter **Setup → Domain Pricing** anlegen und diesem Registrar zuweisen.

### Konfiguration

| Feld | Pflicht | Beschreibung |
| --- | --- | --- |
| API-Benutzername | ja | Benutzer für `/reseller/login` |
| API-Passwort | ja | Passwort des API-Users |
| 2FA TOTP | nein | Nur wenn TOTP für den API-User aktiv ist |
| API-URL | ja | Standard: `https://core.resellerinterface.de` |
| API-Version | ja | Standard: `stable` → `/stable/…` |
| Reseller-ID | ja | Dritter Login-Parameter (`resellerId`) |
| Handle-Tag | nein | Tag für neu angelegte Handles (Standard: `whmcs`) |
| Standard Redirect-Modus | nein | Externe Nameserver oder interne DNS |
| Debug-Modus | nein | Schreibt API-Aufrufe ins WHMCS Module Log |

Erlaubte API-Hosts (alles andere wird ignoriert):

- `core.resellerinterface.de`
- `core.do.de`
- `core.domainreselling.de`

### Voraussetzungen im ResellerInterface

- API-Benutzer anlegen.
- **Reseller-ID** kennen (Login: `username`, `password`, `resellerId`).
- IP des WHMCS-Servers freigeben: **Verwaltung → Einstellungen → API-Zugriff** (IPv4 und ggf. IPv6).
- Rechte für Domain-Bestellung, Domain-Verwaltung, Handles und ggf. DNS.

### Verbindung testen

Auf der Konfigurationsseite des Moduls gibt es den Button **Verbindung testen** (neben Debug-Modus / Verbindungstest).

Der Test loggt sich ein und ruft `domain/list` auf. Es werden nur die Felder dieses Moduls verwendet, nicht die anderer Registrar-Module auf derselben Seite.

### Unterstützte WHMCS-Funktionen

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
| Sync / Transfer-Sync | `domain/details` |

### Bestehende Domains importieren

WHMCS hat im Kunden-Tab **Domains** keinen reinen „Domain hinzufügen“-Button. Bestehende Domains aus dem ResellerInterface so zuweisen, **ohne** neu zu registrieren:

1. **Orders → Add New Order**, Kunde wählen, Domain eintragen.
2. Order Confirmation, Invoice und E-Mail deaktivieren.
3. Bestellung annehmen und **Registrierung nicht an den Registrar senden**.
4. Registrar auf **ResellerInterface CoreAPI** setzen, Status **Active**.

Danach funktionieren Nameserver, Kontakte und DNS über das Modul, weil die Domain bei ResellerInterface schon existiert.

### API-Hinweise

- Auth: `POST /stable/reseller/login`, danach Cookie `coreSID`.
- Parameter per POST, Antwort JSON.
- Offizieller Client: `composer require resellerinterface/api-client-php`.
- Session wiederverwenden; max. ca. 10 Verbindungen/Sekunde.
