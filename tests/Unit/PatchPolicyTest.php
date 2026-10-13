<?php

namespace Tests\Unit;

use App\Services\PatchPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PatchPolicyTest extends TestCase
{
    private const APPLICATION = 'app/Http/Controllers/RegistrationController.php';

    private const REGRESSION = 'tests/Feature/RegistrationRegressionTest.php';

    public function test_accepts_an_existing_application_change_and_returns_the_new_regression_path(): void
    {
        $files = [self::APPLICATION => '<?php // repaired', self::REGRESSION => '<?php // new regression'];

        $test = (new PatchPolicy)->validate($files, [self::APPLICATION => 'original']);

        $this->assertSame(self::REGRESSION, $test);
    }

    #[DataProvider('unsafeRepairs')]
    public function test_rejects_repairs_outside_the_bounded_application_and_new_regression_scope(array $files, array $original): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PatchPolicy)->validate($files, $original);
    }

    public static function unsafeRepairs(): array
    {
        $source = self::APPLICATION;
        $test = self::REGRESSION;
        $original = [$source => 'original', 'tests/Feature/ExistingTest.php' => 'original'];

        return [
            'empty patch' => [[], $original],
            'source without a regression' => [[$source => 'fixed'], $original],
            'regression without an application change' => [[$test => 'regression'], $original],
            'path traversal' => [[$source => 'fixed', 'tests/Feature/../../secret.php' => 'regression'], $original],
            'config mutation' => [['config/app.php' => 'fixed', $test => 'regression'], $original],
            'workflow mutation' => [['.github/workflows/ci.yml' => 'fixed', $test => 'regression'], $original],
            'existing test replacement' => [[$source => 'fixed', 'tests/Feature/ExistingTest.php' => 'regression'], $original],
            'multiple new tests' => [[$source => 'fixed', $test => 'first', 'tests/Feature/AnotherTest.php' => 'second'], $original],
            'new test without Test suffix' => [[$source => 'fixed', 'tests/Feature/registration.php' => 'regression'], $original],
            'new application source' => [['app/NewController.php' => 'fixed', $test => 'regression'], $original],
            'patch above 64 KB' => [[$source => str_repeat('x', 65536), $test => 'regression'], $original],
            'too many files' => [[
                $source => 'fixed', 'app/Two.php' => 'fixed', 'app/Three.php' => 'fixed',
                'app/Four.php' => 'fixed', 'app/Five.php' => 'fixed', $test => 'regression',
            ], array_fill_keys([$source, 'app/Two.php', 'app/Three.php', 'app/Four.php', 'app/Five.php'], 'original')],
            'non-string file contents' => [[$source => ['code'], $test => 'regression'], $original],
            'double slash path' => [[$source => 'fixed', 'tests/Feature//RegistrationTest.php' => 'regression'], $original],
        ];
    }
}
