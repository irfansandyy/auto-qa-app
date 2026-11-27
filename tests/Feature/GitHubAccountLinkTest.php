<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use App\Services\RepositoryAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubAccountLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REPOSITORY = 'https://github.com/owner/qa-demo.git';

    private const TOKEN_ENDPOINT = 'https://github.com/login/oauth/access_token';

    private const INSTALLATION_REPOSITORIES = 'https://api.github.com/user/installations/42/repositories*';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['github.client_id' => 'example-client', 'github.client_secret' => 'test-secret',
            'repository-access.grants' => [], 'repository-access.write_grants' => [],
        ]);
    }

    public function test_owner_can_link_and_disconnect_with_an_encrypted_user_token(): void
    {
        config(['app.url' => 'https://qa-app.example.test/']);
        Http::fake([
            self::TOKEN_ENDPOINT => Http::response(['access_token' => 'test-user-token', 'refresh_token' => 'test-refresh-token', 'expires_in' => 28800]),
            'https://api.github.com/user' => Http::response(['id' => 456, 'login' => 'linked-user']),
        ]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('settings.github.connect'));
        $response->assertRedirectContains('https://github.com/login/oauth/authorize?');
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $authorization);
        $this->assertSame('S256', $authorization['code_challenge_method']);
        $this->assertSame('https://qa-app.example.test/settings/github/callback', $authorization['redirect_uri']);
        $callback = route('settings.github.callback', ['state' => $authorization['state'], 'code' => 'example-code']);
        $this->get($callback)->assertRedirect(route('dashboard', ['panel' => 'settings']))->assertSessionHas('githubStatus');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::TOKEN_ENDPOINT
            && $request['code'] === 'example-code' && $request['redirect_uri'] === $authorization['redirect_uri']
            && rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=') === $authorization['code_challenge']);
        $linked = $user->fresh();
        $this->assertSame(456, $linked->github_id);
        $this->assertSame('linked-user', $linked->github_login);
        $this->assertSame('test-user-token', $linked->github_credentials['token']);
        $this->assertStringNotContainsString('test-user-token', $linked->getRawOriginal('github_credentials'));
        $this->assertArrayNotHasKey('github_credentials', $linked->toArray());

        $this->get($callback)->assertSessionHasErrors('github');
        Http::assertSentCount(2);

        $this->delete(route('settings.github.disconnect'))->assertSessionHas('githubStatus');
        $this->assertNull($user->fresh()->github_credentials);
    }

    public function test_a_missing_public_base_url_keeps_the_user_in_settings_with_an_actionable_error(): void
    {
        config(['app.url' => '/invalid-base-url']);
        Http::fake();

        $this->actingAs(User::factory()->create())->post(route('settings.github.connect', absolute: false))
            ->assertRedirect(route('dashboard', ['panel' => 'settings']))
            ->assertSessionHasErrors(['github' => __('repository-access.callback_unconfigured')])
            ->assertSessionMissing('github_link');

        Http::assertNothingSent();
    }

    public function test_callback_does_not_link_another_auto_qa_user_or_accept_an_installation_id_as_proof(): void
    {
        Http::fake();
        [$user, $unrelated] = User::factory()->count(2)->create();
        $this->actingAs($user)->post(route('settings.github.connect'));
        $state = session('github_link.state');
        $this->actingAs($unrelated)->get(route('settings.github.callback', ['state' => $state, 'code' => 'example-code', 'installation_id' => 42]))
            ->assertSessionHasErrors('github');
        $this->assertNull($unrelated->fresh()->github_id);
        $this->assertNull($user->fresh()->github_id);
        Http::assertNothingSent();
    }

    public function test_github_rights_are_checked_for_the_same_installation_and_do_not_promote_readers_to_writers(): void
    {
        $linked = User::factory()->create();
        $linked->forceFill(['github_id' => 456, 'github_login' => 'linked-user',
            'github_credentials' => ['token' => 'test-user-token', 'refresh' => null, 'expires_at' => null],
        ])->save();
        $this->mock(GitHubAppClient::class)->shouldReceive('installationIdForRepository')->times(4)->with(self::REPOSITORY)->andReturn(42);
        Http::fake([self::INSTALLATION_REPOSITORIES => Http::sequence()
            ->push(['repositories' => [['full_name' => 'owner/qa-demo', 'permissions' => ['pull' => true, 'push' => false]]]])
            ->push(['repositories' => [['full_name' => 'owner/qa-demo', 'permissions' => ['pull' => true, 'push' => false]]]])
            ->push(['repositories' => [['full_name' => 'OWNER/QA-DEMO', 'permissions' => ['pull' => true, 'push' => true]]]])
            ->push(['repositories' => [['full_name' => 'other/qa-demo', 'permissions' => ['pull' => true, 'push' => true]]]])]);

        $access = app(RepositoryAccess::class);
        $this->assertTrue($access->canView($linked, self::REPOSITORY));
        $this->assertFalse($access->canWrite($linked, self::REPOSITORY));
        $this->assertTrue($access->canWrite($linked, self::REPOSITORY));
        $this->assertFalse($access->canView($linked, self::REPOSITORY));
        $this->assertFalse($access->canView(User::factory()->create(), self::REPOSITORY));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-user-token'));
    }

    public function test_an_expiring_token_is_rotated_before_checking_repository_access(): void
    {
        $linked = User::factory()->create();
        $linked->forceFill(['github_id' => 456, 'github_login' => 'linked-user', 'github_credentials' => [
            'token' => 'expired-test-token', 'refresh' => 'original-test-refresh', 'expires_at' => now()->subMinute()->getTimestamp(),
        ]])->save();
        $this->mock(GitHubAppClient::class)->shouldReceive('installationIdForRepository')->once()->with(self::REPOSITORY)->andReturn(42);
        Http::fake([
            self::TOKEN_ENDPOINT => Http::response(['access_token' => 'rotated-token', 'refresh_token' => 'rotated-refresh', 'expires_in' => 28800]),
            self::INSTALLATION_REPOSITORIES => Http::response(['repositories' => [['full_name' => 'owner/qa-demo', 'permissions' => ['pull' => true]]]]),
        ]);

        $this->assertTrue(app(RepositoryAccess::class)->canView($linked, self::REPOSITORY));
        $this->assertSame('rotated-refresh', $linked->fresh()->github_credentials['refresh']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::TOKEN_ENDPOINT && $request['refresh_token'] === 'original-test-refresh');
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer rotated-token'));
    }
}
