<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RepositoryAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('repository-access.grants', []);
        Http::preventStrayRequests();
    }

    public function test_denies_repository_access_when_no_server_grant_exists(): void
    {
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage(__('repository-access.denied'));

        app(RepositoryAccess::class)->assertAllowed($user, 'https://github.com/example/private-repository');
    }

    public function test_allows_only_the_canonical_repository_explicitly_granted_to_that_user(): void
    {
        $user = User::factory()->create();
        config()->set('repository-access.grants', [$user->id => ['Example/Repository']]);

        app(RepositoryAccess::class)->assertAllowed($user, 'https://github.com/EXAMPLE/repository.git/');

        Http::assertNothingSent();
    }

    public function test_does_not_inherit_another_users_repository_grant(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config()->set('repository-access.grants', [$owner->id => ['example/private-repository']]);

        $this->expectException(AuthorizationException::class);

        app(RepositoryAccess::class)->assertAllowed($other, 'https://github.com/example/private-repository');
    }

    public function test_granted_user_cannot_access_a_different_repository_under_the_same_github_owner(): void
    {
        $user = User::factory()->create();
        config()->set('repository-access.grants', [$user->id => ['example/approved-repository']]);

        $this->expectException(AuthorizationException::class);

        app(RepositoryAccess::class)->assertAllowed($user, 'https://github.com/example/private-repository');
    }

    public function test_a_json_list_is_not_treated_as_a_user_id_grant_mapping(): void
    {
        $user = User::factory()->create();
        config()->set('repository-access.grants', array_fill(0, $user->id + 1, ['example/private-repository']));

        $this->expectException(AuthorizationException::class);

        app(RepositoryAccess::class)->assertAllowed($user, 'https://github.com/example/private-repository');
    }

    #[DataProvider('invalidGrants')]
    public function test_malformed_grants_never_authorize_repository_access(mixed $grant): void
    {
        $user = User::factory()->create();
        config()->set('repository-access.grants', [$user->id => $grant]);

        $this->expectException(AuthorizationException::class);

        app(RepositoryAccess::class)->assertAllowed($user, 'https://github.com/example/private-repository');
    }

    public static function invalidGrants(): array
    {
        return [
            'wildcard' => [['*']],
            'owner wildcard' => [['example/*']],
            'URL instead of repository name' => [['https://github.com/example/private-repository']],
            'wrong value shape' => ['example/private-repository'],
            'map instead of list' => [['example/private-repository' => true]],
            'boolean grant' => [true],
            'nested grant' => [[['example/private-repository']]],
        ];
    }

    public function test_invalid_repository_urls_are_denied_without_contacting_github(): void
    {
        $user = User::factory()->create();
        config()->set('repository-access.grants', [$user->id => ['example/private-repository']]);

        $this->expectException(AuthorizationException::class);

        app(RepositoryAccess::class)->assertAllowed($user, 'https://github.com/example/private-repository?access=other');
    }

    public function test_revoking_a_grant_takes_effect_on_the_next_access_check(): void
    {
        $user = User::factory()->create();
        config()->set('repository-access.grants', [$user->id => ['example/private-repository']]);
        $access = app(RepositoryAccess::class);
        $access->assertAllowed($user, 'https://github.com/example/private-repository');
        config()->set('repository-access.grants', []);

        $this->expectException(AuthorizationException::class);

        $access->assertAllowed($user, 'https://github.com/example/private-repository');
    }

    public function test_denial_message_supports_bahasa_indonesia(): void
    {
        $user = User::factory()->create();
        app()->setLocale('id');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Akun Anda belum diberi akses ke repositori ini.');

        app(RepositoryAccess::class)->assertAllowed($user, 'https://github.com/example/private-repository');
    }
}
