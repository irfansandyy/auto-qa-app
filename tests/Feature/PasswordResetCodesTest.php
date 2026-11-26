<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasswordResetCodes;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PasswordResetCodesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FIRST_REQUEST = '01JHMXW2G0TX4VA2E2R3SGBDN3';

    private const SECOND_REQUEST = '01JHMXW2G1TX4VA2E2R3SGBDN3';

    private const NEW_PASSWORD = 'A new password';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_a_code_resets_once_and_revokes_previous_database_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $oldRememberToken = $user->getRememberToken();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            ['id' => 'previous-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'other-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $resets = app(PasswordResetCodes::class);
        $code = $resets->issue($user->email, self::FIRST_REQUEST);
        $digest = DB::table('password_reset_codes')->where('user_id', $user->id)->value('code_digest');
        $this->assertIsString($code);
        $this->assertTrue(preg_match('/\A[0-9]{6}\z/', $code) === 1);
        $this->assertTrue(is_string($digest) && strlen($digest) === 64 && $digest !== $code);
        $this->assertFalse($resets->consume($other->email, self::FIRST_REQUEST, $code, self::NEW_PASSWORD));

        $this->assertTrue($resets->consume($user->email, self::FIRST_REQUEST, $code, self::NEW_PASSWORD));

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertTrue($user->getRememberToken() !== $oldRememberToken);
        $this->assertDatabaseMissing('sessions', ['id' => 'previous-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
        $this->assertFalse($resets->consume($user->email, self::FIRST_REQUEST, $code, 'Another password'));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_a_code_expires_at_ten_minutes(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $resets = app(PasswordResetCodes::class);
        $code = $resets->issue($user->email, self::FIRST_REQUEST);
        $this->assertIsString($code);

        $this->travel(10)->minutes();

        $this->assertFalse($resets->consume($user->email, self::FIRST_REQUEST, $code, self::NEW_PASSWORD));
        $this->assertTrue($user->password === $user->fresh()->password);
    }

    #[TestWith([4, true])]
    #[TestWith([5, false])]
    public function test_a_correct_code_works_only_before_five_failed_attempts(int $failedAttempts, bool $expectedResult): void
    {
        $user = User::factory()->create();
        $resets = app(PasswordResetCodes::class);
        $code = $resets->issue($user->email, self::FIRST_REQUEST);
        $this->assertIsString($code);
        $incorrectCode = $code === '000000' ? '999999' : '000000';
        for ($attempt = 0; $attempt < $failedAttempts; $attempt++) {
            $this->assertFalse($resets->consume($user->email, self::FIRST_REQUEST, $incorrectCode, self::NEW_PASSWORD));
        }

        $this->assertSame($expectedResult, $resets->consume($user->email, self::FIRST_REQUEST, $code, self::NEW_PASSWORD));

        $this->assertSame($expectedResult, Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_a_new_request_replaces_an_older_code_and_rejects_old_mail_jobs(): void
    {
        $user = User::factory()->create();
        $resets = app(PasswordResetCodes::class);
        $olderCode = $resets->issue($user->email, self::FIRST_REQUEST);
        $newerCode = $resets->issue($user->email, self::SECOND_REQUEST);
        $this->assertIsString($olderCode);
        $this->assertIsString($newerCode);

        $resets->invalidate($user->email, self::FIRST_REQUEST);

        $this->assertFalse($resets->consume($user->email, self::FIRST_REQUEST, $olderCode, self::NEW_PASSWORD));
        $this->assertTrue($resets->consume($user->email, self::SECOND_REQUEST, $newerCode, self::NEW_PASSWORD));
        $this->assertNull($resets->issue($user->email, self::SECOND_REQUEST));
        $this->assertNull($resets->issue($user->email, self::FIRST_REQUEST));
    }

    public function test_invalidating_current_code_prevents_redemption(): void
    {
        $user = User::factory()->create();
        $resets = app(PasswordResetCodes::class);
        $code = $resets->issue($user->email, self::FIRST_REQUEST);
        $this->assertIsString($code);

        $resets->invalidate($user->email, self::FIRST_REQUEST);

        $this->assertFalse($resets->consume($user->email, self::FIRST_REQUEST, $code, self::NEW_PASSWORD));
        $this->assertTrue($user->password === $user->fresh()->password);
    }

    public function test_an_unknown_account_does_not_receive_a_code(): void
    {
        $code = app(PasswordResetCodes::class)->issue('unknown@example.test', self::FIRST_REQUEST);

        $this->assertNull($code);
        $this->assertDatabaseEmpty('password_reset_codes');
    }
}
