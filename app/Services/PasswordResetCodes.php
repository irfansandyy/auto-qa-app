<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

class PasswordResetCodes
{
    public const EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private Encrypter $encrypter) {}

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
        return DB::transaction(function () use ($email, $requestId, $code, $password): bool {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                return false;
            }

            $challenge = DB::table('password_reset_codes')
                ->where('user_id', $user->getKey())->where('request_id', $requestId)
                ->where('expires_at', '>', now())->whereNotNull('code_digest');
            $reset = $challenge->first(['attempts', 'code_digest']);
            if ($reset === null) {
                return false;
            }

            if (! hash_equals($reset->code_digest, $this->digest($email, $requestId, $code))) {
                $attempts = $reset->attempts + 1;
                $challenge->update([
                    'attempts' => $attempts,
                    'code_digest' => $attempts >= self::MAX_ATTEMPTS ? null : $reset->code_digest,
                ]);

                return false;
            }

            if ($challenge->update(['code_digest' => null]) !== 1) {
                return false;
            }
            if (! $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save()) {
                throw new RuntimeException('Unable to update the account password.');
            }

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))
                    ->where('user_id', $user->getKey())->delete();
            }

            return true;
        }, attempts: 3);
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
