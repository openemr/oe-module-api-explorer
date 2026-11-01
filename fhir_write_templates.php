<?php

/**
 * fhir_write_templates.php — the FHIR write matrix for the API Explorer.
 *
 * One entry per resource that the OpenEMR FHIR API accepts writes for. Each body is
 * the same fixture the PHPUnit write tests post (tests/Tests/Fixtures/FHIR/*.json plus
 * the reference injections those tests make in setUp), so a body that fails here is a
 * server-side problem rather than a hand-rolled payload problem.
 *
 * Placeholders are filled from fhir_write.php?action=context:
 *   {{patient}} {{practitioner}} {{encounter}} {{orgProvider}} {{orgInsurer}}
 *   {{facility}} {{questionnaire}} {{serviceRequest}} {{documentReference}}
 *   {{unique}} {{uniqueDigits}}
 *   {{effective}}  a fresh past timestamp, generated per body (not from the context call)
 *   {{uniqueDate}} a random past date (YYYY-MM-DD), generated per body
 *
 * Keys per resource:
 *   scope     the OAuth scope the token must carry
 *   acl       the OpenEMR ACL the route enforces, for when a 401 needs explaining
 *   verbs     POST and/or PUT -- Appointment is create-only, it has no PUT route
 *   readById  whether GET /fhir/<Resource>/{id} exists (all of them, as of the route
 *             that added GET /fhir/Questionnaire/{uuid})
 *   needs     context keys the body references, so the UI can say what is missing
 *   idKey     the key the create response names the new id with, when it is not `uuid`
 *             -- Encounter answers `euuid` and Appointment answers `pc_uuid`
 *   status    'supported' (default) or 'not-implemented': the route exists but answers
 *             405 via RestControllerHelper::fhirWriteNotImplemented(). Those entries are
 *             kept so the suite proves each still refuses cleanly; the stress panel skips them.
 *   reason    for not-implemented entries, the rationale the route gives
 *   variants  optional alternative bodies: name => [label, body, expect?, contains?]. expect
 *             defaults to 201 (full POST -> PUT -> verify); 400 means the POST must be refused,
 *             with `contains` somewhere in the response
 *   checks    optional follow-ups after the default round trip (see Observation, and
 *             Task's `lifecycle`: ordered PUT / search steps on the Task just created)
 *
 * Matches the write routes in apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php as of
 * openemr master 69dee5d (2026-10-02), plus Observation from PR #14217 (4f6c939) and Task
 * from #14614, #14660 and #14669: 23 writable resources and 7 routes that answer 405.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

return [
    'AllergyIntolerance' => [
        'scope' => 'user/AllergyIntolerance.write',
        'acl' => 'patients/med',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'AllergyIntolerance',
            'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', 'code' => 'active', 'display' => 'Active']]],
            'verificationStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification', 'code' => 'confirmed', 'display' => 'Confirmed']]],
            'category' => ['medication'],
            'criticality' => 'high',
            'patient' => ['reference' => 'Patient/{{patient}}'],
            'code' => ['coding' => [['system' => 'http://www.nlm.nih.gov/research/umls/rxnorm', 'code' => '7980', 'display' => 'Penicillin G']], 'text' => 'Penicillin G'],
            'reaction' => [['manifestation' => [['coding' => [['system' => 'http://snomed.info/sct', 'code' => '247472004', 'display' => 'Hives']], 'text' => 'Hives']]]],
            'note' => [['text' => 'explorer-write {{unique}}']],
        ],
    ],

    'Appointment' => [
        'scope' => 'user/Appointment.write',
        'idKey' => 'pc_uuid',
        'acl' => 'patients/appt',
        // No PUT /fhir/Appointment/{uuid} route exists, and FhirAppointmentService
        // declares no update interface. Create-only until that is added.
        'verbs' => ['POST'],
        'readById' => true,
        // `facility` rather than `location`: FHIR Location covers patient home addresses
        // as well as facilities, and only a facility-backed Location resolves to the
        // pc_facility the appointment requires.
        'needs' => ['patient', 'facility'],
        'body' => [
            'resourceType' => 'Appointment',
            'status' => 'booked',
            'appointmentType' => ['coding' => [['code' => 'office_visit', 'display' => 'Office Visit']]],
            'start' => '2026-06-15T10:00:00-05:00',
            'end' => '2026-06-15T10:30:00-05:00',
            'comment' => 'explorer-write {{unique}}',
            'participant' => [
                ['actor' => ['reference' => 'Patient/{{patient}}'], 'status' => 'accepted'],
                ['actor' => ['reference' => 'Location/{{facility}}'], 'status' => 'accepted'],
            ],
        ],
    ],

    'CarePlan' => [
        'scope' => 'user/CarePlan.write',
        'acl' => 'patients/med',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient', 'encounter'],
        'body' => [
            'resourceType' => 'CarePlan',
            'status' => 'active',
            'intent' => 'plan',
            'category' => [['coding' => [['system' => 'http://hl7.org/fhir/us/core/CodeSystem/careplan-category', 'code' => 'assess-plan', 'display' => 'Assessment and Plan of Treatment']]]],
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'encounter' => ['reference' => 'Encounter/{{encounter}}'],
            'period' => ['start' => '2024-01-15'],
            'activity' => [
                ['detail' => [
                    'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '182840001', 'display' => 'drug therapy']], 'text' => 'drug therapy'],
                    'status' => 'in-progress',
                    'description' => 'explorer-write {{unique}}',
                    'scheduledPeriod' => ['start' => '2024-01-15'],
                ]],
            ],
        ],
    ],

    'CareTeam' => [
        'scope' => 'user/CareTeam.write',
        'acl' => 'patients/demo',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient', 'practitioner'],
        'body' => [
            'resourceType' => 'CareTeam',
            'status' => 'active',
            'name' => 'explorer-write {{unique}}',
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'participant' => [
                [
                    'role' => [['coding' => [['system' => 'http://snomed.info/sct', 'code' => '453231000124104', 'display' => 'Primary care provider']]]],
                    'member' => ['reference' => 'Practitioner/{{practitioner}}'],
                ],
            ],
        ],
    ],

    'Condition' => [
        'scope' => 'user/Condition.write',
        'acl' => 'patients/med',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'Condition',
            'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical', 'code' => 'active', 'display' => 'Active']]],
            'verificationStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status', 'code' => 'confirmed', 'display' => 'Confirmed']]],
            'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'problem-list-item', 'display' => 'Problem List Item']]]],
            'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '44054006', 'display' => 'Type 2 Diabetes Mellitus']], 'text' => 'Type 2 Diabetes Mellitus'],
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'onsetDateTime' => '2020-03-15',
            'note' => [['text' => 'explorer-write {{unique}}']],
        ],
    ],

    'Coverage' => [
        'scope' => 'user/Coverage.write',
        'acl' => 'admin/super',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient', 'orgInsurer'],
        'body' => [
            'resourceType' => 'Coverage',
            'status' => 'active',
            'type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'HIP', 'display' => 'health insurance plan number']]],
            'subscriberId' => 'explorer-{{unique}}',
            'beneficiary' => ['reference' => 'Patient/{{patient}}'],
            'payor' => [['reference' => 'Organization/{{orgInsurer}}']],
            'relationship' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/subscriber-relationship', 'code' => 'self', 'display' => 'Self']]],
            // {{uniqueDate}}: a policy of the same type with the same effective date for the same
            // patient is refused as a duplicate, so a fixed start date fails on every run after the first.
            'period' => ['start' => '{{uniqueDate}}', 'end' => '2099-12-31'],
            'order' => 1,
            'class' => [
                ['type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/coverage-class', 'code' => 'group', 'display' => 'Group']]], 'value' => 'explorer-group-{{unique}}'],
                ['type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/coverage-class', 'code' => 'plan', 'display' => 'Plan']]], 'value' => 'Explorer Plan'],
            ],
        ],
    ],

    'Device' => [
        'scope' => 'user/Device.write',
        'acl' => 'admin/super',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'Device',
            'patient' => ['reference' => 'Patient/{{patient}}'],
            'type' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '49062001', 'display' => 'device-implantable']], 'text' => 'device-implantable'],
            'udiCarrier' => [['deviceIdentifier' => '00643169007222', 'carrierHRF' => '(01)00643169007222(17)260101(10)explorer01']],
            'manufacturer' => 'Explorer Manufacturer Inc',
            'lotNumber' => 'explorer-{{unique}}',
            'serialNumber' => 'explorer-{{unique}}',
            'manufactureDate' => '2024-01-15',
            'expirationDate' => '2030-01-15',
        ],
    ],

    'Encounter' => [
        'scope' => 'user/Encounter.write',
        'idKey' => 'euuid',
        'acl' => 'encounters/auth_a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'Encounter',
            'status' => 'finished',
            'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB', 'display' => 'ambulatory'],
            'type' => [['coding' => [['system' => 'http://snomed.info/sct', 'code' => '185349003', 'display' => 'Encounter for check up (procedure)']]]],
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'period' => ['start' => '2024-01-15T10:00:00+00:00'],
            'reasonCode' => [['text' => 'explorer-write {{unique}}']],
        ],
    ],

    'Goal' => [
        'scope' => 'user/Goal.write',
        'acl' => 'admin/super',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient', 'encounter'],
        'body' => [
            'resourceType' => 'Goal',
            'lifecycleStatus' => 'active',
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'description' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '129004', 'display' => 'Healthy weight goal']], 'text' => 'explorer-write {{unique}}'],
            'startDate' => '2024-02-01',
            'target' => [['dueDate' => '2024-12-31']],
            'extension' => [[
                'url' => 'http://hl7.org/fhir/StructureDefinition/encounter-associatedEncounter',
                'valueReference' => ['reference' => 'Encounter/{{encounter}}'],
            ]],
        ],
    ],

    'Immunization' => [
        'scope' => 'user/Immunization.write',
        'acl' => 'patients/med',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'Immunization',
            'status' => 'completed',
            'patient' => ['reference' => 'Patient/{{patient}}'],
            'vaccineCode' => ['coding' => [['system' => 'http://hl7.org/fhir/sid/cvx', 'code' => '197', 'display' => 'influenza, high-dose seasonal, quadrivalent, preservative free']], 'text' => 'influenza, high-dose seasonal'],
            'occurrenceDateTime' => '2021-01-10',
            'primarySource' => true,
            'lotNumber' => 'explorer-{{unique}}',
            'expirationDate' => '2099-02-15',
            'site' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActSite', 'code' => 'LA', 'display' => 'left arm']]],
            'doseQuantity' => ['value' => 5, 'unit' => 'mg', 'system' => 'http://unitsofmeasure.org', 'code' => 'mg'],
            'note' => [['text' => 'explorer-write {{unique}}']],
        ],
    ],

    'Medication' => [
        'scope' => 'user/Medication.write',
        'acl' => 'admin/drugs',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => [
            'resourceType' => 'Medication',
            'status' => 'active',
            'code' => ['coding' => [['system' => 'http://www.nlm.nih.gov/research/umls/rxnorm', 'code' => '1049502', 'display' => 'explorer-medication']], 'text' => 'explorer-medication {{unique}}'],
            'form' => ['coding' => [['system' => 'http://ncimeta.nci.nih.gov', 'code' => 'C42998', 'display' => 'tablet']]],
        ],
    ],

    'MedicationRequest' => [
        'scope' => 'user/MedicationRequest.write',
        'acl' => 'patients/rx',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'MedicationRequest',
            'status' => 'active',
            'intent' => 'order',
            'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/medicationrequest-category', 'code' => 'community', 'display' => 'Home/Community']]]],
            'medicationCodeableConcept' => ['coding' => [['system' => 'http://www.nlm.nih.gov/research/umls/rxnorm', 'code' => '1049502', 'display' => 'acetaminophen 325 MG Oral Tablet']], 'text' => 'acetaminophen 325 MG Oral Tablet'],
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'authoredOn' => '2024-01-15T09:00:00+00:00',
            'dosageInstruction' => [['text' => 'Take 1 tablet by mouth every 6 hours as needed for pain']],
            'dispenseRequest' => ['quantity' => ['value' => 30, 'unit' => 'tablet']],
            'note' => [['text' => 'explorer-write {{unique}}']],
        ],
    ],

    'Person' => [
        'scope' => 'user/Person.write',
        'acl' => 'admin/users',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => [
            'resourceType' => 'Person',
            // FhirPersonService rejects a Person with no NPI: the OpenEMR record it maps
            // onto is a provider row, and `npi` is read out of this identifier.
            'identifier' => [['system' => 'http://hl7.org/fhir/sid/us-npi', 'value' => '{{uniqueDigits}}']],
            'active' => true,
            'name' => [['use' => 'official', 'family' => 'ExplorerLast', 'given' => ['ExplorerFirst', '{{unique}}'], 'prefix' => ['Dr.']]],
            'telecom' => [
                ['system' => 'phone', 'value' => '(555) 100-2000', 'use' => 'home'],
                ['system' => 'email', 'value' => 'explorer-person@example.invalid', 'use' => 'home'],
            ],
            'address' => [['line' => ['123 Explorer Ave'], 'city' => 'Testville', 'state' => 'CA', 'postalCode' => '90001']],
        ],
    ],

    'PractitionerRole' => [
        'scope' => 'user/PractitionerRole.write',
        'acl' => 'admin/users',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['practitioner', 'orgProvider'],
        'body' => [
            'resourceType' => 'PractitionerRole',
            'active' => true,
            'practitioner' => ['reference' => 'Practitioner/{{practitioner}}'],
            'organization' => ['reference' => 'Organization/{{orgProvider}}'],
            'code' => [['coding' => [['system' => 'http://nucc.org/provider-taxonomy', 'code' => '103T00000X', 'display' => 'Psychologist']]]],
            'specialty' => [['coding' => [['system' => 'http://nucc.org/provider-taxonomy', 'code' => '101Y00000X', 'display' => 'Counselor']]]],
        ],
    ],

    'Questionnaire' => [
        'scope' => 'user/Questionnaire.write',
        'acl' => 'admin/super',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => [
            'resourceType' => 'Questionnaire',
            'status' => 'active',
            'name' => 'explorerquestionnaire',
            // The repository keys questionnaires by title and rejects a duplicate on
            // create, so every run needs its own title.
            'title' => 'explorer-write Questionnaire {{unique}}',
            'item' => [
                ['linkId' => '1', 'type' => 'string', 'text' => 'What is your name?'],
                ['linkId' => '2', 'type' => 'decimal', 'text' => 'What is your age?'],
            ],
        ],
    ],

    'QuestionnaireResponse' => [
        'scope' => 'user/QuestionnaireResponse.write',
        'acl' => 'patients/med',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient', 'questionnaire'],
        'body' => [
            'resourceType' => 'QuestionnaireResponse',
            'status' => 'completed',
            'questionnaire' => 'Questionnaire/{{questionnaire}}',
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'authored' => '2026-01-15T09:30:00+00:00',
            'item' => [
                ['linkId' => '1', 'text' => 'What is your name?', 'answer' => [['valueString' => 'explorer-write {{unique}}']]],
            ],
        ],
    ],

    'RelatedPerson' => [
        'scope' => 'user/RelatedPerson.write',
        'acl' => 'patients/demo',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'RelatedPerson',
            'active' => true,
            'patient' => ['reference' => 'Patient/{{patient}}'],
            'relationship' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-RoleCode', 'code' => 'MTH', 'display' => 'Mother']]]],
            // PersonService rejects a duplicate keyed on first_name + last_name + birth_date, and
            // the Explorer does not clean up after itself, so the family name carries the per-run
            // token. given[1] maps to middle_name, which is not part of that key.
            'name' => [['use' => 'official', 'family' => 'ExplorerRelated-{{unique}}', 'given' => ['ExplorerRelatedFirst']]],
            'telecom' => [['system' => 'phone', 'value' => '(555) 123-4567', 'use' => 'home']],
            'address' => [['use' => 'home', 'line' => ['123 Main St'], 'city' => 'Testville', 'state' => 'CA', 'postalCode' => '90210']],
            'gender' => 'female',
            'birthDate' => '1970-05-15',
        ],
    ],

    'ServiceRequest' => [
        'scope' => 'user/ServiceRequest.write',
        'acl' => 'patients/med',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'ServiceRequest',
            'status' => 'active',
            'intent' => 'order',
            'category' => [['coding' => [['system' => 'http://snomed.info/sct', 'code' => '108252007', 'display' => 'Laboratory procedure']]]],
            'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '24356-8', 'display' => 'Urinalysis complete panel']], 'text' => 'Urinalysis complete panel'],
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'authoredOn' => '2024-02-01T09:30:00+00:00',
            'priority' => 'routine',
            'patientInstruction' => 'Fast for 8 hours before collection',
            'note' => [['text' => 'explorer-write {{unique}}']],
        ],
    ],

    // The referral loop (openemr #14710): the referral is a ServiceRequest, and its Task says
    // where it stands. focus -> the request, owner -> who receives it, requester -> who sent
    // it; the reply comes back in Task.output.
    //
    // {{serviceRequest}} is one of the patient's orders, never a referral. Since #14715 and
    // #14962 a Task with code fulfill, no partOf and a ServiceRequest focus is taken for a
    // referral's own Task: on a real referral this one would become the referral's status, send
    // Refer By an inbox message on accepted and completed, and be cancelled by the referral's
    // next save as a Task for another owner. The Referral loop panel works the Task OpenEMR
    // made for the referral instead.
    //
    // A Task that is completed, rejected, cancelled or failed can only become
    // entered-in-error; PUT takes If-Match (W/"versionId") and answers 412 on a stale one.
    'Task' => [
        'scope' => 'user/Task.write',
        'acl' => 'patients/med',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient', 'practitioner', 'orgProvider', 'serviceRequest'],
        'body' => [
            'resourceType' => 'Task',
            'status' => 'requested',
            'intent' => 'order',
            'priority' => 'routine',
            'code' => ['coding' => [['system' => 'http://hl7.org/fhir/CodeSystem/task-code', 'code' => 'fulfill', 'display' => 'Fulfill the focal request']]],
            'description' => 'explorer-write {{unique}} referral task',
            'focus' => ['reference' => 'ServiceRequest/{{serviceRequest}}'],
            'for' => ['reference' => 'Patient/{{patient}}'],
            'authoredOn' => '2026-10-18T09:30:00-05:00',
            'requester' => ['reference' => 'Practitioner/{{practitioner}}'],
            'owner' => ['reference' => 'Organization/{{orgProvider}}'],
            'input' => [['type' => ['text' => 'Reason for referral'], 'valueString' => 'explorer-write {{unique}}']],
        ],
        // The loop, one PUT at a time on the Task the round trip made. Each step's `patch` is
        // laid over that Task's body; `ifMatch` sends the header as given; `search` is a GET on
        // the Task type that must find the Task; `needs` skips the step (or uses `fallback`)
        // when a context key is unresolved.
        'checks' => [
            'lifecycle' => [
                ['label' => 'search by focus finds the Task', 'search' => 'focus=ServiceRequest/{{serviceRequest}}'],
                ['label' => 'search by owner finds the Task', 'search' => 'owner=Organization/{{orgProvider}}&status=requested'],
                ['label' => 'recipient accepts', 'patch' => ['status' => 'accepted', 'businessStatus' => ['text' => 'Accepted by recipient']], 'expect' => 200],
                ['label' => 'recipient starts work', 'patch' => ['status' => 'in-progress', 'businessStatus' => ['text' => 'Appointment scheduled']], 'expect' => 200],
                ['label' => 'stale If-Match is refused', 'patch' => ['status' => 'in-progress'], 'ifMatch' => 'W/"1"', 'expect' => 412],
                [
                    'label' => 'reply completes the Task (consult note in Task.output)',
                    'needs' => ['documentReference'],
                    'patch' => [
                        'status' => 'completed',
                        'businessStatus' => ['text' => 'Consult note received'],
                        'output' => [[
                            'type' => ['coding' => [['system' => 'http://loinc.org', 'code' => '11488-4', 'display' => 'Consult note']]],
                            'valueReference' => ['reference' => 'DocumentReference/{{documentReference}}'],
                        ]],
                    ],
                    // No DocumentReference for the patient: complete with the reply as text.
                    'fallback' => [
                        'status' => 'completed',
                        'businessStatus' => ['text' => 'Consult note received'],
                        'output' => [[
                            'type' => ['coding' => [['system' => 'http://loinc.org', 'code' => '11488-4', 'display' => 'Consult note']]],
                            'valueString' => 'explorer-write {{unique}} reply',
                        ]],
                    ],
                    'expect' => 200,
                ],
                ['label' => 'search status=completed finds the Task', 'search' => 'focus=ServiceRequest/{{serviceRequest}}&status=completed'],
                ['label' => 'a completed Task cannot reopen', 'patch' => ['status' => 'in-progress'], 'expect' => 400, 'contains' => "can't become"],
            ],
        ],
        'variants' => [
            'patient-questionnaire' => [
                'label' => 'questionnaire assigned to the patient (SDC complete-questionnaire)',
                'body' => [
                    'resourceType' => 'Task',
                    'status' => 'requested',
                    'intent' => 'order',
                    'code' => ['coding' => [['system' => 'http://hl7.org/fhir/uv/sdc/CodeSystem/temp', 'code' => 'complete-questionnaire']]],
                    'description' => 'explorer-write {{unique}} questionnaire task',
                    'focus' => ['reference' => 'Questionnaire/{{questionnaire}}'],
                    'for' => ['reference' => 'Patient/{{patient}}'],
                    'owner' => ['reference' => 'Patient/{{patient}}'],
                    'requester' => ['reference' => 'Practitioner/{{practitioner}}'],
                ],
            ],
            'focus-patient' => [
                'label' => 'focus on a Patient is refused',
                'expect' => 400,
                'contains' => 'Task.focus',
                'body' => [
                    'resourceType' => 'Task',
                    'status' => 'requested',
                    'intent' => 'order',
                    'focus' => ['reference' => 'Patient/{{patient}}'],
                    'for' => ['reference' => 'Patient/{{patient}}'],
                ],
            ],
            'bad-status' => [
                'label' => 'status outside R4 task-status is refused',
                'expect' => 400,
                'contains' => 'Task.status',
                'body' => [
                    'resourceType' => 'Task',
                    'status' => 'done',
                    'intent' => 'order',
                    'for' => ['reference' => 'Patient/{{patient}}'],
                ],
            ],
            'output-practitioner' => [
                'label' => 'output naming a Practitioner is refused',
                'expect' => 400,
                'contains' => 'Task.output',
                'body' => [
                    'resourceType' => 'Task',
                    'status' => 'completed',
                    'intent' => 'order',
                    'for' => ['reference' => 'Patient/{{patient}}'],
                    'output' => [['type' => ['text' => 'Reply'], 'valueReference' => ['reference' => 'Practitioner/{{practitioner}}']]],
                ],
            ],
        ],
    ],

    // ------------------------------------------------------------------ original write routes
    // Patient, Practitioner and Organization predate the write PR. Bodies are the same ones the
    // seeds use (fhir_write_seeds.php), which are known to pass each validator on a bare install.

    'Organization' => [
        'scope' => 'user/Organization.write',
        'acl' => 'admin/super',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => [
            'resourceType' => 'Organization',
            'active' => true,
            'name' => 'Explorer Write Clinic {{unique}}',
            'identifier' => [['system' => 'http://hl7.org/fhir/sid/us-npi', 'value' => '{{uniqueDigits}}']],
            'telecom' => [['system' => 'phone', 'value' => '(555) 300-4002', 'use' => 'work']],
            'address' => [['line' => ['3 Explorer Way'], 'city' => 'Testville', 'state' => 'CA', 'postalCode' => '90001']],
        ],
    ],

    'Patient' => [
        'scope' => 'user/Patient.write',
        'acl' => 'patients/demo',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => [
            'resourceType' => 'Patient',
            'active' => true,
            'identifier' => [[
                'use' => 'official',
                'type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'PT']]],
                'system' => 'http://terminology.hl7.org/ValueSet/v2-0203',
                'value' => 'explorer-write-{{unique}}',
            ]],
            'name' => [['use' => 'official', 'family' => 'ExplorerWrite{{unique}}', 'given' => ['Workbench']]],
            'gender' => 'female',
            'birthDate' => '1985-04-12',
            'address' => [['line' => ['1 Explorer Way'], 'city' => 'Testville', 'state' => 'CA', 'postalCode' => '90001']],
            'telecom' => [['system' => 'phone', 'value' => '(555) 300-4000', 'use' => 'home']],
        ],
    ],

    'Practitioner' => [
        'scope' => 'user/Practitioner.write',
        'acl' => 'admin/users',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => [
            'resourceType' => 'Practitioner',
            'active' => true,
            // PractitionerValidator requires an NPI; {{uniqueDigits}} keeps repeat runs from colliding.
            'identifier' => [['system' => 'http://hl7.org/fhir/sid/us-npi', 'value' => '{{uniqueDigits}}']],
            'name' => [['use' => 'official', 'family' => 'ExplorerWriteProvider{{unique}}', 'given' => ['Workbench'], 'prefix' => ['Dr.']]],
            'telecom' => [['system' => 'phone', 'value' => '(555) 300-4001', 'use' => 'work']],
            'address' => [['line' => ['2 Explorer Way'], 'city' => 'Testville', 'state' => 'CA', 'postalCode' => '90001']],
        ],
    ],

    // ------------------------------------------------------------------ Observation (PR #14217, fhir-observation-write)
    // Vital signs only. The default body is tests/Tests/Fixtures/FHIR/observation.json[1], the
    // heart-rate fixture ObservationFhirWriteApiTest posts. What the server enforces, and what the
    // variants and checks below exercise:
    //   - category is required and must include vital-signs: it routes the write (code alone is
    //     ambiguous -- 2708-6 is a vital sign and a lab result)
    //   - encounter is required: vitals are an encounter form; the encounter must be active and
    //     belong to the subject
    //   - effectiveDateTime is required and is half the row's identity: vitals posted for one
    //     encounter at one time share a form_vitals row, a POST for a reading the row already
    //     holds is refused (use PUT), and a PUT may not change the date
    //   - values must be > 0 (0 means "not recorded") and in a unit that converts to storage
    //   - BMI, the vitals panel, average BP and temperature location are read-only
    // {{effective}} is a fresh timestamp per body, so repeat runs against the same encounter do
    // not collide with the reading the previous run left behind.
    'Observation' => [
        'scope' => 'user/Observation.write',
        'acl' => 'encounters/notes',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => ['patient', 'encounter'],
        'body' => [
                'resourceType' => 'Observation',
                'status' => 'final',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8867-4', 'display' => 'Heart rate']], 'text' => 'Heart rate'],
                'subject' => ['reference' => 'Patient/{{patient}}'],
                'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                'valueQuantity' => ['value' => 72, 'unit' => '/min', 'system' => 'http://unitsofmeasure.org', 'code' => '/min'],
                'effectiveDateTime' => '{{effective}}',
            ],
        // After the default round trip: the same POST again must be refused, and a PUT that
        // moves effectiveDateTime must be refused.
        'checks' => ['repostConflict' => 'already exists', 'putDateChange' => 'cannot be changed'],
        'variants' => [
            'weight-kg' => [
                'label' => 'Body weight in kg (converted to lb)',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '29463-7', 'display' => 'Body weight']], 'text' => 'Body weight'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 70, 'unit' => 'kg', 'system' => 'http://unitsofmeasure.org', 'code' => 'kg'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'temperature-cel' => [
                'label' => 'Body temperature in Cel (converted to degF)',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8310-5', 'display' => 'Body temperature']], 'text' => 'Body temperature'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 37, 'unit' => 'Cel', 'system' => 'http://unitsofmeasure.org', 'code' => 'Cel'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'blood-pressure' => [
                'label' => 'Blood pressure panel (components)',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '85354-9', 'display' => 'Blood pressure panel with all children optional']], 'text' => 'Blood pressure panel with all children optional'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'component' => [
                        ['code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8480-6', 'display' => 'Systolic blood pressure']]], 'valueQuantity' => ['value' => 120, 'unit' => 'mmHg', 'system' => 'http://unitsofmeasure.org', 'code' => 'mm[Hg]']],
                        ['code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8462-4', 'display' => 'Diastolic blood pressure']]], 'valueQuantity' => ['value' => 80, 'unit' => 'mmHg', 'system' => 'http://unitsofmeasure.org', 'code' => 'mm[Hg]']],
                    ],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'pulse-oximetry' => [
                'label' => 'Pulse oximetry',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '59408-5', 'display' => 'Oxygen saturation in Arterial blood by Pulse oximetry']], 'text' => 'Oxygen saturation in Arterial blood by Pulse oximetry'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 98, 'unit' => '%', 'system' => 'http://unitsofmeasure.org', 'code' => '%'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'snomed-then-loinc' => [
                'label' => 'LOINC coding listed second',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '86290005', 'display' => 'Respiratory rate'], ['system' => 'http://loinc.org', 'code' => '9279-1', 'display' => 'Respiratory rate']], 'text' => 'Respiratory rate'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 16, 'unit' => '/min', 'system' => 'http://unitsofmeasure.org', 'code' => '/min'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'no-encounter' => [
                'label' => 'No encounter',
                'expect' => 400,
                'contains' => 'encounter',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8867-4', 'display' => 'Heart rate']], 'text' => 'Heart rate'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'valueQuantity' => ['value' => 72, 'unit' => '/min', 'system' => 'http://unitsofmeasure.org', 'code' => '/min'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'no-category' => [
                'label' => 'No category',
                'expect' => 400,
                'contains' => 'category',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8867-4', 'display' => 'Heart rate']], 'text' => 'Heart rate'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 72, 'unit' => '/min', 'system' => 'http://unitsofmeasure.org', 'code' => '/min'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'social-history' => [
                'label' => 'Smoking status (social-history, read-only view)',
                'expect' => 400,
                'contains' => 'cannot be written',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'social-history']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '72166-2', 'display' => 'Tobacco smoking status']], 'text' => 'Tobacco smoking status'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'bmi' => [
                'label' => 'BMI (derived, read-only)',
                'expect' => 400,
                'contains' => 'cannot be written',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '39156-5', 'display' => 'Body mass index (BMI) [Ratio]']], 'text' => 'Body mass index (BMI) [Ratio]'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 24, 'unit' => 'kg/m2', 'system' => 'http://unitsofmeasure.org', 'code' => 'kg/m2'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'zero-value' => [
                'label' => 'Zero value',
                'expect' => 400,
                'contains' => 'greater than zero',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '8867-4', 'display' => 'Heart rate']], 'text' => 'Heart rate'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 0, 'unit' => '/min', 'system' => 'http://unitsofmeasure.org', 'code' => '/min'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
            'bad-unit' => [
                'label' => 'Unit that cannot be converted',
                'expect' => 400,
                'contains' => 'cannot be converted',
                'body' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                    'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '29463-7', 'display' => 'Body weight']], 'text' => 'Body weight'],
                    'subject' => ['reference' => 'Patient/{{patient}}'],
                    'encounter' => ['reference' => 'Encounter/{{encounter}}'],
                    'valueQuantity' => ['value' => 11, 'unit' => '[stone_av]', 'system' => 'http://unitsofmeasure.org', 'code' => '[stone_av]'],
                    'effectiveDateTime' => '{{effective}}',
                ],
            ],
        ],
    ],

    // ------------------------------------------------------------------ routes that answer 405
    // RestControllerHelper::fhirWriteNotImplemented(). The Explorer's clients request no write
    // scope for these, so a token normally meets 401/403 at the scope check before the 405.

    'DiagnosticReport' => [
        'status' => 'not-implemented',
        'reason' => 'Read path federates Laboratory and ClinicalNotes; writes need a per-category target sub-service.',
        'scope' => 'user/DiagnosticReport.write',
        'acl' => 'n/a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => ['resourceType' => 'DiagnosticReport'],
    ],

    'DocumentReference' => [
        'status' => 'not-implemented',
        'reason' => 'Read path federates clinical notes, patient documents and advance directives; $docref remains.',
        'scope' => 'user/DocumentReference.write',
        'acl' => 'n/a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => ['resourceType' => 'DocumentReference'],
    ],

    'Group' => [
        'status' => 'not-implemented',
        'reason' => 'Computed aggregation (patients-by-provider); no persistent storage.',
        'scope' => 'user/Group.write',
        'acl' => 'n/a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => ['resourceType' => 'Group'],
    ],

    'Location' => [
        'status' => 'not-implemented',
        'reason' => 'Virtual projection over patient_data/users/facility; write the Patient, Practitioner or Organization instead.',
        'scope' => 'user/Location.write',
        'acl' => 'n/a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => ['resourceType' => 'Location'],
    ],

    'MedicationDispense' => [
        'status' => 'not-implemented',
        'reason' => 'Dispensary persistence varies by deployment; needs a per-deployment design.',
        'scope' => 'user/MedicationDispense.write',
        'acl' => 'n/a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => ['resourceType' => 'MedicationDispense'],
    ],

    'Procedure' => [
        'status' => 'not-implemented',
        'reason' => 'Overlaps ServiceRequest writes (use intent=order); standalone Procedure writes to follow.',
        'scope' => 'user/Procedure.write',
        'acl' => 'n/a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => ['resourceType' => 'Procedure'],
    ],

    'Provenance' => [
        'status' => 'not-implemented',
        'reason' => 'Synthesized from other resources at read time; no provenance table.',
        'scope' => 'user/Provenance.write',
        'acl' => 'n/a',
        'verbs' => ['POST', 'PUT'],
        'readById' => true,
        'needs' => [],
        'body' => ['resourceType' => 'Provenance'],
    ],
];
