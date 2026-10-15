<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Where a guest is sent, and the fact that the API is sent nowhere at all.
 *
 * The framework's default is a route named `login`, which assumes an
 * application with a web login route. This one's login belongs to a panel, so
 * the default names a route that does not exist and the redirect raises while
 * it is being computed — which the caller reads as the backend's own fault
 * rather than as a refusal.
 */
class GuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_route_is_named_login(): void
    {
        $this->assertFalse(
            Route::has('login'),
            'The framework default guest redirect names a route called `login`; nothing may depend on one.',
        );
    }

    public function test_the_login_a_guest_is_sent_to_is_the_panels_own(): void
    {
        $this->assertTrue(Route::has('filament.review.auth.login'));

        Route::middleware('auth')->get('/_guest-redirect-probe', fn (): string => 'ok');

        $this->get('/_guest-redirect-probe')->assertRedirect('/review/login');
    }

    /**
     * Filament's panel does its own redirecting, so this is not the same path
     * as the probe above — and it is the one the owner actually walks.
     */
    public function test_the_review_ui_still_sends_a_signed_out_visitor_to_a_login_page(): void
    {
        $this->get('/review')->assertRedirect('/review/login');
    }
}
