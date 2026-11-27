<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

class PasswordResetCodes
{
    public const EXPIRY_MINUTES = 10;

    public const SESSION_KEY = 'password_reset_challenge';

    private const MAX_ATTEMPTS = 5;

    public function __construct(private Encrypter $encrypter) {}

    /** @return array{email: string, id: string, expires_at: int}|null */
    public static function pendingChallenge(Session $session): ?array
    {
        $challenge = $session->get(self::SESSION_KEY);

        return is_array($challenge) && is_string($challenge['email'] ?? null) && is_string($challenge['id'] ?? null)
            && is_int($challenge['expires_at'] ?? null) && $challenge['expires_at'] > now()->timestamp ? $challenge : null;
    }

    public function issue(string $email, string $requestId): ?string
    {
        return DB::transaction(function () use ($email, $requestId): ?string {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                return null;
            }

            $previousId = DB::table('password_reset_codes')->where('user_id', $user->getKey())->value('request_id');
            if ($previousId !== null && strcmp($previousId, $requestId) >= 0) {
                return null;
            }

            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            DB::table('password_reset_codes')->updateOrInsert(['user_id' => $user->getKey()], [
                'request_id' => $requestId,
                'code_digest' => $this->digest($email, $requestId, $code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);

            return $code;
        }, attempts: 3);
    }

    public function consume(string $email, string $requestId, #[SensitiveParameter] string $code, #[SensitiveParameter] string $password): bool
    {
        $resetAccount = DB::transaction(function () use ($email, $requestId, $code, $password): ?User {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                return null;
            }

            $challenge = DB::table('password_reset_codes')
                ->where('user_id', $user->getKey())->where('request_id', $requestId)
                ->where('expires_at', '>', now())->whereNotNull('code_digest');
            $reset = $challenge->first(['attempts', 'code_digest']);
            if ($reset === null) {
                return null;
            }

            if (! hash_equals($reset->code_digest, $this->digest($email, $requestId, $code))) {
                $attempts = $reset->attempts + 1;
                $challenge->update([
                    'attempts' => $attempts,
                    'code_digest' => $attempts >= self::MAX_ATTEMPTS ? null : $reset->code_digest,
                ]);

                return null;
            }

            if ($challenge->update(['code_digest' => null]) !== 1) {
                return null;
            }
            $broker = Password::broker();
            $status = $broker->reset([
                'email' => $user->email,
                'token' => $broker->createToken($user),
                'password' => $password,
            ], function (User $account, #[SensitiveParameter] string $replacement): void {
                if (! $account->forceFill(['password' => Hash::make($replacement), 'remember_token' => Str::random(60)])->save()) {
                    throw new RuntimeException('Unable to update the account password.');
                }
            });
            if ($status !== Password::PASSWORD_RESET) {
                throw new RuntimeException('The password broker could not complete the reset.');
            }

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))
                    ->where('user_id', $user->getKey())->delete();
            }

            return $user->refresh();
        }, attempts: 3);

        if ($resetAccount === null) {
            return false;
        }

        event(new PasswordReset($resetAccount));

        return true;
    }

    public function invalidate(string $email, string $requestId): void
    {
        DB::table('password_reset_codes')
            ->whereIn('user_id', User::query()->select('id')->where('email', $email))
            ->where('request_id', $requestId)->update(['code_digest' => null]);
    }

    private function digest(string $email, string $requestId, #[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', implode("\0", ['auto-qa/password-reset', $email, $requestId, $code]), $this->encrypter->getKey());
    }
}
