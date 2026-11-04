<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\IdentityTestCase;

class AuthenticationTest extends IdentityTestCase
{
    public function test_valid_login_uses_session_and_records_time_and_audit(): void
    {
        $this->get('/login');
        $previousSession = session()->getId();
        $this->post('/login', ['email' => strtoupper($this->admin->email), 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->admin);
        $this->assertNotSame($previousSession, session()->getId());
        $this->assertNotNull($this->inspect($this->admin)->last_login_at);
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event_type', 'auth.login_success')->count());
    }

    public function test_native_session_rehydrates_identity_before_tenant_scoped_queries(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        Auth::forgetGuards();
        $this->get('/users')->assertOk()->assertSee($this->admin->email)->assertDontSee($this->other->email);
    }

    public function test_invalid_login_is_generic_and_audited_without_credentials(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'invalid-fixture-secret'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->context()->apply(platform: true);
        $event = AuditEvent::withoutGlobalScopes()->sole();
        $this->assertSame('auth.login_failed', $event->event_type);
        $this->assertNull($event->user_id);
        $this->assertNull($event->ip_address);
        $this->assertStringNotContainsString('invalid-fixture-secret', $event->toJson());
        $this->assertStringNotContainsString($this->admin->email, $event->toJson());
    }

    public function test_invalid_email_type_is_a_validation_error(): void
    {
        $this->post('/login', ['email' => ['invalid'], 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_by_identity_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $this->admin->email, 'password' => 'invalid'])->assertRedirect();
        }
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGuest();
    }

    public function test_account_disabled_is_rejected_and_existing_session_is_revoked(): void
    {
        $this->context()->apply(platform: true);
        $this->admin->forceFill(['status' => 'disabled'])->save();
        $this->context()->apply();
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_disabled_tenant_cannot_login_or_continue_a_session(): void
    {
        $this->context()->apply(platform: true);
        $this->a->update(['status' => 'disabled']);
        $this->context()->apply();
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout_invalidates_authentication_and_is_audited(): void
    {
        $this->actingAs($this->admin)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->context()->apply(platform: true);
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event_type', 'auth.logout')->count());
    }

    public function test_required_password_change_blocks_every_privileged_route_until_changed(): void
    {
        $this->context()->apply(platform: true);
        $this->admin->forceFill(['must_change_password' => true])->save();
        $this->context()->apply();
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertRedirect('/password');
        foreach (['/dashboard', '/users', '/audit'] as $url) {
            $this->get($url)->assertRedirect('/password');
        }
        $this->get('/password')->assertOk();
        $this->put('/password', ['current_password' => self::PASSWORD, 'password' => 'Replacement-Fixture-2!', 'password_confirmation' => 'Replacement-Fixture-2!'])->assertRedirect('/dashboard');
        $user = $this->inspect($this->admin);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Replacement-Fixture-2!', $user->password));
        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->get('/dashboard')->assertOk();
        $this->context()->apply(platform: true);
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event_type', 'auth.password_changed')->count());
    }

    public function test_password_change_requires_current_password_and_strong_new_password(): void
    {
        $this->actingAs($this->admin)->put('/password', ['current_password' => 'wrong', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors(['current_password', 'password']);
        $this->assertTrue(Hash::check(self::PASSWORD, $this->inspect($this->admin)->password));
    }

    public function test_native_rehash_on_login_can_update_under_rls(): void
    {
        config(['hashing.argon.time' => 2]);
        Hash::forgetDrivers();
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->assertSame(2, password_get_info($this->inspect($this->admin)->password)['options']['time_cost']);
    }

    public function test_csrf_is_enforced_and_cookie_flags_are_appropriate(): void
    {
        $this->app->bind(PreventRequestForgery::class, function ($app) {
            return new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
        $this->post('/login', ['email' => $this->admin->email, 'password' => self::PASSWORD])->assertStatus(419);
        $this->post('/logout')->assertStatus(419);
        $response = $this->get('/login');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertTrue(config('session.encrypt'));
    }

    public function test_password_change_deletes_other_database_sessions(): void
    {
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->admin->id, 'payload' => 'fixture', 'last_activity' => time()]);
        $this->actingAs($this->admin)->put('/password', ['current_password' => self::PASSWORD, 'password' => 'Replacement-Fixture-2!', 'password_confirmation' => 'Replacement-Fixture-2!'])->assertRedirect('/dashboard');
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
    }
}
