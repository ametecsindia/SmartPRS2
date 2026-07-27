<?php

/*
 * Auth + access control on the SPA. Guests are bounced to login; an
 * authenticated company user can load the app's data endpoint.
 */

test('guests are redirected from the app to the login page', function () {
    $this->get('/app/data')->assertRedirect('/login');
});

test('the public landing page renders', function () {
    $this->get('/')->assertOk();
});

test('an authenticated admin can load the app data endpoint', function () {
    [$tid, $cid] = makeTenantCompany();
    $admin = makeUser('admin', $tid);

    $this->actingAs($admin)->get('/app/data')->assertOk();
});
