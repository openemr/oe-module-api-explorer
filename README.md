# OpenEMR API Explorer

A browser tool for learning, exercising and testing OpenEMR's OAuth2, SMART on FHIR, FHIR and
standard REST APIs. It registers its own API clients, walks every grant type, reads and writes
resources, and checks that the scopes a token carries are the scopes the server enforces.

It's a working reference for anyone building an app against OpenEMR, and a test bench for anyone
changing the API itself.

> **Development tool.** It skips the OpenEMR login, keeps client secrets and private keys on disk,
> and turns off TLS verification so self-signed dev certificates work. Run it on a development
> install with test data. See [SECURITY.md](SECURITY.md).

Dedicated to the OpenEMR community. *Jerry Padgett*

---

## What you get

| Panel | What it does |
|---|---|
| **Request** | Pick a client, grant, API and resource, add a query, and fetch. Shows the raw response. |
| **Bulk $export** | Lists Groups, starts a Group `$export`, polls it, and lists the NDJSON files. |
| **FHIR writes** | POST / PUT workbench for 23 writable resources, with a full POST → PUT → verify round trip. |
| **Referral loop** | Works a referral's own Task: accept, reject, complete with If-Match, then follow the reply to its DocumentReference and Binary. |
| **FHIR write stress** | Fires many writes at once and reports latency, status spread and duplicate ids. |
| **Scope Lab** | Tests SMART v1, v2 and mixed scope sets across grant types: what was granted, and what is enforced. |
| **SMART launch workspace** | Appears when OpenEMR launches the Explorer as a SMART app: launch context, patient, questionnaires. |

Clients and grants covered:

| Client | Grant | Typical use |
|---|---|---|
| Confidential | Authorization Code (+ refresh) | Provider-facing apps. Carries the write scopes. |
| Public | Authorization Code with PKCE | Patient-facing and browser apps. |
| JWT | Client Credentials (signed assertion) | Backend services, bulk export. |
| SMART EHR Launch | Authorization Code with `launch` | Apps launched from inside OpenEMR. |

---

## Quick start

You need a running OpenEMR development install (8.0 or current master; PHP 8.2+).

**1. Put the folder two levels below the OpenEMR root**

```bash
cd /path/to/openemr
mkdir -p devtools
git clone https://github.com/sjpadgett/oe-module-api-explorer.git devtools/oe-module-api-explorer
```

**2. Turn the APIs on in OpenEMR** (Admin → Config → Connectors)

- Site Address (required for OAuth2 and FHIR): the URL you browse OpenEMR at
- Enable OpenEMR Standard REST API
- Enable OpenEMR Standard FHIR REST API
- Enable OpenEMR FHIR System Scopes (needed for the JWT client and bulk export)

**3. Open the Explorer**

```
https://localhost/openemr/devtools/oe-module-api-explorer/oeApiExplorer.php
```

**4. Click Register Clients.** This creates the key pair and the four clients, and enables them.

**5. Pick Confidential + Authorization Code + FHIR + Patient, and click Fetch.** Log in, approve
the scopes, and the Patient bundle comes back.

If your OpenEMR isn't at `https://localhost/openemr`, copy `config.local.sample.php` to
`config.local.php` and set your URL there first. Details are in [INSTALLATION.md](INSTALLATION.md).

---

## Configuration

All your settings go in **`config.local.php`**, which git ignores. Don't edit `config.php` for
this: you'd get conflicts on every pull.

```bash
cp config.local.sample.php config.local.php
```

```php
return [
    'sites' => [
        'localhost' => 'https://localhost/openemr',
        'docker'    => 'https://localhost:9300',
    ],
    'default_site' => 'localhost',
];
```

| Key | Default | Meaning |
|---|---|---|
| `sites` | `localhost` → `https://localhost/openemr` | Servers in the Site dropdown: name → base URL |
| `default_site` | first site | Site selected on first load |
| `openemr_site` | `default` | OpenEMR multisite id in the API paths |
| `use_keys_file` | `false` | Register a `jwks_uri` instead of sending the key set inline |
| `observation_write` | `true` | Ask for `user/Observation.write` |
| `allow_remote` | `false` | Answer requests from outside this machine / private networks |
| `app_url` | derived | Public URL of this folder, for reverse proxies |

