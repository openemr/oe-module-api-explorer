# FHIR writes

Two panels test the write half of the FHIR API. Both use the token already in the Explorer
session, and the token stays on the server: the browser only sends bodies.

Get a write-capable token first: **Confidential** client, **Authorization Code**, **Fetch** once.

## FHIR writes panel

1. **Load** reads the write catalog and resolves the references the bodies need from your server:
   a patient, practitioner, facility, insurer, encounter and questionnaire, plus the patient's
   orders (a ServiceRequest that isn't a referral; referrals are skipped) and a DocumentReference
   for the Task loop. With only referrals on the patient, **Create missing fixtures** posts a lab
   order.
2. Pick a **Resource**. The line under it shows the scope required and whether your token has it,
   the ACL the route enforces, the verbs available, and any reference that couldn't be resolved.
3. The **Request body** is filled from the template with real ids substituted. Edit it freely;
   **reset to template** restores it.
4. Send it one of three ways:
   - **Send**: one POST or PUT, response shown as-is. A PUT carries **If-Match** when it is
     filled; it is filled from the last response's ETag, or from `W/"meta.versionId"` in the body
     when there is no ETag (the OpenEMR Docker image strips it with `Header unset ETag`).
   - **POST → PUT → verify**: create, update, read back.
   - **Run all resources**: the round trip for every resource, with a pass / fail / skipped list.

On an empty install, **Create missing fixtures** POSTs the prerequisites through the same API.

### What the round trip catches

- A create that answers 201 without an id. The id key differs by resource: most answer `uuid`,
  Encounter `euuid`, Appointment `pc_uuid`.
- A PUT that answers 200 with an empty body.
- A created resource that can't be read back.
- A body that references something unresolved is **skipped** with the reason, not sent broken.

### The catalog

`fhir_write_templates.php` has one entry per write route. Bodies mirror OpenEMR's own PHPUnit
fixtures (`tests/Tests/Fixtures/FHIR/*.json`), so a failure points at the server, not at a
hand-typed payload.

**Writable (23):** AllergyIntolerance, Appointment (create only), CarePlan, CareTeam, Condition,
Coverage, Device, Encounter, Goal, Immunization, Medication, MedicationRequest, Observation,
Organization, Patient, Person, Practitioner, PractitionerRole, Questionnaire,
QuestionnaireResponse, RelatedPerson, ServiceRequest, Task.

**Routes that answer 405 (7):** DiagnosticReport, DocumentReference, Group, Location,
MedicationDispense, Procedure, Provenance. These have POST/PUT routes that deliberately answer
"not supported". The suite passes them on a 405 and reports them as skipped when the scope check
answers 401/403 first.

Observation needs OpenEMR PR #14217. On a server without it, Register Clients leaves the scope
out and the panel shows Observation as "scope missing".

### Observation variants

Observation writes cover vital signs. The server requires a `vital-signs` category, an encounter
that belongs to the patient, and an `effectiveDateTime`. Vitals posted for one encounter at one
time share a row, so a POST for a reading that row already holds is refused, and a PUT can't
change the date. Each body therefore gets a fresh `{{effective}}` timestamp.

With Observation selected, a dropdown next to *reset to template* offers more bodies:

| Variant | Expect |
|---|---|
| Body weight in kg, temperature in Cel | 201, converted to storage units |
| Blood pressure panel (components), pulse oximetry | 201 |
| LOINC coding listed second | 201 |
| No encounter, no category | 400 |
| Smoking status (social-history), BMI | 400, read-only |
| Zero value, unit that can't be converted | 400 |
| Body height in cm | 201, converted to in |
| Heart rate 900 /min | 400, "above the largest value accepted" (each column has a plausibility limit) |
| Vital signs panel 85353-1 | 400, assembled from its members on read |
| Glucose under category `laboratory` | 400: lab results are written through their procedure order |

Category is half of the route: the same code can be a vital sign under `vital-signs` and a lab
result under `laboratory`, so a write without a category is refused.

Running the round trip with the default body also runs every variant and two follow-up checks:
a repeat POST of the same reading and a PUT that moves the date must both be refused.

### Task and the referral loop

Task needs OpenEMR #14614, #14660 and #14669; the referral side needs #14708, #14711, #14715
and #14962 (openemr #14710 tracks the referral loop). The referral is a ServiceRequest; its Task
says where it stands: `focus` the referral, `owner` who receives it, `requester` who sent it,
and the reply in `output`.

`{{serviceRequest}}` is one of the patient's **orders, never a referral**. OpenEMR takes any Task
with code `fulfill`, no `partOf` and a ServiceRequest focus for a referral's own Task. Posted on
a real referral, the workbench's Task would become the referral's status, send Refer By inbox
messages, and be cancelled by the referral's next save as a Task for another owner. If the
patient has no order, **Create missing fixtures** posts one. To work a real referral, use the
**Referral loop** panel below.

Running the round trip with the default body then walks the loop on the new Task:

| Step | Expect |
|---|---|
| Search by `focus`, and by `owner` + `status=requested` | 200, the Task is in the bundle |
| PUT `accepted`, then `in-progress` | 200 |
| PUT with a stale `If-Match: W/"1"` | 412 |
| PUT `completed` with `output` = the patient's DocumentReference typed LOINC 11488-4 Consult note (the reply as text when the patient has none) | 200 |
| Search `status=completed` | 200, the Task is in the bundle |
| PUT `in-progress` on the completed Task | 400, "can't become" |

Variants: a questionnaire assigned to the patient (SDC `complete-questionnaire`, Patient owner),
and three that must be refused: a Patient focus, a status outside R4, an output naming a
Practitioner.

The Explorer also asks for `user/DocumentReference.read` and `user/Binary.read`, to read the
reply a Task's output names, and `patient/Task.read` for the public client.

## Referral loop panel

Works the Task OpenEMR makes for a referral, the way the recipient's system would.

1. On a patient's Transactions screen, save a referral with Refer By, Refer To and a Referral
   Date. With the `fhir_referral_tasks` global on, saving it makes its Task: `requested`,
   owned by Refer To, requested by Refer By.
2. **Find referrals** lists the referral ServiceRequests (category `3457005`) and every Task that
   names each as focus. The referral's own Task is the latest one with code `fulfill`, no
   `partOf`, owned by the referral's performer, that still counts for the recipient (OpenEMR's
   `ReferralTaskService::recipientTask()`): one OpenEMR set aside itself (cancelled because the
   referral went elsewhere, lost its recipient or was deleted; entered-in-error because its reply
   was cleared) doesn't. After a **Re-send** the older Tasks are listed as *earlier*.
