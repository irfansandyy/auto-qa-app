<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_fresh_workspace_shows_first_operator_setup(): void
    {
        $response = $this->get('/register');

        $response->assertOk()->assertSee('Create your operator account');
    }

    public function test_first_registration_creates_hashed_operator_and_signs_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'First Operator', 'email' => 'OPERATOR@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'operator@example.test']);
        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    public function test_registration_is_closed_after_first_operator_exists(): void
    {
        User::factory()->create();

        $response = $this->post('/register', [
            'name' => 'Second Operator', 'email' => 'second@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_registered_workspace_redirects_setup_page_to_sign_in(): void
    {
        User::factory()->create();

        $response = $this->get('/register');

        $response->assertRedirect(route('login'));
    }

    public function test_setup_rejects_short_password_without_creating_account(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'Operator', 'email' => 'operator@example.test',
            'password' => 'short', 'password_confirmation' => 'short',
        ]);

        $response->assertRedirect('/register')->assertSessionHasErrors(['password']);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_valid_credentials_sign_in_the_operator(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.test',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->post('/login', [
            'email' => 'OPERATOR@example.test', 'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_do_not_create_an_authenticated_session(): void
    {
        User::factory()->create(['email' => 'operator@example.test']);

        $response = $this->from('/login')->post('/login', [
            'email' => 'operator@example.test', 'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login')->assertSessionHasErrors([
            'email' => 'The email or password did not match.',
        ]);
        $this->assertGuest();
    }

    public function test_repeated_failed_sign_ins_temporarily_block_even_correct_password(): void
    {
        User::factory()->create([
            'email' => 'limited@example.test', 'password' => Hash::make('correct-horse-battery'),
        ]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'limited@example.test', 'password' => 'wrong-password']);
        }

        $response = $this->post('/login', [
            'email' => 'limited@example.test', 'password' => 'correct-horse-battery',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_logout_removes_operator_authentication(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
