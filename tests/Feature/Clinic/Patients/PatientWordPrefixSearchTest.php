<?php

use App\Models\Clinic;
use App\Models\Organization;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/*
 * Patient search matches the start of any word of the name, or the start of
 * a phone number or UHID, inside one clinic. It no longer goes through the
 * platform-wide FULLTEXT index, whose cost grew with every tenant's patients.
 */

function wordPrefixClinicWith(array $names): Clinic
{
    $clinic = Clinic::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    foreach ($names as $index => $name) {
        [$first, $last] = explode(' ', $name, 2);
        Patient::factory()->create([
            'clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id,
            'first_name' => $first, 'last_name' => $last, 'name' => $name,
            'phone' => '98450000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'uhid' => 'OC-0000010'.$index,
        ]);
    }

    return $clinic;
}

/** @return list<string> */
function wordPrefixSearch(Clinic $clinic, string $search): array
{
    return Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->matchingSearch($search)
        ->orderBy('name')->pluck('name')->all();
}

it('finds patients by the start of any word of their name, a phone or a UHID', function (string $search, array $expected) {
    $clinic = wordPrefixClinicWith(['Asha Sharma', 'Rahul Verma', 'Mary Ann Joseph', 'Ashok Kumar']);

    expect(wordPrefixSearch($clinic, $search))->toBe($expected);
})->with([
    'first-name prefix' => ['ash', ['Asha Sharma', 'Ashok Kumar']],
    'last-name prefix' => ['ver', ['Rahul Verma']],
    'two words, any order' => ['sha as', ['Asha Sharma']],
    'a later word of a multi-word name' => ['ann', ['Mary Ann Joseph']],
    'phone prefix' => ['9845000001', ['Rahul Verma']],
    'UHID prefix' => ['OC-00000103', ['Ashok Kumar']],
    'not the middle of a word' => ['arma', []],
]);

it('treats typed wildcard characters literally', function () {
    $clinic = wordPrefixClinicWith(['Asha Sharma']);

    expect(wordPrefixSearch($clinic, '%'))->toBe([])
        ->and(wordPrefixSearch($clinic, '_sha'))->toBe([]);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'SQLite LIKE has no default escape character; MySQL (production) escapes with a backslash.');

it('never searches through the platform-wide fulltext index', function () {
    $sql = strtolower(Patient::withoutGlobalScopes()->where('clinic_id', 1)->matchingSearch('Asha Sharma')->toSql());

    expect($sql)->not->toContain('match');
});

it('looks a number up only in the indexed phone and ID columns, and a name first in the indexed first and last names', function () {
    $clinic = Clinic::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    Patient::factory()->create([
        'clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id,
        'first_name' => 'Ravi', 'middle_name' => 'Kumar', 'last_name' => 'Shah', 'phone' => '9811122233', 'uhid' => 'OC-00000777',
    ]);
    $search = fn (string $q) => Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->matchingSearch($q);

    expect($search('Ravi Kumar Shah')->count())->toBe(1)
        ->and($search('Shah Ravi')->count())->toBe(1)
        ->and($search('9811')->count())->toBe(1)
        ->and($search('OC-0000077')->count())->toBe(1);

    $digits = $search('9811')->toSql();
    $words = $search('Ravi Kumar')->toSql();
    expect($digits)->not->toContain('first_name')->not->toContain('middle_name')
        ->and($words)->not->toContain('phone')->not->toContain('"name"');
});
