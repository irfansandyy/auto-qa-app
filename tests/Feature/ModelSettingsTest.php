<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModelEndpointPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModelSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_model_settings_require_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post(route('settings.model'), $this->cloudSettings())->assertRedirect(route('login'));

        $this->assertSame('local', $user->fresh()->model_provider);
        $this->assertNull($user->fresh()->model_api_key);
    }

    public function test_saves_only_current_user_settings_encrypts_key_and_never_renders_it(): void
    {
        $user = User::factory()->create(['theme' => 'light']);
        $other = User::factory()->create();
        $originalName = $user->name;

        $this->actingAs($user)->post(route('settings.model'), $this->cloudSettings() + [
            'user_id' => $other->id, 'name' => 'Injected name', 'theme' => 'dark',
        ])->assertRedirect(route('dashboard', ['panel' => 'settings']))->assertSessionHas('model_settings_saved', true);

        $user->refresh();
        $this->assertSame('openai', $user->model_provider);
        $this->assertSame('test-model', $user->model_name);
        $this->assertSame('private-api-key', $user->model_api_key);
        $stored = DB::table('users')->where('id', $user->id)->value('model_api_key');
        $this->assertNotSame('private-api-key', $stored);
        $this->assertStringNotContainsString('private-api-key', $stored);
        $this->assertArrayNotHasKey('model_api_key', $user->toArray());
        $this->assertSame($originalName, $user->name);
        $this->assertSame('light', $user->theme);
        $this->assertSame('local', $other->fresh()->model_provider);
        $this->assertNull($other->fresh()->model_api_key);

        $response = $this->actingAs($user)->get(route('dashboard', ['panel' => 'settings']));
        $response->assertOk()->assertSee('An API key is saved.')->assertDontSee('private-api-key', false);
        $this->assertMatchesRegularExpression('/id="model_api_key"[^>]*type="password"/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/id="model_api_key"[^>]*value=/', $response->getContent());
    }

    public function test_blank_key_keeps_current_key_and_explicit_removal_clears_it(): void
    {
        $user = User::factory()->create($this->cloudSettings());

        $this->actingAs($user)->post(route('settings.model'), [
            'model_provider' => 'openai', 'model_name' => 'different-model', 'model_api_key' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('private-api-key', $user->fresh()->model_api_key);
        $this->assertSame('different-model', $user->fresh()->model_name);

        $this->actingAs($user->fresh())->post(route('settings.model'), [
            'model_provider' => 'openai', 'model_name' => 'different-model', 'forget_api_key' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->model_api_key);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'model_api_key' => null]);
    }

    public function test_changing_provider_requires_a_new_key_instead_of_reusing_another_providers_credentials(): void
    {
        $user = User::factory()->create($this->cloudSettings());

        $this->actingAs($user)->post(route('settings.model'), [
            'model_provider' => 'anthropic', 'model_name' => 'test-claude',
        ])->assertSessionHasErrors(['model_api_key' => __('model-settings.key_required')]);

        $this->assertSame('openai', $user->fresh()->model_provider);
        $this->assertSame('private-api-key', $user->fresh()->model_api_key);
    }

    public function test_custom_base_url_keeps_gateway_path_and_requires_a_new_key_when_address_changes(): void
    {
        $user = User::factory()->create();
        $this->mock(ModelEndpointPolicy::class, function ($mock): void {
            $mock->shouldReceive('requestOptions')->with('https://gateway.example.com/llm-gateway/v1')->once()->andReturn([]);
            $mock->shouldReceive('requestOptions')->with('https://other.example.com/v1')->once()->andReturn([]);
        });

        $this->actingAs($user)->post(route('settings.model'), [
            'model_provider' => 'custom', 'model_name' => 'qwen3:14b',
            'model_base_url' => 'https://gateway.example.com/llm-gateway/v1/', 'model_api_key' => 'gateway-secret',
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://gateway.example.com/llm-gateway/v1', $user->fresh()->model_base_url);
        $this->assertSame('gateway-secret', $user->fresh()->model_api_key);

        $this->actingAs($user->fresh())->post(route('settings.model'), [
            'model_provider' => 'custom', 'model_name' => 'qwen3:14b', 'model_base_url' => 'https://other.example.com/v1',
        ])->assertSessionHasErrors(['model_api_key' => __('model-settings.key_required')]);

        $this->assertSame('https://gateway.example.com/llm-gateway/v1', $user->fresh()->model_base_url);
    }

    public function test_returning_to_server_model_removes_personal_credentials_and_overrides(): void
    {
        $user = User::factory()->create($this->cloudSettings());

        $this->actingAs($user)->post(route('settings.model'), ['model_provider' => 'local'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $user->id, 'model_provider' => 'local', 'model_name' => null, 'model_base_url' => null, 'model_api_key' => null,
        ]);
    }

    #[DataProvider('invalidSettings')]
    public function test_rejects_invalid_settings_without_flashing_the_api_key(array $input, string $field): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from(route('dashboard', ['panel' => 'settings']))->post(route('settings.model'), [
            ...$this->cloudSettings(), ...$input,
        ]);

        $response->assertRedirect(route('dashboard', ['panel' => 'settings']))->assertSessionHasErrors($field);
        $this->assertArrayNotHasKey('model_api_key', session('_old_input', []));
        $this->assertStringNotContainsString('private-api-key', json_encode(session()->all()));
        $this->assertSame('local', $user->fresh()->model_provider);
        $this->assertNull($user->fresh()->model_api_key);
    }

    public static function invalidSettings(): array
    {
        return [
            'unknown provider' => [['model_provider' => 'unsupported'], 'model_provider'],
            'non-string provider' => [['model_provider' => ['openai']], 'model_provider'],
            'model missing' => [['model_name' => null], 'model_name'],
            'invalid model path characters' => [['model_name' => 'model?key=secret'], 'model_name'],
            'overlong model' => [['model_name' => str_repeat('a', 151)], 'model_name'],
            'missing custom URL' => [['model_provider' => 'custom'], 'model_base_url'],
            'HTTP custom URL' => [['model_provider' => 'custom', 'model_base_url' => 'http://example.com'], 'model_base_url'],
            'private custom URL' => [['model_provider' => 'custom', 'model_base_url' => 'https://127.0.0.1/v1'], 'model_base_url'],
            'invalid remove value' => [['forget_api_key' => 'anything'], 'forget_api_key'],
            'non-string key' => [['model_api_key' => ['private-api-key']], 'model_api_key'],
            'key contains spaces' => [['model_api_key' => 'private-api-key bad'], 'model_api_key'],
            'overlong key' => [['model_api_key' => str_repeat('a', 4097)], 'model_api_key'],
        ];
    }

    public function test_indonesian_model_settings_are_rendered_and_saved_values_are_escaped(): void
    {
        $unsafe = '"><script>alert("model")</script>';
        $user = User::factory()->create(['locale' => 'id', 'model_provider' => 'openai', 'model_name' => $unsafe, 'model_api_key' => 'private-api-key']);

        $this->actingAs($user)->get(route('dashboard', ['panel' => 'settings']))->assertOk()
            ->assertSee('Koneksi model')->assertSee('Kunci API')->assertSee('Simpan koneksi model')
            ->assertSee('&lt;script&gt;', false)->assertDontSee($unsafe, false)->assertDontSee('private-api-key', false);
    }

    private function cloudSettings(): array
    {
        return ['model_provider' => 'openai', 'model_name' => 'test-model', 'model_api_key' => 'private-api-key'];
    }
}