Register clients once per site. A site name starting with `remote-` means "this install can't
reach that server's database": existing clients there are left alone, and you enable the new
ones on that server (Admin → System → API Clients).

**Scopes** each client asks for are constants in `config.php` (`SYSTEM_SCOPES`, `LIMITED_SCOPES`,
`PUBLIC_SCOPES`, `SMART_SCOPES`, and the read/write sets appended to `LIMITED_SCOPES`).
Register Clients compares them with the server's published list and leaves out anything the
server doesn't offer, so one checkout works across OpenEMR versions. After changing a scope
constant, run Register Clients again and get a new token.

---

## Guides

| Document | Read it for |
|---|---|
| [INSTALLATION.md](INSTALLATION.md) | Full setup, Docker and reverse-proxy notes, troubleshooting |
| [FHIR_WRITES.md](FHIR_WRITES.md) | The write workbench, the resource catalog, Observation variants, stress testing |
| [SCOPE_LAB.md](SCOPE_LAB.md) | Scope profiles, how grants and enforcement are judged, reading the results |
| [SMART_TESTING.md](SMART_TESTING.md) | Launching the Explorer from OpenEMR as a SMART app |
| [SECURITY.md](SECURITY.md) | What this tool bypasses and how to keep it contained |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Adding a resource, a scope profile or a panel |
| [CHANGELOG.md](CHANGELOG.md) | What changed |

The same help is available inside the Explorer, at the bottom of the page.

---

## How it fits together

```
oeApiExplorer.php        main page: session bar, Request panel, includes the cards
  ui_panel.php           collapsible panel helper
  oauth_client.php       authorization-code, client-credentials and refresh flows
  scope_resources.php    resource dropdown, built from the client's scopes
  smart_launch.php       SMART EHR launch entry (iss + launch)
  group_export.php       Bulk $export endpoint

client_register.php      key pair + dynamic registration of the four clients
src/JwkService.php       RSA key pair and JWKS

fhir_write_card.php      FHIR writes panel
fhir_write.php           its endpoint: catalog, context, seed, send, verify
fhir_write_templates.php the write catalog: bodies, scopes, ACLs, variants
fhir_write_seeds.php     prerequisite fixtures for an empty install
fhir_stress_card.php     stress panel
fhir_stress.php          its endpoint (curl_multi fan-out)

scope_lab_card.php       Scope Lab panel
scope_lab.php            its endpoint
scope_lab_lib.php        registration, tokens, probes, verdicts
scope_lab_profiles.php   the scope sets under test
scope_lab_callback.php   OAuth redirect for Scope Lab clients
src/ScopeAlgebra.php     SMART v1/v2 scope parsing and comparison

config.php               defaults, endpoint map, scope constants
config.local.sample.php  copy to config.local.php
clients_keys/            generated keys and client credentials (git-ignored)
```

Access tokens stay in the PHP session. The browser sends request bodies and gets results back;
it never holds a token or a client secret.

---

## Requirements

- OpenEMR 8.0 or current master, with the REST and FHIR APIs enabled
- PHP 8.2+ with the curl and openssl extensions
- HTTPS on the OpenEMR site (OAuth2 requires it; a self-signed certificate is fine)
- An OpenEMR user with broad ACLs for the write panels. An administrator covers every route.

No Composer install and no build step: it uses the libraries OpenEMR already ships.

---

## Contributing and support

Issues and pull requests are welcome at
<https://github.com/sjpadgett/oe-module-api-explorer>. See [CONTRIBUTING.md](CONTRIBUTING.md).

This is a community project and not an official OpenEMR product.

## License

GNU General Public License v3. See [LICENSE](LICENSE).

© 2025-2026 Jerry Padgett
