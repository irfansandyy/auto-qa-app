<?php

namespace Tests\Feature;

use App\Models\Project;
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

    public function test_signup_page_is_available_before_any_account_exists(): void
    {
        $response = $this->get('/register');

        $response->assertOk()->assertSee('Create your account')->assertSee(route('login'));
    }

    public function test_registration_creates_hashed_account_and_signs_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'First User', 'email' => ' OPERATOR@example.test ',
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

    public function test_registration_creates_separate_account_when_other_users_exist(): void
    {
        $existingUser = User::factory()->create();
        Project::factory()->for($existingUser)->create(['name' => 'Existing private project']);

        $response = $this->post('/register', [
            'name' => 'Second Operator', 'email' => 'second@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('users', 2);
        $this->assertModelExists($existingUser);
        $this->assertAuthenticatedAs(User::query()->where('email', 'second@example.test')->sole());

        $this->get('/dashboard')->assertOk()->assertDontSee('Existing private project');
    }

    public function test_signup_remains_available_to_guests_after_an_account_exists(): void
    {
        User::factory()->create();

        $response = $this->get('/register');

        $response->assertOk()->assertSee('Create your account');
    }

    public function test_sign_in_page_links_to_signup_before_any_account_exists(): void
    {
        $response = $this->get('/login');

        $response->assertOk()->assertSee('Sign in to your workspace')->assertSee(route('register'));
    }

    public function test_registration_rejects_short_password_without_creating_account(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'Operator', 'email' => 'operator@example.test',
            'password' => 'short', 'password_confirmation' => 'short',
        ]);

        $response->assertRedirect('/register')->assertSessionHasErrors(['password']);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_rejects_mismatched_password_confirmation(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'New User', 'email' => 'new@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'different-password',
        ]);

        $response->assertRedirect('/register')->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_rejects_existing_email_after_normalizing_case_and_spaces(): void
    {
        $existingUser = User::factory()->create(['email' => 'existing@example.test']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Duplicate User', 'email' => ' EXISTING@example.test ',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/register')->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertModelExists($existingUser);
        $this->assertGuest();
    }

    public function test_registration_ignores_submitted_owner_id(): void
    {
        $existingUser = User::factory()->create();

        $response = $this->post('/register', [
            'id' => $existingUser->id,
            'name' => 'New User', 'email' => 'new@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($existingUser->email, $existingUser->fresh()->email);
        $this->assertAuthenticatedAs(User::query()->where('email', 'new@example.test')->sole());
    }

    public function test_signed_in_users_cannot_create_another_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/register', [
            'name' => 'Another User', 'email' => 'another@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($user);
    }

    public function test_repeated_registration_requests_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/register', []);
        }

        $response = $this->post('/register', [
            'name' => 'New User', 'email' => 'new@example.test',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_valid_credentials_sign_in_to_the_matching_account(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.test',
            'password' => Hash::make('correct-horse-battery'),
        ]);
        Project::factory()->for($user)->create(['name' => 'Account project']);
        Project::factory()->create(['name' => 'Other account private project']);

        $response = $this->post('/login', [
            'email' => 'OPERATOR@example.test', 'password' => 'correct-horse-battery',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->get('/dashboard')->assertOk()->assertSee('Account project')->assertDontSee('Other account private project');
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
