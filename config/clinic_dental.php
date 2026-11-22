<?php

/*
|--------------------------------------------------------------------------
| Dental chart vocabulary (registry extension `odontogram`)
|--------------------------------------------------------------------------
|
| Teeth are FDI two-digit numbers. `surface` marks a condition that sits on
| particular surfaces (caries on M and O) rather than on the whole tooth.
| `category` groups conditions by clinical meaning; each category has one
| colour everywhere it appears (chart, tooth history, plans, procedures).
| `tone` is kept for the consultation workspace's chart.
|
*/

return [

    'conditions' => [
        'sound' => ['label' => 'Healthy', 'tone' => 'neutral', 'surface' => false, 'category' => null],
        'caries' => ['label' => 'Caries', 'tone' => 'danger', 'surface' => true, 'category' => 'pathology'],
        'recurrent_caries' => ['label' => 'Recurrent caries', 'tone' => 'danger', 'surface' => true, 'category' => 'pathology'],
        'abrasion' => ['label' => 'Abrasion', 'tone' => 'warning', 'surface' => true, 'category' => 'observation'],
        'erosion' => ['label' => 'Erosion', 'tone' => 'warning', 'surface' => true, 'category' => 'observation'],
        'filled' => ['label' => 'Filled', 'tone' => 'brand', 'surface' => true, 'category' => 'restoration'],
        'inlay' => ['label' => 'Inlay / onlay', 'tone' => 'brand', 'surface' => true, 'category' => 'restoration'],
        'sealant' => ['label' => 'Sealant', 'tone' => 'brand', 'surface' => true, 'category' => 'restoration'],
        'veneer' => ['label' => 'Veneer', 'tone' => 'amber', 'surface' => false, 'category' => 'prosthetic'],
        'crown' => ['label' => 'Crown', 'tone' => 'amber', 'surface' => false, 'category' => 'prosthetic'],
        'bridge' => ['label' => 'Bridge / pontic', 'tone' => 'amber', 'surface' => false, 'category' => 'prosthetic'],
        'denture' => ['label' => 'Denture tooth', 'tone' => 'amber', 'surface' => false, 'category' => 'prosthetic'],
        'implant' => ['label' => 'Implant', 'tone' => 'teal', 'surface' => false, 'category' => 'implant'],
        'root_canal' => ['label' => 'Root canal treated', 'tone' => 'violet', 'surface' => false, 'category' => 'endodontic'],
        'periapical' => ['label' => 'Periapical lesion', 'tone' => 'violet', 'surface' => false, 'category' => 'endodontic'],
        'missing' => ['label' => 'Missing', 'tone' => 'muted', 'surface' => false, 'category' => 'missing'],
        'extracted' => ['label' => 'Extracted', 'tone' => 'muted', 'surface' => false, 'category' => 'missing'],
        'fractured' => ['label' => 'Fractured', 'tone' => 'danger', 'surface' => false, 'category' => 'urgent'],
        'abscess' => ['label' => 'Abscess', 'tone' => 'danger', 'surface' => false, 'category' => 'urgent'],
        'mobile' => ['label' => 'Mobile', 'tone' => 'warning', 'surface' => false, 'category' => 'urgent'],
        'impacted' => ['label' => 'Impacted', 'tone' => 'warning', 'surface' => false, 'category' => 'observation'],
        'wear' => ['label' => 'Attrition', 'tone' => 'warning', 'surface' => true, 'category' => 'observation'],
        'discoloration' => ['label' => 'Discoloration', 'tone' => 'warning', 'surface' => false, 'category' => 'observation'],
        'watch' => ['label' => 'Watch', 'tone' => 'warning', 'surface' => false, 'category' => 'observation'],
        'bracket' => ['label' => 'Orthodontic bracket', 'tone' => 'brand', 'surface' => false, 'category' => 'orthodontic'],
        'band' => ['label' => 'Orthodontic band', 'tone' => 'brand', 'surface' => false, 'category' => 'orthodontic'],
    ],

    /*
     * One colour per clinical meaning. The stroke carries the meaning
     * against white (all at least 4.5:1); the fill is a light tint. Planned
     * and completed treatment are overlays drawn on top of a condition.
     */
    'categories' => [
        'pathology' => ['label' => 'Caries / decay', 'fill' => '#fecdd3', 'stroke' => '#be123c'],
        'urgent' => ['label' => 'Urgent finding', 'fill' => '#fca5a5', 'stroke' => '#b91c1c'],
        'restoration' => ['label' => 'Restoration', 'fill' => '#bae6fd', 'stroke' => '#0369a1'],
        'prosthetic' => ['label' => 'Crown / prosthetic', 'fill' => '#fde68a', 'stroke' => '#b45309'],
        'implant' => ['label' => 'Implant', 'fill' => '#99f6e4', 'stroke' => '#0f766e'],
        'endodontic' => ['label' => 'Endodontic', 'fill' => '#ddd6fe', 'stroke' => '#6d28d9'],
        'missing' => ['label' => 'Missing / extracted', 'fill' => '#e2e8f0', 'stroke' => '#475569'],
        'observation' => ['label' => 'Observation', 'fill' => '#fef3c7', 'stroke' => '#a16207'],
        'orthodontic' => ['label' => 'Orthodontic', 'fill' => '#c7d2fe', 'stroke' => '#4338ca'],
    ],

    /*
     * The tooth-state legend used everywhere (3D view, Ongoing Treatment,
     * plans, tooth detail, reports). A tooth's state is decided on the server
     * (`Dental\DentalContextFilter`) from its findings and treatment, in this
     * order of precedence. Soft fills on white, strokes at least 4.5:1.
     */
    'states' => [
        'urgent' => ['label' => 'Urgent', 'fill' => '#fecaca', 'stroke' => '#b91c1c'],
        'in_progress' => ['label' => 'In progress', 'fill' => '#bfdbfe', 'stroke' => '#1d4ed8'],
        'planned' => ['label' => 'Planned treatment', 'fill' => '#fde68a', 'stroke' => '#a16207'],
        'condition' => ['label' => 'Existing condition', 'fill' => '#fed7aa', 'stroke' => '#c2410c'],
        'completed' => ['label' => 'Completed', 'fill' => '#bbf7d0', 'stroke' => '#15803d'],
        'missing' => ['label' => 'Missing / extracted', 'fill' => '#e2e8f0', 'stroke' => '#64748b'],
        'implant' => ['label' => 'Implant', 'fill' => '#99f6e4', 'stroke' => '#0f766e'],
        'restored' => ['label' => 'Existing restoration', 'fill' => '#e0e7ff', 'stroke' => '#4338ca'],
        'healthy' => ['label' => 'Healthy', 'fill' => '#ffffff', 'stroke' => '#94a3b8'],
    ],

    'overlays' => [
        'planned' => ['label' => 'Planned treatment', 'stroke' => '#1d4ed8'],
        'completed' => ['label' => 'Treatment done', 'stroke' => '#15803d'],
        'new' => ['label' => 'Charted in the open consultation', 'stroke' => '#be185d'],
    ],

    'surfaces' => [
        'M' => 'Mesial',
        'O' => 'Occlusal / incisal',
        'D' => 'Distal',
        'B' => 'Buccal / labial',
        'L' => 'Lingual / palatal',
    ],

    /** FDI quadrants, each listed from the midline out. */
    'dentitions' => [
        'permanent' => ['1' => 8, '2' => 8, '3' => 8, '4' => 8],
        'primary' => ['5' => 5, '6' => 5, '7' => 5, '8' => 5],
    ],

    /** Periodontal charting vocabulary (2017 classification). */
    'periodontal' => [
        'sites' => ['MB', 'B', 'DB', 'ML', 'L', 'DL'],
        'stages' => ['I', 'II', 'III', 'IV'],
        'grades' => ['A', 'B', 'C'],
        'extents' => ['localised' => 'Localised', 'generalised' => 'Generalised', 'molar_incisor' => 'Molar–incisor pattern'],
    ],

    /*
    | What a doctor usually does about a finding: words matched against the clinic's own service names, so the visit screen
    | offers them as one-tap chips (a clinic that names a service differently simply gets fewer). `diagnosis` is the
    | wording a finding adds to the visit's diagnosis.
    */
    'suggestions' => [
        'caries' => ['diagnosis' => 'Dental caries', 'services' => ['filling', 'restoration', 'root canal', 'rct']],
        'recurrent_caries' => ['diagnosis' => 'Recurrent caries', 'services' => ['filling', 'root canal', 'crown']],
        'periapical' => ['diagnosis' => 'Periapical lesion', 'services' => ['root canal', 'rct', 'extraction']],
        'abscess' => ['diagnosis' => 'Dental abscess', 'services' => ['drainage', 'root canal', 'extraction']],
        'fractured' => ['diagnosis' => 'Fractured tooth', 'services' => ['crown', 'filling', 'extraction']],
        'mobile' => ['diagnosis' => 'Mobile tooth', 'services' => ['splint', 'extraction']],
        'missing' => ['diagnosis' => 'Missing tooth', 'services' => ['implant', 'bridge', 'denture']],
        'impacted' => ['diagnosis' => 'Impacted tooth', 'services' => ['surgical', 'extraction']],
        'discoloration' => ['diagnosis' => 'Discoloured tooth', 'services' => ['whitening', 'veneer', 'crown']],
        'wear' => ['diagnosis' => 'Tooth wear', 'services' => ['filling', 'crown']],
        'abrasion' => ['diagnosis' => 'Abrasion', 'services' => ['filling']],
        'erosion' => ['diagnosis' => 'Erosion', 'services' => ['filling']],
    ],

    /*
    | The visits of a treatment that takes more than one, matched against the service name (first match wins). Each stage is
    | [what that visit does, days until the next one]; the last stage has no next visit. The visit screen shows them so the
    | doctor sees "sitting 2 of 3: Cleaning and dressing" without typing it.
    */
    'stages' => [
        'root canal' => [['Access opening and cleaning', 5], ['Cleaning and dressing', 5], ['Obturation', 0]],
        'rct' => [['Access opening and cleaning', 5], ['Cleaning and dressing', 5], ['Obturation', 0]],
        'endodont' => [['Access opening and cleaning', 5], ['Cleaning and dressing', 5], ['Obturation', 0]],
        'pulpectomy' => [['Pulp removal and dressing', 5], ['Obturation', 0]],
        'implant' => [['Implant surgery', 14], ['Healing check', 14], ['Abutment', 7], ['Crown fitting', 0]],
        'crown' => [['Preparation and impression', 7], ['Fitting and cementation', 0]],
        'bridge' => [['Preparation and impression', 7], ['Fitting and cementation', 0]],
        'veneer' => [['Preparation and impression', 7], ['Fitting and cementation', 0]],
        'zirconia' => [['Preparation and impression', 7], ['Fitting and cementation', 0]],
        'ceramic cap' => [['Preparation and impression', 7], ['Fitting and cementation', 0]],
        'denture' => [['Impression', 7], ['Try-in', 7], ['Delivery', 0]],
        'wisdom' => [['Surgery', 7], ['Suture removal', 0]],
        'surgical' => [['Surgery', 7], ['Suture removal', 0]],
        'flap' => [['Surgery', 7], ['Review', 0]],
        'gum' => [['Surgery', 7], ['Review', 0]],
    ],

    /*
    | Prescriptions a dentist can add in one tap. The doctor reads and may edit each line before the visit is signed.
    */
    'prescription_templates' => [
        ['key' => 'pain_antibiotic', 'label' => 'Pain and antibiotic', 'lines' => [
            ['drug_name' => 'Amoxicillin 500 mg', 'dosage' => '1 capsule', 'frequency' => 'Three times a day', 'duration' => '5 days', 'instructions' => 'After food'],
            ['drug_name' => 'Ibuprofen 400 mg', 'dosage' => '1 tablet', 'frequency' => 'When in pain, max 3 a day', 'duration' => '3 days', 'instructions' => 'After food'],
        ]],
        ['key' => 'after_extraction', 'label' => 'After extraction', 'lines' => [
            ['drug_name' => 'Paracetamol 650 mg', 'dosage' => '1 tablet', 'frequency' => 'When in pain, max 3 a day', 'duration' => '3 days', 'instructions' => 'After food'],
            ['drug_name' => 'Chlorhexidine 0.2% mouthwash', 'dosage' => '10 ml', 'frequency' => 'Twice a day', 'duration' => '7 days', 'instructions' => 'Start the day after'],
        ]],
        ['key' => 'gum_care', 'label' => 'Gum care', 'lines' => [
            ['drug_name' => 'Metronidazole 400 mg', 'dosage' => '1 tablet', 'frequency' => 'Three times a day', 'duration' => '5 days', 'instructions' => 'After food'],
            ['drug_name' => 'Chlorhexidine 0.2% mouthwash', 'dosage' => '10 ml', 'frequency' => 'Twice a day', 'duration' => '14 days', 'instructions' => null],
        ]],
        ['key' => 'sensitivity', 'label' => 'Sensitivity', 'lines' => [
            ['drug_name' => 'Desensitising toothpaste', 'dosage' => 'Pea-sized amount', 'frequency' => 'Twice a day', 'duration' => '4 weeks', 'instructions' => 'Do not rinse straight after'],
        ]],
    ],
];
