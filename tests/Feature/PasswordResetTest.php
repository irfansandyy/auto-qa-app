<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetCode;
use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.from.address' => 'admin@example.test',
        ]);
    }

    public function test_guests_can_request_recovery_and_the_code_page_requires_a_request(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'));
        $this->get(route('password.request'))->assertOk()->assertSee(route('password.email'));
        $this->get(route('password.reset'))->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->post(route('password.update'), [
            'code' => '123456', 'password' => 'example8', 'password_confirmation' => 'example8',
        ])->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
    }

    public function test_an_emailed_code_resets_only_the_account_bound_to_the_session_and_accepts_eight_characters(): void
    {
        Mail::fake();
        $operator = User::factory()->create(['email' => 'owner@example.test']);
        $other = User::factory()->create();

        $this->post(route('password.email'), ['email' => ' OWNER@example.test '])->assertRedirect(route('password.reset'));
        $this->get(route('password.reset'))->assertOk()->assertSee('owner@example.test')->assertSee(route('password.update'));
        Mail::assertSent(PasswordResetCode::class, fn (PasswordResetCode $mail): bool => $mail->hasTo($operator->email));
        $mail = Mail::sent(PasswordResetCode::class)->sole();
        $code = $mail->content()->with['code'];
        $mail->assertSeeInText($code)->assertSeeInText('This code expires in 10 minutes');
        $this->from(route('password.reset'))->post(route('password.update'), [
            'code' => $code, 'password' => 'short77', 'password_confirmation' => 'short77',
        ])->assertRedirect(route('password.reset'))->assertSessionHasErrors('password')->assertSessionMissing('_old_input.code');
        $this->post(route('password.update'), [
            'email' => $other->email, 'code' => $code, 'password' => 'sunrise8', 'password_confirmation' => 'sunrise8',
        ])->assertRedirect(route('login'))->assertSessionHas('password_reset_success', true);

        $this->assertTrue(Hash::check('sunrise8', $operator->fresh()->password));
        $this->assertSame($other->password, $other->fresh()->password);
        $this->assertGuest();
    }

    public function test_unknown_addresses_and_requests_within_the_resend_cooldown_receive_the_same_response(): void
    {
        Mail::fake();
        $operator = User::factory()->create();

        $this->post(route('password.email'), ['email' => $operator->email])->assertRedirect(route('password.reset'));
        $code = Mail::sent(PasswordResetCode::class)->sole()->content()->with['code'];
        $this->post(route('password.email'), ['email' => $operator->email])->assertRedirect(route('password.reset'));
        $this->post(route('password.update'), [
            'code' => $code, 'password' => 'sunrise8', 'password_confirmation' => 'sunrise8',
        ])->assertRedirect(route('login'));
        $this->post(route('password.email'), ['email' => 'unregistered@example.test'])->assertRedirect(route('password.reset'));
        $this->get(route('password.reset'))->assertOk()->assertSee('unregistered@example.test');

        Mail::assertSentCount(1);
    }

    public function test_local_log_mailer_reports_recovery_unavailable_and_queues_no_code(): void
    {
        config(['mail.default' => 'log']);
        Queue::fake([SendPasswordResetCode::class]);

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'anyone@example.test'])
            ->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => __('auth-ui.reset_unavailable')]);

        Queue::assertNothingPushed();
    }
}
