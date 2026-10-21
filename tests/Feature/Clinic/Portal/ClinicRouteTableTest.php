<?php

/**
 * The client route table (Ziggy) on Clinic pages is the Clinic Portal's own:
 * the `clinic.*` names its sign-in links and intake resolve, and none of the
 * Company Portal's. Company pages keep the full table they resolve by name.
 */
test('a clinic page carries the clinic routes it resolves and no Company route names', function () {
    $html = $this->get('/clinic/login')->assertOk()->getContent();

    expect($html)->toContain('"clinic.password.request"')
        ->toContain('"clinic.reception.check-in"')
        ->not->toContain('"company.');
});

test('a Company page keeps the full route table', function () {
    $html = $this->get('/company/login')->assertOk()->getContent();

    expect($html)->toContain('"company.');
});
