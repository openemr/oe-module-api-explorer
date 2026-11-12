<?php

/**
 * patient_write_seeds.php — the "Home vital signs" Questionnaire, seeded with a staff token so a
 * patient app has something to record vitals with.
 *
 * OpenEMR accepts only QuestionnaireResponse from patient apps today (PatientWritableType::
 * servedByApi(); the Vital Signs setting says "Not accepted yet in this version"), so a
 * patient's home readings travel as answers to this Questionnaire, held for staff review. Each
 * item carries the LOINC code and UCUM unit of the vital sign it records, so the answers map
 * one to one onto vital-sign Observations once core accepts those.
 *
 * The same definition ships with the OpenEMR SMART Questionnaires app
 * (docs/home-vital-signs.questionnaire.json), which finds it by title and checks `url`. Keep the
 * two in step; change `version` when the items change.
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$unit = static fn(string $code, string $display): array => [
    'url' => 'http://hl7.org/fhir/StructureDefinition/questionnaire-unit',
    'valueCoding' => ['system' => 'http://unitsofmeasure.org', 'code' => $code, 'display' => $display],
];
$range = static fn(string $type, int|float $min, int|float $max): array => [
    ['url' => 'http://hl7.org/fhir/StructureDefinition/minValue', 'value' . ucfirst($type) => $min],
    ['url' => 'http://hl7.org/fhir/StructureDefinition/maxValue', 'value' . ucfirst($type) => $max],
];
$loinc = static fn(string $code, string $display): array => [['system' => 'http://loinc.org', 'code' => $code, 'display' => $display]];

return [
    'homeVitals' => [
        'resourceType' => 'Questionnaire',
        'url' => 'http://open-emr.org/fhir/Questionnaire/home-vital-signs',
        // OpenEMR ignores a client url on create and publishes its own; the identifier keeps the
        // canonical so apps can still recognize the questionnaire.
        'identifier' => [['system' => 'urn:ietf:rfc:3986', 'value' => 'http://open-emr.org/fhir/Questionnaire/home-vital-signs']],
        'version' => '1',
        'name' => 'HomeVitalSigns',
        'title' => 'Home vital signs',
        'status' => 'active',
        'subjectType' => ['Patient'],
        'description' => 'Readings a patient takes at home. Fill in the ones you took; leave the rest blank.',
        'item' => [
            ['linkId' => 'taken', 'text' => 'When did you take these readings?', 'type' => 'dateTime', 'required' => true],
            [
                'linkId' => 'bp',
                'text' => 'Blood pressure',
                'type' => 'group',
                'code' => $loinc('85354-9', 'Blood pressure panel with all children optional'),
                'item' => [
                    ['linkId' => 'bp-systolic', 'text' => 'Systolic (top number)', 'type' => 'integer',
                        'code' => $loinc('8480-6', 'Systolic blood pressure'),
                        'extension' => array_merge([$unit('mm[Hg]', 'mmHg')], $range('integer', 50, 300))],
                    ['linkId' => 'bp-diastolic', 'text' => 'Diastolic (bottom number)', 'type' => 'integer',
                        'code' => $loinc('8462-4', 'Diastolic blood pressure'),
                        'extension' => array_merge([$unit('mm[Hg]', 'mmHg')], $range('integer', 20, 200))],
                ],
            ],
            ['linkId' => 'heart-rate', 'text' => 'Heart rate (pulse)', 'type' => 'integer',
                'code' => $loinc('8867-4', 'Heart rate'),
                'extension' => array_merge([$unit('/min', 'beats/minute')], $range('integer', 20, 300))],
            ['linkId' => 'weight', 'text' => 'Weight', 'type' => 'decimal',
                'code' => $loinc('29463-7', 'Body weight'),
                'extension' => array_merge([$unit('[lb_av]', 'lb')], $range('decimal', 1, 1000))],
            ['linkId' => 'temperature', 'text' => 'Temperature', 'type' => 'decimal',
                'code' => $loinc('8310-5', 'Body temperature'),
                'extension' => array_merge([$unit('[degF]', '°F')], $range('decimal', 90, 110))],
            ['linkId' => 'spo2', 'text' => 'Oxygen level (pulse oximeter)', 'type' => 'integer',
                'code' => $loinc('59408-5', 'Oxygen saturation in Arterial blood by Pulse oximetry'),
                'extension' => array_merge([$unit('%', '%')], $range('integer', 50, 100))],
            ['linkId' => 'resp-rate', 'text' => 'Breaths per minute', 'type' => 'integer',
                'code' => $loinc('9279-1', 'Respiratory rate'),
                'extension' => array_merge([$unit('/min', 'breaths/minute')], $range('integer', 4, 80))],
            ['linkId' => 'notes', 'text' => 'Anything else your care team should know?', 'type' => 'text'],
        ],
    ],
];
