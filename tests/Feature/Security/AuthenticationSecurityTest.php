<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class AuthenticationSecurityTest extends SecurityTestCase
{
    public function test_guests_cannot_open_protected_pages(): void
    {
        foreach (['admin.dashboard', 'sales.dashboard', 'sale.index', 'setting.create', 'salesman.index', 'reports.net-profit'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }

        $this->getJson(route('sale.index'))->assertUnauthorized();
    }

    public function test_home_page_sends_users_to_their_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->staff('accountant'))->get('/')->assertRedirect(route('sales.dashboard'));
    }

    public function test_login_redirects_each_role_to_its_home(): void
    {
        $admin = $this->admin();
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->post('/logout');

        $tech = $this->staff('technician');
        $this->post('/login', ['email' => $tech->email, 'password' => 'password'])->assertRedirect(route('sales.dashboard'));
        $this->assertAuthenticatedAs($tech);
    }

    public function test_login_is_rate_limited_after_five_wrong_passwords(): void
    {
        $user = $this->staff('salesman');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }

        // ekhon thik password o kaj kore na
        $error = $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->getSession()->get('errors')->first('email');
        $this->assertStringContainsString('Too many login attempts', $error);
        $this->assertGuest();
    }

    public function test_wrong_password_and_unknown_email_get_the_same_message(): void
    {
        $user = $this->staff('salesman');

        $wrong = $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->getSession()->get('errors')->first('email');
        $unknown = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'nope'])->getSession()->get('errors')->first('email');

        $this->assertSame($wrong, $unknown);
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->staff('salesman', ['status' => 0]);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Your account is inactive.']);
        $this->assertGuest();

        // login page e message ta dekhay
        $this->get('/login')->assertSee('Your account is inactive.');
    }

    public function test_user_deactivated_while_logged_in_is_logged_out_on_next_request(): void
    {
        $user = $this->staff('salesman');
        $this->actingAs($user)->get(route('sale.index'))->assertOk();

        $user->update(['status' => 0]);

        $this->get(route('sale.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivated_user_ajax_request_gets_403(): void
    {
        $user = $this->staff('salesman', ['status' => 0]);

        $this->actingAs($user)->getJson(route('sale.index'))->assertForbidden();
    }

    public function test_forgot_password_does_not_reveal_whether_the_email_exists(): void
    {
        $user = $this->staff('salesman');

        $known = $this->post('/forgot-password', ['email' => $user->email])->getSession()->get('status');
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHasNoErrors()->getSession()->get('status');

        $this->assertSame($known, $unknown);
    }

    public function test_changing_password_signs_out_other_devices(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->staff('salesman');
        $other = $this->staff('salesman');
        foreach ([['phone-session', $user->id], ['laptop-session', $user->id], ['someone-else', $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }

        $oldRememberToken = $user->remember_token;

        $this->actingAs($user)->post(route('profile.change.password.updated'), [
            'current_password' => 'password', 'new_password' => 'new-password-123', 'confirm_password' => 'new-password-123',
        ])->assertSessionHasNoErrors();

        // onno device er session gelo; je session theke bodlano holo seta (notun id) thake; onno user er thake
        $this->assertDatabaseMissing('sessions', ['id' => 'phone-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'laptop-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);
        $this->assertNotSame($oldRememberToken, $user->fresh()->remember_token);
    }

    public function test_csrf_protection_is_enabled_for_web_routes(): void
    {
        // test e Laravel CSRF check bondho rakhe, tai middleware group e ache kina dekha hoy
        $web = app(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(ValidateCsrfToken::class, $web);
    }

    public function test_server_errors_do_not_show_technical_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__boom', fn () => throw new \RuntimeException('SQLSTATE secret password=hunter2 at C:\\xampp\\app'));

        $response = $this->get('/__boom');

        $response->assertStatus(500)->assertSee('Something Went Wrong')
            ->assertDontSee('hunter2')->assertDontSee('SQLSTATE')->assertDontSee('xampp');
    }

    public function test_403_page_is_generic(): void
    {
        $this->actingAs($this->staff('salesman'))->get(route('purchase.index'))
            ->assertForbidden()->assertSee('Access Denied')->assertDontSee('permission:')->assertDontSee('Spatie');
    }

    public function test_error_message_helper_hides_system_errors_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $this->assertSame('Stock is low.', errorMessage(new \Exception('Stock is low.')));
        $this->assertSame('Something went wrong. Please try again.', errorMessage(new \RuntimeException('SQLSTATE[23000] users.password')));
    }

    public function test_new_users_get_a_hashed_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('salesman.store'), [
            'name' => 'Rafi', 'email' => 'rafi@example.com', 'phone' => '01711111111', 'address' => 'Dhaka',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'role' => 'salesman',
        ])->assertSessionHasNoErrors();

        $stored = User::where('email', 'rafi@example.com')->value('password');
        $this->assertNotSame('secret-pass-1', $stored);
        $this->assertTrue(password_verify('secret-pass-1', $stored));
    }
}
