<?php

/*
|--------------------------------------------------------------------------
| Clinic patient WhatsApp automations
|--------------------------------------------------------------------------
|
| One automation per patient message code of `PatientMessaging` (the one
| producer of what a clinic tells its patients; its texts for other channels
| are in `clinic_messages.php`). When a clinic switches an automation on and
| its WhatsApp is connected, that message goes out on WhatsApp.
|
| `event`: what produces it. `variables`: the WhatsApp template's positional
| values, by placeholder name; the clinic's name is always first, so on
| OralCares' common number a patient always knows which clinic is writing.
| `requires_extension` keeps a specialty's automations to clinics that bought
| that specialty. Texts never name a diagnosis, condition, medicine or
| procedure: only who, when and how much.
|
*/

return [

    /** Days after a bill is issued before an unpaid balance counts as overdue. */
    'overdue_after_days' => 7,

    /** Days before a follow-up's date that its reminder goes. */
    'follow_up_reminder_days' => 1,

    /** Sessions left at which the package balance reminder goes. */
    'package_low_balance' => 2,

    /** Days before a package's validity ends that the expiry reminder goes. */
    'package_expiry_days' => 7,

    'groups' => [
        'appointments' => 'Appointments',
        'reception' => 'Reception',
        'dental' => 'Dental',
        'physio' => 'Physiotherapy',
        'billing' => 'Billing',
        'follow_ups' => 'Follow-ups',
        'general' => 'General',
    ],

    'automations' => [
        'appointment_confirmed' => [
            'group' => 'appointments', 'label' => 'Appointment confirmation', 'event' => 'appointment.created',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, your appointment is confirmed for {{3}}.',
        ],
        'appointment_reminder' => [
            'group' => 'appointments', 'label' => 'Appointment reminder', 'event' => 'appointment.reminder_due',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, a reminder of your appointment on {{3}}.',
        ],
        'appointment_rescheduled' => [
            'group' => 'appointments', 'label' => 'Appointment rescheduled', 'event' => 'appointment.rescheduled',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, your appointment has moved to {{3}}.',
        ],
        'appointment_cancelled' => [
            'group' => 'appointments', 'label' => 'Appointment cancelled', 'event' => 'appointment.cancelled',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, your appointment on {{3}} has been cancelled. Please contact us to book again.',
        ],
        'appointment_missed' => [
            'group' => 'appointments', 'label' => 'Missed appointment', 'event' => 'appointment.no_show',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, we missed you on {{3}}. Please contact us to book a new time.',
        ],
        'checkin_confirmation' => [
            'group' => 'reception', 'label' => 'Check-in confirmation', 'event' => 'visit.checked_in',
            'variables' => ['clinic', 'patient', 'token'],
            'body' => '{{1}}: Hello {{2}}, you are checked in. Your token is {{3}}.',
        ],
        'queue_turn' => [
            'group' => 'reception', 'label' => 'Your turn', 'event' => 'queue.called',
            'variables' => ['clinic', 'patient'],
            'body' => '{{1}}: Hello {{2}}, it is your turn now. Please come in.',
        ],
        'dental_treatment_followup' => [
            'group' => 'dental', 'label' => 'Treatment follow-up', 'event' => 'treatment.stage_completed', 'requires_extension' => 'odontogram',
            'variables' => ['clinic', 'patient'],
            'body' => '{{1}}: Hello {{2}}, this step of your treatment is complete. Please book your next visit with us.',
        ],
        'procedure_aftercare' => [
            'group' => 'dental', 'label' => 'Care after a procedure', 'event' => 'procedure.recorded', 'requires_extension' => 'odontogram',
            'variables' => ['clinic', 'patient', 'procedure', 'care'],
            'body' => '{{1}}: Hello {{2}}, after your {{3}}: {{4}} Call us if you have any concerns.',
        ],
        'dental_plan_waiting' => [
            'group' => 'dental', 'label' => 'Treatment waiting for a visit', 'event' => 'treatment.plan_stalled', 'requires_extension' => 'odontogram',
            'variables' => ['clinic', 'patient'],
            'body' => '{{1}}: Hello {{2}}, your treatment with us is waiting for your next visit. Please book a time that suits you.',
        ],
        'dental_procedure_followup' => [
            'group' => 'dental', 'label' => 'After-visit check', 'event' => 'visit.completed', 'requires_extension' => 'odontogram',
            'variables' => ['clinic', 'patient'],
            'body' => '{{1}}: Hello {{2}}, we hope you are feeling well after your visit. Call us if you have any concerns.',
        ],
        'physio_session_reminder' => [
            'group' => 'physio', 'label' => 'Session reminder', 'event' => 'physio.session.reminder_due', 'requires_extension' => 'session_packages',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, a reminder of your session on {{3}}.',
        ],
        'physio_next_session' => [
            'group' => 'physio', 'label' => 'Next session', 'event' => 'physio.session.completed', 'requires_extension' => 'session_packages',
            'variables' => ['clinic', 'patient', 'next'],
            'body' => '{{1}}: Hello {{2}}, today\'s session is complete. Next: {{3}}.',
        ],
        'physio_package_balance' => [
            'group' => 'physio', 'label' => 'Package balance', 'event' => 'physio.package.low_balance', 'requires_extension' => 'session_packages',
            'variables' => ['clinic', 'patient', 'remaining'],
            'body' => '{{1}}: Hello {{2}}, you have {{3}} session(s) left in your package.',
        ],
        'physio_package_expiry' => [
            'group' => 'physio', 'label' => 'Package expiry', 'event' => 'physio.package.expiring', 'requires_extension' => 'session_packages',
            'variables' => ['clinic', 'patient', 'date'],
            'body' => '{{1}}: Hello {{2}}, your session package is valid until {{3}}. Please book your remaining sessions before then.',
        ],
        'bill_invoice' => [
            'group' => 'billing', 'label' => 'Invoice', 'event' => 'bill.created',
            'variables' => ['clinic', 'patient', 'number', 'amount'],
            'body' => '{{1}}: Hello {{2}}, your bill {{3}} for {{4}} is ready.',
        ],
        'payment_due' => [
            'group' => 'billing', 'label' => 'Payment due', 'event' => 'payment.due',
            'variables' => ['clinic', 'patient', 'amount', 'number'],
            'body' => '{{1}}: Hello {{2}}, {{3}} is due on bill {{4}}.',
        ],
        'payment_overdue' => [
            'group' => 'billing', 'label' => 'Payment overdue', 'event' => 'payment.overdue',
            'variables' => ['clinic', 'patient', 'amount', 'number'],
            'body' => '{{1}}: Hello {{2}}, {{3}} on bill {{4}} is overdue. Please contact us to settle it.',
        ],
        'payment_received' => [
            'group' => 'billing', 'label' => 'Payment received', 'event' => 'payment.completed',
            'variables' => ['clinic', 'patient', 'amount', 'balance'],
            'body' => '{{1}}: Hello {{2}}, we received {{3}}. Balance due: {{4}}.',
        ],
        'payment_receipt' => [
            'group' => 'billing', 'label' => 'Receipt', 'event' => 'payment.completed',
            'variables' => ['clinic', 'patient', 'number', 'amount'],
            'body' => '{{1}}: Hello {{2}}, receipt {{3}} for {{4}}. Thank you.',
        ],
        'follow_up_reminder' => [
            'group' => 'follow_ups', 'label' => 'Follow-up reminder', 'event' => 'followup.reminder_due',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, a reminder that your follow-up visit is due on {{3}}. Please book a time with us.',
        ],
        'follow_up_due' => [
            'group' => 'follow_ups', 'label' => 'Follow-up due today', 'event' => 'followup.due',
            'variables' => ['clinic', 'patient', 'when'],
            'body' => '{{1}}: Hello {{2}}, your follow-up visit is due today ({{3}}). Please call us to book.',
        ],
        'prescription_ready' => [
            'group' => 'general', 'label' => 'Prescription issued', 'event' => 'prescription.issued',
            'variables' => ['clinic', 'patient', 'number'],
            'body' => '{{1}}: Hello {{2}}, your prescription {{3}} is ready. Ask us for a copy if you need one.',
        ],
        'report_ready' => [
            'group' => 'general', 'label' => 'Report ready', 'event' => 'report.reviewed',
            'variables' => ['clinic', 'patient'],
            'body' => '{{1}}: Hello {{2}}, your test result has been reviewed by your doctor. Please contact us to discuss it.',
        ],
        'feedback_request' => [
            'group' => 'general', 'label' => 'Feedback request', 'event' => 'visit.completed',
            'variables' => ['clinic', 'patient'],
            'body' => '{{1}}: Hello {{2}}, thank you for visiting us. How was your experience? Reply to let us know.',
        ],
    ],
];
