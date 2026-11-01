# Changelog

## 2.1.0 (2026-11-01)

### Added
- **Task** in the write catalog, for the referral loop (OpenEMR #14710): a referral Task body,
  a lifecycle check (search by focus and owner; accepted, in-progress, a stale If-Match refused
  with 412, completed with a Consult note in `Task.output`, no reopening), a patient
  questionnaire variant and three must-fail variants.
- **Referral loop** panel: finds referral ServiceRequests and their Tasks, moves a referral's own
  Task through accepted / in-progress / rejected / failed / cancelled / completed with If-Match,
  and follows `Task.output` to the reply's DocumentReference and Binary (OpenEMR #14715, #14962).
- `{{serviceRequest}}` (one of the patient's orders, never a referral: a Task on a referral is
  taken for its own Task) and `{{documentReference}}` context keys; a ServiceRequest order seed.
- **If-Match** on PUT in the FHIR writes panel, filled from the last response's ETag.
- Scopes: `user/Task.read` / `.write`, `user/DocumentReference.read`, `user/Binary.read`,
  `system/Task.read`, `system/Binary.read`, `patient/Task.read`. Run **Register Clients** again.

## 2.0.0 (2026-10-02)

First community release.

### Added
- **Scope Lab**: registers a client per scope profile and grant, compares requested, granted,
  JWT and introspected scopes as effective permissions, probes enforcement, tests refresh, and
  audits search results under granular (`?category=`) scopes for leaks. Markdown and JSON export.
- **FHIR write stress** panel: concurrent POST / PUT load with latency, status and duplicate-id
  reporting.
- **Write catalog** now covers 22 writable resources and the 7 routes that answer 405.
- **Observation writes** (OpenEMR PR #14217): variants, must-fail bodies, repeat-POST and
  date-change checks, fresh `effectiveDateTime` per body.
- `config.local.php` for your sites and options, with `config.local.sample.php` as the template.
- Access guard: local and private-network requests only, unless `allow_remote` is set.
- `clients_keys/.htaccess`, `.gitignore`, `LICENSE`, and the guides: `FHIR_WRITES.md`,
  `SCOPE_LAB.md`, `SECURITY.md`, `CONTRIBUTING.md`.

### Changed
- **Register Clients** reads the server's published scopes and leaves out the ones it doesn't
  offer, so a single checkout registers against different OpenEMR versions.
- Authorization requests ask for the scopes the client was registered with.
- Write-scope detection accepts any scope that grants create: `user/` or `system/`, v1 `.write`
  or v2 `.c…`.
- The OpenEMR multisite id and the Explorer's own URL are configurable; the folder name is no
  longer hard-coded.
- Rewritten README, installation guide and in-app help.

### Removed
- Site list and personal bookmarks from `config.php`.
- The hard-coded Group id in the resource list. Use the Bulk $export panel.

### Upgrading from an earlier copy
1. Move your site list into `config.local.php` (copy `config.local.sample.php`).
2. Run **Register Clients** for each site.
3. **Clear Session**.

## 1.x (2025 – mid 2026)

OAuth2 client registration, authorization-code / PKCE / client-credentials / refresh flows, FHIR
and standard API fetch, multi-site support, Bulk Group `$export`, SMART EHR launch with the
questionnaire workspace, and the first FHIR write workbench.
