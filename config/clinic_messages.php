<?php

/*
|--------------------------------------------------------------------------
| Patient messages
|--------------------------------------------------------------------------
|
| The messages a clinic sends its patients, by template code. These are the
| starting wording: the first time a clinic sends one, it is copied into the
| clinic's own `notification_templates` (code below), where the clinic can
| change it. {{placeholders}} are filled from the event.
|
| `reminder_hours_before`: when the appointment reminder goes out. A booking
| made inside that window gets no reminder (its confirmation is enough).
|
*/

return [

    'reminder_hours_before' => 24,

    /** Wall-clock hour a follow-up reminder is sent, the day before the review. */
    'follow_up_hour' => 9,

    'templates' => [
        'appointment_confirmed' => [
            'name' => 'Appointment confirmation',
            'subject' => 'Your appointment at {{clinic}}',
            'content' => 'Hello {{patient}}, your appointment with {{doctor}} at {{clinic}} is confirmed for {{when}}.',
        ],
        'appointment_reminder' => [
            'name' => 'Appointment reminder',
            'subject' => 'Reminder: your appointment at {{clinic}}',
            'content' => 'Hello {{patient}}, a reminder of your appointment with {{doctor}} at {{clinic}} on {{when}}.',
        ],
        'appointment_rescheduled' => [
            'name' => 'Appointment rescheduled',
            'subject' => 'Your appointment at {{clinic}} has moved',
            'content' => 'Hello {{patient}}, your appointment at {{clinic}} is now on {{when}} with {{doctor}}.',
        ],
        'appointment_cancelled' => [
            'name' => 'Appointment cancelled',
            'subject' => 'Your appointment at {{clinic}} is cancelled',
            'content' => 'Hello {{patient}}, your appointment at {{clinic}} on {{when}} has been cancelled. Call us to book again.',
        ],
        'follow_up_reminder' => [
            'name' => 'Follow-up reminder',
            'subject' => 'Your review at {{clinic}}',
            'content' => 'Hello {{patient}}, {{doctor}} asked to see you again on {{when}}. Please book your visit with {{clinic}}.',
        ],
        'prescription_ready' => [
            'name' => 'Prescription issued',
            'subject' => 'Your prescription from {{clinic}}',
            'content' => 'Hello {{patient}}, your prescription {{number}} from {{doctor}} is ready. Ask {{clinic}} for a copy if you need one.',
        ],
        'appointment_missed' => [
            'name' => 'Missed appointment',
            'subject' => 'We missed you at {{clinic}}',
            'content' => 'Hello {{patient}}, we missed you at your appointment on {{when}}. Please contact {{clinic}} to book a new time.',
        ],
        'checkin_confirmation' => [
            'name' => 'Check-in confirmation',
            'subject' => 'You are checked in at {{clinic}}',
            'content' => 'Hello {{patient}}, you are checked in at {{clinic}}. Your token is {{token}}.',
        ],
        'queue_turn' => [
            'name' => 'Your turn',
            'subject' => 'It is your turn at {{clinic}}',
            'content' => 'Hello {{patient}}, it is your turn now at {{clinic}}. Please come in.',
        ],
        'dental_treatment_followup' => [
            'name' => 'Treatment follow-up',
            'subject' => 'Your next visit at {{clinic}}',
            'content' => 'Hello {{patient}}, this step of your treatment is complete. Please book your next visit with {{clinic}}.',
        ],
        'dental_procedure_followup' => [
            'name' => 'After-visit check',
            'subject' => 'After your visit to {{clinic}}',
            'content' => 'Hello {{patient}}, we hope you are feeling well after your visit to {{clinic}}. Call us if you have any concerns.',
        ],
        'physio_session_reminder' => [
            'name' => 'Session reminder',
            'subject' => 'Your session at {{clinic}}',
            'content' => 'Hello {{patient}}, a reminder of your session at {{clinic}} on {{when}}.',
        ],
        'physio_next_session' => [
            'name' => 'Next session',
            'subject' => 'Your next session at {{clinic}}',
            'content' => 'Hello {{patient}}, today\'s session at {{clinic}} is complete. Next: {{next}}.',
        ],
        'physio_package_balance' => [
            'name' => 'Package balance',
            'subject' => 'Sessions left at {{clinic}}',
            'content' => 'Hello {{patient}}, you have {{remaining}} session(s) left in your package at {{clinic}}.',
        ],
        'physio_package_expiry' => [
            'name' => 'Package expiry',
            'subject' => 'Your package at {{clinic}}',
            'content' => 'Hello {{patient}}, your session package at {{clinic}} is valid until {{date}}. Book your remaining sessions before then.',
        ],
        'bill_invoice' => [
            'name' => 'Invoice',
            'subject' => 'Your bill from {{clinic}}',
            'content' => 'Hello {{patient}}, your bill {{number}} for {{amount}} from {{clinic}} is ready.',
        ],
        'payment_due' => [
            'name' => 'Payment due',
            'subject' => 'Payment due at {{clinic}}',
            'content' => 'Hello {{patient}}, {{amount}} is due on bill {{number}} at {{clinic}}.',
        ],
        'payment_overdue' => [
            'name' => 'Payment overdue',
            'subject' => 'Payment overdue at {{clinic}}',
            'content' => 'Hello {{patient}}, {{amount}} on bill {{number}} at {{clinic}} is overdue. Please contact us to settle it.',
        ],
        'payment_received' => [
            'name' => 'Payment received',
            'subject' => 'Payment received by {{clinic}}',
            'content' => 'Hello {{patient}}, {{clinic}} received {{amount}}. Balance due: {{balance}}.',
        ],
        'payment_receipt' => [
            'name' => 'Receipt',
            'subject' => 'Your receipt from {{clinic}}',
            'content' => 'Hello {{patient}}, receipt {{number}} for {{amount}} from {{clinic}}. Thank you.',
        ],
        'follow_up_due' => [
            'name' => 'Follow-up due today',
            'subject' => 'Your follow-up at {{clinic}}',
            'content' => 'Hello {{patient}}, your follow-up visit at {{clinic}} is due today ({{when}}). Please call us to book.',
        ],
        'feedback_request' => [
            'name' => 'Feedback request',
            'subject' => 'How was your visit to {{clinic}}?',
            'content' => 'Hello {{patient}}, thank you for visiting {{clinic}}. How was your experience? Reply to let us know.',
        ],
        'report_ready' => [
            'name' => 'Report ready',
            'subject' => 'Your report from {{clinic}}',
            'content' => 'Hello {{patient}}, your {{test}} result has been reviewed by your doctor. Contact {{clinic}} to discuss it.',
        ],
    ],
];
