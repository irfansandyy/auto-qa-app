<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QaRun;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_new_users_receive_english_light_preferences_and_matching_server_rendered_markup(): void
    {
        $operator = User::factory()->create();

        $response = $this->actingAs($operator)->get(route('dashboard'));

        $this->assertSame('en', $operator->fresh()->locale);
        $this->assertSame('light', $operator->fresh()->theme);
        $response->assertOk()->assertSee('lang="en"', false)->assertSee('data-theme="light"', false)
            ->assertSee('name="color-scheme" content="light"', false);
    }

    public function test_preferences_require_authentication_without_changing_any_user(): void
    {
        $operator = User::factory()->create();

        $response = $this->post(route('settings.preferences'), ['locale' => 'id', 'theme' => 'dark']);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseHas('users', ['id' => $operator->id, 'locale' => 'en', 'theme' => 'light']);
        $response->assertCookieMissing('autoqa_locale')->assertCookieMissing('autoqa_theme');
    }

    public function test_saves_only_the_authenticated_user_preferences_and_issues_encrypted_private_year_long_cookies(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-13 10:00:00 UTC'));
        $expectedExpiry = (new DateTimeImmutable('2027-10-13 10:00:00 UTC'))->getTimestamp();
        $operator = User::factory()->create();
        $foreign = User::factory()->create();
        $originalName = $operator->name;
        $originalEmail = $operator->email;

        $response = $this->actingAs($operator)->post(route('settings.preferences'), [
            'locale' => 'id', 'theme' => 'dark', 'user_id' => $foreign->id,
            'name' => 'Unexpected replacement', 'email' => 'unexpected@example.test',
        ]);

        $response->assertRedirect(route('dashboard', ['panel' => 'settings']))
            ->assertSessionHas('preferences_saved', true)
            ->assertCookie('autoqa_locale', 'id')->assertCookie('autoqa_theme', 'dark');
        $this->assertDatabaseHas('users', [
            'id' => $operator->id, 'locale' => 'id', 'theme' => 'dark',
            'name' => $originalName, 'email' => $originalEmail,
        ]);
        $this->assertDatabaseHas('users', ['id' => $foreign->id, 'locale' => 'en', 'theme' => 'light']);
        foreach (['autoqa_locale' => 'id', 'autoqa_theme' => 'dark'] as $name => $value) {
            $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $name);
            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertNotSame($value, $cookie->getValue());
            $this->assertSame($expectedExpiry, $cookie->getExpiresTime());
        }
    }

    #[DataProvider('invalidPreferences')]
    public function test_rejects_missing_unsupported_or_non_scalar_preferences_without_mutating_the_user(array $input, array $errors): void
    {
        $operator = User::factory()->create();

        $response = $this->actingAs($operator)->from(route('dashboard', ['panel' => 'settings']))
            ->post(route('settings.preferences'), $input);

        $response->assertSessionHasErrors($errors)
            ->assertCookieMissing('autoqa_locale')->assertCookieMissing('autoqa_theme');
        $this->assertDatabaseHas('users', ['id' => $operator->id, 'locale' => 'en', 'theme' => 'light']);
    }

    public static function invalidPreferences(): array
    {
        return [
            'both values missing' => [[], ['locale', 'theme']],
            'locale missing' => [['theme' => 'dark'], ['locale']],
            'theme missing' => [['locale' => 'id'], ['theme']],
            'unsupported locale' => [['locale' => 'fr', 'theme' => 'dark'], ['locale']],
            'unsupported theme' => [['locale' => 'id', 'theme' => 'system'], ['theme']],
            'non scalar locale' => [['locale' => ['id'], 'theme' => 'dark'], ['locale']],
            'non scalar theme' => [['locale' => 'id', 'theme' => ['dark']], ['theme']],
        ];
    }

    public function test_saved_user_preferences_take_precedence_over_opposing_guest_cookies(): void
    {
        $operator = User::factory()->create(['locale' => 'id', 'theme' => 'dark']);

        $response = $this->actingAs($operator)->withCookies(['autoqa_locale' => 'en', 'autoqa_theme' => 'light'])
            ->get(route('dashboard', ['panel' => 'settings']));

        $response->assertOk()->assertSee('lang="id"', false)->assertSee('data-theme="dark"', false)
            ->assertSee('name="color-scheme" content="dark"', false)
            ->assertSee('Pengaturan')->assertSee('Bahasa')->assertSee('Tampilan')->assertSee('Terang')->assertSee('Gelap');
    }

    public function test_guest_auth_pages_use_valid_language_and_theme_cookies(): void
    {
        User::factory()->create();

        $response = $this->withCookies(['autoqa_locale' => 'id', 'autoqa_theme' => 'dark'])->get(route('login'));

        $response->assertOk()->assertSee('lang="id"', false)->assertSee('data-theme="dark"', false)
            ->assertSee('name="color-scheme" content="dark"', false)->assertSee('Masuk ke ruang kerja');
    }

    public function test_invalid_guest_cookies_fall_back_to_english_light_without_entering_html(): void
    {
        User::factory()->create();
        $unsafe = '"><script>alert("preferences")</script>';

        $response = $this->withCookies(['autoqa_locale' => $unsafe, 'autoqa_theme' => $unsafe])->get(route('login'));

        $response->assertOk()->assertSee('lang="en"', false)->assertSee('data-theme="light"', false)
            ->assertSee('Sign in to your workspace')->assertDontSee($unsafe, false);
    }

    public function test_indonesian_auth_validation_uses_the_localized_required_email_message(): void
    {
        User::factory()->create();

        $response = $this->withCookies(['autoqa_locale' => 'id', 'autoqa_theme' => 'dark'])
            ->from(route('login'))->post(route('login'), ['password' => 'not-a-real-password']);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Alamat email wajib diisi.']);
        $this->assertGuest();
    }

    public function test_the_settings_query_reopens_the_settings_panel_after_a_full_page_redirect(): void
    {
        $operator = User::factory()->create();

        $response = $this->actingAs($operator)->get(route('dashboard', ['panel' => 'settings']));

        $response->assertOk();
        $snapshot = json_decode($this->signedSnapshot($response->getContent()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('settings', $snapshot['data']['panel']);
        $this->assertStringContainsString('action="'.route('settings.preferences').'"', $response->getContent());
    }

    public function test_a_real_livewire_update_reapplies_the_saved_locale_over_an_old_english_snapshot(): void
    {
        $operator = User::factory()->create();
        $initial = $this->actingAs($operator)->get(route('dashboard'));
        $initial->assertOk();
        $snapshot = $this->signedSnapshot($initial->getContent());
        $this->assertSame('en', json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['locale']);
        $operator->forceFill(['locale' => 'id', 'theme' => 'dark'])->save();

        $response = $this->actingAs($operator->fresh())->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot, 'updates' => [],
                'calls' => [['method' => 'setPanel', 'params' => ['settings']]],
            ]],
        ], ['X-Livewire' => 'true']);

        $response->assertOk();
        $updated = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('id', $updated['memo']['locale']);
        $this->assertSame('settings', $updated['data']['panel']);
        $this->assertStringContainsString('Pengaturan', $response->json('components.0.effects.html'));
        $this->assertStringContainsString('Bahasa', $response->json('components.0.effects.html'));
    }

    public function test_indonesian_interface_preserves_user_project_names_and_original_model_text(): void
    {
        $operator = User::factory()->create(['locale' => 'id', 'theme' => 'light']);
        $project = Project::factory()->for($operator)->create(['name' => 'Settings']);
        QaRun::factory()->for($project)->create([
            'status' => 'completed', 'report' => ['summary' => 'Sign in to your workspace'],
        ]);

        $response = $this->actingAs($operator)->get(route('dashboard'));

        $response->assertOk()->assertSee('Pengaturan')->assertSee('Settings')->assertSee('Sign in to your workspace');
    }

    private function signedSnapshot(string $html): string
    {
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
