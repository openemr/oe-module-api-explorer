<?php

/**
 * fhir_write_seeds.php — prerequisite fixtures for the FHIR write workbench.
 *
 * The write matrix in fhir_write_templates.php mirrors tests/Tests/Fixtures/FHIR/*.json, and the
 * PHPUnit write tests install what those bodies reference through FixtureManager rather than
 * assuming an installation already has the data. This file is the Explorer's equivalent: a
 * minimal FHIR body per context key, POSTed through the same API under test so a resource can be
 * exercised on an installation carrying no demo data.
 *
 * Ordered by dependency -- patient before encounter -- and the driver resolves each key it just
 * created before building the next body, so {{patient}} is filled by the patient created a moment
 * earlier. `{{unique}}` and `{{uniqueDigits}}` keep repeat runs from colliding on the natural keys
 * OpenEMR enforces (a practitioner's NPI, a person's name+DOB).
 *
 * Every seed goes through a route the token already needs a write scope for, so nothing here
 * widens what the Explorer's client asks for.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

return [
    'patient' => [
        'resource' => 'Patient',
        'scope' => 'user/Patient.write',
        'label' => 'a patient',
        'body' => [
            'resourceType' => 'Patient',
            'active' => true,
            'identifier' => [[
                'use' => 'official',
                'type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'PT']]],
                'system' => 'http://terminology.hl7.org/ValueSet/v2-0203',
                'value' => 'explorer-seed-{{unique}}',
            ]],
            'name' => [[
                'use' => 'official',
                'family' => 'ExplorerSeed{{unique}}',
                'given' => ['Workbench'],
            ]],
            'gender' => 'female',
            'birthDate' => '1985-04-12',
            'address' => [[
                'line' => ['1 Workbench Way'],
                'city' => 'Testville',
                'state' => 'CA',
                'postalCode' => '90001',
            ]],
            'telecom' => [
                ['system' => 'phone', 'value' => '(555) 200-3000', 'use' => 'home'],
                ['system' => 'email', 'value' => 'explorer-seed-{{unique}}@example.invalid', 'use' => 'home'],
            ],
        ],
    ],

    'practitioner' => [
        'resource' => 'Practitioner',
        'scope' => 'user/Practitioner.write',
        'label' => 'a practitioner',
        // PractitionerValidator gates writes on the NPI, and PractitionerService::search() only
        // returns `users` rows that carry one -- which is why a stock install with no NPI on any
        // user resolves no practitioner at all.
        'body' => [
            'resourceType' => 'Practitioner',
            'active' => true,
            'identifier' => [[
                'system' => 'http://hl7.org/fhir/sid/us-npi',
                'value' => '{{uniqueDigits}}',
            ]],
            'name' => [[
                'use' => 'official',
                'family' => 'ExplorerSeedProvider{{unique}}',
                'given' => ['Workbench'],
                'prefix' => ['Dr.'],
            ]],
            'telecom' => [
                ['system' => 'phone', 'value' => '(555) 200-3001', 'use' => 'work'],
                ['system' => 'email', 'value' => 'explorer-provider-{{unique}}@example.invalid', 'use' => 'work'],
            ],
            'address' => [[
                'line' => ['2 Workbench Way'],
                'city' => 'Testville',
                'state' => 'CA',
                'postalCode' => '90001',
            ]],
        ],
    ],

    'facility' => [
        'resource' => 'Organization',
        'scope' => 'user/Organization.write',
        'label' => 'a facility Organization',
        // FhirOrganizationService::parseFhirResource() routes to the insurance service only when
        // the body carries an Ins/Pay type; anything else is inserted as a facility. A facility is
        // what an Appointment needs, and it is the one kind of `prov` Organization that is also
        // exposed as a Location.
        'body' => [
            'resourceType' => 'Organization',
            'active' => true,
            'name' => 'Explorer Seed Clinic {{unique}}',
            'identifier' => [[
                'system' => 'http://hl7.org/fhir/sid/us-npi',
                'value' => '{{uniqueDigits}}',
            ]],
            'telecom' => [['system' => 'phone', 'value' => '(555) 200-3002', 'use' => 'work']],
            'address' => [[
                'line' => ['3 Workbench Way'],
                'city' => 'Testville',
                'state' => 'CA',
                'postalCode' => '90001',
            ]],
        ],
    ],

    'orgInsurer' => [
        'resource' => 'Organization',
        'scope' => 'user/Organization.write',
        'label' => 'an insurance Organization',
        'body' => [
            'resourceType' => 'Organization',
            'active' => true,
            'name' => 'Explorer Seed Insurance {{unique}}',
            'type' => [['coding' => [[
                'system' => 'http://terminology.hl7.org/CodeSystem/organization-type',
                'code' => 'Ins',
                'display' => 'Insurance Company',
            ]]]],
            'telecom' => [['system' => 'phone', 'value' => '(555) 200-3003', 'use' => 'work']],
            'address' => [[
                'line' => ['4 Workbench Way'],
                'city' => 'Testville',
                'state' => 'CA',
                'postalCode' => '90001',
            ]],
        ],
    ],

    'encounter' => [
        'resource' => 'Encounter',
        'scope' => 'user/Encounter.write',
        'label' => 'an encounter',
        'needs' => ['patient'],
        'body' => [
            'resourceType' => 'Encounter',
            'status' => 'finished',
            'class' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => 'AMB',
                'display' => 'ambulatory',
            ],
            'type' => [['coding' => [[
                'system' => 'http://snomed.info/sct',
                'code' => '185349003',
                'display' => 'Encounter for check up (procedure)',
            ]]]],
            'subject' => ['reference' => 'Patient/{{patient}}'],
            'period' => ['start' => '2024-01-15T10:00:00+00:00'],
            'reasonCode' => [['text' => 'explorer-seed {{unique}}']],
        ],
    ],

    'questionnaire' => [
        'resource' => 'Questionnaire',
        'scope' => 'user/Questionnaire.write',
        'label' => 'a questionnaire',
        'body' => [
            'resourceType' => 'Questionnaire',
            'status' => 'active',
            'title' => 'Explorer Seed Questionnaire {{unique}}',
            'name' => 'ExplorerSeedQuestionnaire{{unique}}',
            'item' => [[
                'linkId' => '1',
                'text' => 'How are you feeling today?',
                'type' => 'string',
            ]],
        ],
    ],

    // The write workbench's Task focus: an order, never a referral. A Task on a real referral is
    // taken for the referral's own Task (see the Task entry in fhir_write_templates.php); the
    // Referral loop panel works those.
    'serviceRequest' => [
        'resource' => 'ServiceRequest',
        'scope' => 'user/ServiceRequest.write',
        'label' => 'a service request for the patient',
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
            'note' => [['text' => 'explorer-seed {{unique}}']],
        ],
    ],
];
