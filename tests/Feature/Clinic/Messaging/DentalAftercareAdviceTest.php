<?php

use App\Services\Dental\DentalConsultation;

/** The kind of aftercare advice a procedure name gets: the first configured pattern that matches. */
function aftercareKindFor(string $procedure): ?string
{
    foreach ((array) config('clinic_messages.aftercare') as $kind => $advice) {
        if (preg_match($advice['pattern'], $procedure)) {
            return $kind;
        }
    }

    return null;
}

it('gives every common dental treatment its own care advice', function (string $procedure, string $kind) {
    expect(aftercareKindFor($procedure))->toBe($kind);
})->with([
    ['Tooth extraction', 'extraction'],
    ['Root canal treatment', 'root_canal'],
    ['Endodontic therapy', 'root_canal'],
    ['Dental implant', 'implant'],
    ['Zirconia crown', 'crown_bridge'],
    ['Porcelain bridge', 'crown_bridge'],
    ['Complete denture', 'denture'],
    ['Teeth whitening', 'whitening'],
    ['Wisdom tooth surgery', 'surgery'],
    ['Braces adjustment', 'braces'],
    ['Scaling and polishing', 'scaling'],
    ['Composite filling', 'filling'],
]);

it('sends nothing for a procedure it has no advice for', function () {
    expect(aftercareKindFor('Consultation'))->toBeNull();
});

it('lays out the visits of a treatment from its name, whatever the clinic calls it', function (string $service, int $visits) {
    expect(DentalConsultation::sittingsOf($service)['visits'])->toBe($visits);
})->with([
    ['Root canal treatment', 3],
    ['Endodontic therapy', 3],
    ['Zirconia crown', 2],
    ['Dental implant', 4],
    ['Scaling', 1],
]);
