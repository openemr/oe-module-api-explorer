# Changelog

## 2.2.0 (2026-11-11)

### Added
- **Patient app writes** panel (OpenEMR #15138, #15144, #15162): with a patient token, a
  QuestionnaireResponse held for staff review is created, read back, found by search, revised,
  withdrawn (then 410), and checked against the must-refuse cases (body id mismatch, an id the app
  didn't write, another patient, and vital-sign Observation / AllergyIntolerance / Condition, which
  have settings but are not served to patient apps yet). Can leave the write for staff review.
- **Seed Home vital signs Questionnaire**: a LOINC/UCUM-coded questionnaire for a patient's home
  readings, shared with the OpenEMR SMART Questionnaires app.
- **Check Clients** in the session bar: asks the site (RFC 7592 read) whether each saved client is
  still registered and enabled, and keeps the rotated registration token. A rebuilt database that
  lost the Explorer's clients is why an EHR launch stops offering the Explorer.
- Referral loop: **Probe locks** (owner, code, focus, partOf on a referral's Task, OpenEMR #15139)
  with a stale If-Match so nothing is written; **What does this token reach?** for clients limited
  to an address book entry (#15140); an *Earlier / other Tasks* column.
- Observation variants: height in cm, a heart rate above the plausibility limit, the vital signs
  panel 85353-1, and a lab result under category `laboratory` (refused: lab results are written
  through their procedure order).

### Fixed
- Referral loop picked the referral's Task by bundle order. After a Re-send that could be the old
  rejected Task. It now takes the latest own Task that still counts for the recipient, as
  OpenEMR does, and ignores Tasks OpenEMR set aside itself.
- Docs: a rejected or failed Task now closes its referral (only completed completes it); Re-send,
  recipient notices and Direct replies are on the referral page and have no API.
- Patient app writes: a Patient Login token without `launch/patient` has no `patient` in the
  token response, so the panel refused to run. The patient now comes from the id_token's
  `fhirUser`, else from a Patient search (a patient token reads only its own record).
- Home vital signs seed: OpenEMR ignores a client `url` on create and publishes its own, so the
  questionnaire also carries its canonical as an `identifier` (`urn:ietf:rfc:3986`), and the
  panel recognizes it by title plus url or identifier. Found by a live run against OpenEMR.

## 2.1.1 (2026-11-01)

### Fixed
- **If-Match** in the FHIR writes panel now refills from the response's `meta.versionId` when
  there is no ETag. The OpenEMR Docker image strips ETag (`Header unset ETag`), so a second PUT
  got 412. A POST that names no version clears the field.
- Docs: Load picks one of the patient's orders, never a referral; a note on the core 500 for the
  insurer Organization in Create missing fixtures.

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
