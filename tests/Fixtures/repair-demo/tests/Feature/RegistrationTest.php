<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_with_phone_succeeds_and_can_sign_in(): void
    {
        $registration = $this->post('/register', [
            'name' => 'Demo Student', 'email' => 'student@example.test',
            'phone' => '+62 812 3456', 'password' => 'Demo-password-2026',
            'password_confirmation' => 'Demo-password-2026',
        ]);
        $registration->assertRedirect('/dashboard');
        $this->assertDatabaseHas('users', ['email' => 'student@example.test', 'phone' => '+628123456']);
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', [
            'email' => 'student@example.test', 'password' => 'Demo-password-2026',
        ])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Account dashboard');
    }

    public function test_registration_form_and_prepared_stylesheet_are_available(): void
    {
        $response = $this->get('/register');
        $response->assertOk()->assertSee('Phone is optional.')->assertSee('/build/assets/app.css');
    }
}
