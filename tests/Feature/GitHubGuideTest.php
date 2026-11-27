<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GitHubGuideTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_can_read_the_installation_guide_and_open_its_images(): void
    {
        config([
            'github.app_slug' => 'example-qa',
            'github.private_key_path' => '/private/example-qa.pem',
        ]);

        $response = $this->get(route('docs.github-app'))
            ->assertOk()
            ->assertSee('Connect a GitHub repository')
            ->assertSee('Only select repositories')
            ->assertSee('https://github.com/apps/example-qa/installations/new')
            ->assertDontSee('/private/example-qa.pem');

        foreach ([
            '01-autoqa-github-settings.jpg',
            '02-github-account-chooser.jpg',
            '03-github-repository-url.jpg',
            '04-autoqa-new-project.jpg',
        ] as $image) {
            $path = 'images/docs/github-app/'.$image;

            $response->assertSee('src="'.asset($path).'"', false);
            $this->assertFileExists(public_path($path));
        }
    }

    public function test_the_guide_does_not_link_to_installation_without_a_configured_app(): void
    {
        config(['github.app_slug' => '']);

        $this->get(route('docs.github-app'))
            ->assertOk()
            ->assertSee('The Install on GitHub button is missing')
            ->assertDontSee('/installations/new');
    }

    public function test_the_settings_link_opens_the_guide_with_the_users_preferences(): void
    {
        $user = User::factory()->create(['locale' => 'id', 'theme' => 'dark']);

        $this->actingAs($user)->get(route('dashboard', ['panel' => 'settings']))
            ->assertOk()
            ->assertSee('href="'.route('docs.github-app').'"', false)
            ->assertSee('Bantuan')
            ->assertSee('Panduan GitHub App');

        $this->get(route('docs.github-app'))
            ->assertOk()
            ->assertSee('lang="id"', false)
            ->assertSee('data-theme="dark"', false)
            ->assertSee('Hubungkan repositori GitHub')
            ->assertSee('Buka ruang kerja');
    }
}
