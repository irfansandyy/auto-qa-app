<?php

namespace Tests\Unit;

use App\Services\GitHub\RepositoryUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubRepositoryUrlTest extends TestCase
{
    public function test_normalizes_a_repository_without_accepting_a_file_or_branch_url(): void
    {
        $this->assertSame(['owner' => 'student', 'name' => 'demo', 'full_name' => 'student/demo'], RepositoryUrl::parse('https://github.com/student/demo.git/'));
        $this->assertSame('https://github.com/student/demo', RepositoryUrl::normalize('https://github.com/student/demo.git'));
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects_urls_outside_one_github_repository(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        RepositoryUrl::parse($url);
    }

    public static function invalidUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://github.com/student/demo', 'https://github.com.evil.example/student/demo',
            'https://user:token@github.com/student/demo', 'https://github.com:443/student/demo',
            'https://github.com/student/demo?token=secret', 'https://github.com/student/demo#main',
            'https://github.com/student/demo/tree/main', 'https://github.com/student/..',
            'https://github.com/student/demo%2fother', 'git@github.com:student/demo.git',
        ]);
    }
}