3. Pick one, then **Accept**, **In progress**, **On hold**, **Reject**, **Fail**, **Cancel**,
   **Complete** or **Entered in error**. Each reads the Task and PUTs it back with the new status
   and `If-Match` set to its version. The reason field becomes `statusReason` for rejected,
   failed and cancelled, and `businessStatus` otherwise. **send a stale If-Match** must get 412.
4. Record a **Reply Date** in the referral's Counter-Referral section and save it. The reply is
   filed as a document and becomes the Task's output (LOINC 11488-4 Consult note), and the Task
   completes.
5. **Follow output** reads `Task.output` -> DocumentReference -> its attachment's Binary and shows
   the reply text. The Binary is only fetched from this FHIR server.

6. **Probe locks** (OpenEMR #15139) tries to change the Task's `owner` (to its requester), `code`,
   `focus` (to another of the patient's ServiceRequests) and to set `partOf` (to another of the
   patient's Tasks). Every PUT carries an `If-Match` one version ahead. OpenEMR validates the body
   before it compares versions, so a server with the lock answers 400 naming the element, and one
   without it answers 412: nothing is written either way. An element is skipped when the patient
   has nothing valid to point it at, since an unresolvable reference would be refused first and
   hide the lock.
7. **What does this token reach?** (OpenEMR #15140) reads Task, referral and all ServiceRequests,
   Patient, Practitioner and Organization. A client an administrator limited to an address book
   entry (Admin > System > API Clients > *Limit to Address Book Entry*) reads only the Tasks that
   entry owns and the referrals it currently receives, and gets 403 everywhere else. Dynamic
   registration can't set the limit.

These change real referrals. The referral screen and worklist show the Task's status, and
accepted, rejected, completed and failed each send Refer By a "Referral" message in their
inbox. A completed, rejected, failed or cancelled Task can only become entered-in-error. A
completed Task (or a Reply Date) completes the referral; any other final status closes it.

What has no API, and is done on the referral page (OpenEMR #15139, #15203):

- **Re-send**, offered when the recipient's latest Task is rejected, failed or cancelled by them
  and the referral has no Reply Date. It makes a new `requested` Task and keeps the old one.
- **Notify Referral Recipients** (`fhir_referral_notify_recipient`, off by default): the recipient
  is told when a Task is made for them and on a Re-send, by Direct (the Referral C-CDA) when the
  address book entry has a Direct address, else by a plain email with no patient details. The
  referral page shows how they were told; each notice is in the `referral_notice` table.
- A **Direct reply** to a message sent about the referral is filed with it, and **Confirm it
  completes the referral** completes the Task with the reply's documents as Consult note outputs.

### Placeholders

`{{patient}}` `{{practitioner}}` `{{encounter}}` `{{facility}}` `{{orgProvider}}`
`{{orgInsurer}}` `{{questionnaire}}` `{{serviceRequest}}` `{{documentReference}}` come from the
resolved context. `{{unique}}` and
`{{uniqueDigits}}` keep repeat runs from colliding on natural keys such as an NPI.
`{{effective}}` is a fresh past timestamp per body, and `{{uniqueDate}}` a random past date
(Coverage refuses a second policy of the same type with the same start date).

`{{facility}}` and `{{orgProvider}}` are the same id: the Organization of a row in `facility`.
It's found through `Location.managingOrganization` (the primary business entity), or failing
that the first provider Organization that has an address or phone. Procedure providers are also
type `prov` but have neither.

### Adding a resource

Add an entry to `fhir_write_templates.php`:

```php
'Example' => [
    'scope' => 'user/Example.write',
    'acl' => 'patients/med',          // shown when a 401 needs explaining
    'verbs' => ['POST', 'PUT'],
    'readById' => true,
    'needs' => ['patient'],           // context keys the body references
    // 'idKey' => 'uuid',             // if the create response names the id differently
    'body' => ['resourceType' => 'Example', 'subject' => ['reference' => 'Patient/{{patient}}']],
    // 'variants' => ['name' => ['label' => '...', 'body' => [...], 'expect' => 400, 'contains' => 'phrase']],
],
```

Add `user/Example.read` and `user/Example.write` to `FHIR_READ_SCOPES` and `FHIR_WRITE_SCOPES` in
`config.php`, run Register Clients, and get a new token.

## Patient app writes panel

What a patient-facing app can write (OpenEMR #15138, #15144, #15162). With **Patient App FHIR
Writes** on (Admin > Config > Connectors), a patient token's QuestionnaireResponse is **held for
staff review** in *Miscellaneous > Patient App Submissions* and stays out of the chart until
approved. The mode decides which clients may write: *Confidential apps*, or *Confidential and
public apps*.

Only QuestionnaireResponse is served to patient apps today. Vital signs, AllergyIntolerance and
Condition have settings, but their help says "Not accepted yet in this version": the server
publishes no patient write scope for them, and a POST is refused whatever the setting.

Get a patient token: the **Public** client, Authorization Code, and **Patient Login** on OpenEMR's
sign-in page (the mode must allow public apps). **Token & server** shows what the token holds and
which patient write scopes the server publishes. A Patient Login token has no `patient` in the
token response unless `launch/patient` was asked for; the panel then takes the patient from the
id_token's `fhirUser` or from a Patient search, and says which.

**Run patient write checks** answers a Questionnaire (Home vital signs if it is seeded, else the
first with items) and checks:

| Step | Expect |
|---|---|
| POST QuestionnaireResponse | 201, `meta.tag` `held-for-review`, a Location |
| GET by id | 200, still tagged |
| Search `?patient=` | the held write is in the bundle |
| Search `?patient=&_sort=-authored` | information: held writes join only a search by `_id`, `patient`, `subject` and `_count`, so an app sorts on its side |
| PUT a revision | 200, still held |
| PUT with a body id that isn't the URL's | 400 |
| PUT on an id the app didn't write | 403 |
| POST for another patient | 403 |
| POST a vital-sign Observation, an AllergyIntolerance, a Condition | 401/403 (if one is ever accepted, the probe is withdrawn and the run says the server is newer) |
| PUT `entered-in-error` (withdraw), then GET, then PUT | 200, 410, 410 |

Tick **leave it for staff review** to skip the withdrawal and approve or deny it in Patient App
Submissions. Approved, it keeps its id and reads as an ordinary QuestionnaireResponse; denied, it
reads 410.

**Seed Home vital signs Questionnaire** (staff token with `user/Questionnaire.write`) adds the
questionnaire a patient app records home readings with (`patient_write_seeds.php`; canonical
`http://open-emr.org/fhir/Questionnaire/home-vital-signs`). Each item carries the LOINC code and
UCUM unit of its vital sign, so the same answers can become vital-sign Observations once OpenEMR
takes those from patient apps. The OpenEMR SMART Questionnaires app ships the same definition.

## FHIR write stress panel

Sends many writes at once to find what single requests can't: id allocator collisions,
transaction boundaries, duplicate uuids.

1. **Load resources**, tick the ones to hit.
2. Set **Iterations** (per resource, up to 200) and **Concurrency** (in flight, up to 32).
3. Choose **POST only** or **POST then PUT**.
4. **Dry run** shows what would be sent. **Run load** sends it.

The report gives per-resource success counts, status spread, p50 / p95 / max latency, whether
every create returned a distinct id, and a sample of failures.

**It writes real rows and can't delete them.** The FHIR API has no DELETE. Each row carries a run
tag in its free-text field so you can find it later; the panel prints the tag and sample SQL.
Observation vitals have no free-text field: look for the Vitals forms on the encounter used.

The fan-out runs server-side with `curl_multi`. Driving it from the browser wouldn't work: PHP's
session lock would run the requests one after another.
