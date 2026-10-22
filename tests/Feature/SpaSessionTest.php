<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The SPA authenticates with Sanctum's session cookie. Sanctum only treats a request as
 * coming from the SPA (and so attaches the session) when it carries a same-origin Referer
 * or Origin. Browsers send no Origin on same-origin GETs, so the Referer must survive:
 * a `no-referrer` policy turns every authenticated GET into an anonymous 401.
 */
class SpaSessionTest extends TestCase
{
    public function test_referrer_policy_keeps_same_origin_referer_but_hides_it_from_other_sites(): void
    {
        $this->get('/admin')->assertOk()->assertHeader('Referrer-Policy', 'same-origin');
        $this->get('/referee/'.str_repeat('a', 64))->assertHeader('Referrer-Policy', 'same-origin');
        $this->assertStringContainsString('<meta name="referrer" content="same-origin">', $this->get('/login')->getContent());
    }

    public function test_session_from_login_authenticates_later_same_origin_gets(): void
    {
        $this->recruitment();
        // Login is a POST: browsers attach Origin.
        $this->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/login', ['email' => $this->super->email, 'password' => 'Password@123'])->assertOk();

        // Navigation GETs: no Origin, only the same-origin Referer the policy allows.
        $this->withHeaders(['Origin' => null, 'Referer' => config('app.url').'/admin'])
            ->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_requests_without_a_session_are_rejected_not_silently_accepted(): void
    {
        $this->recruitment();
        $this->getJson('/api/admin/dashboard')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }
}
