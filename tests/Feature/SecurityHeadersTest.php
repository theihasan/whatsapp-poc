<?php

it('sends baseline hardening headers', function () {
    $this->get(route('login'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('applies a nonce-based script policy and nonces the inline theme script', function () {
    config(['security.csp_enabled' => true]);

    $response = $this->get(route('login'));
    $policy = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $policy, $nonce);

    expect($policy)->toContain("script-src 'self' 'nonce-")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain("script-src 'self' 'unsafe-inline'");
    $response->assertSee('nonce="'.$nonce[1].'"', false);
});

it('omits the policy when disabled for local development', function () {
    config(['security.csp_enabled' => false]);

    $this->get(route('login'))->assertHeaderMissing('Content-Security-Policy');
});
