<?php

namespace App\Jobs;

use App\Mail\PasswordResetCode;
use App\Services\PasswordResetCodes;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendPasswordResetCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const MAX_QUEUE_MINUTES = 2;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(
        #[\SensitiveParameter] public string $email,
        public string $requestId,
        public string $locale,
        public int $deliverBefore,
    ) {
        $this->onQueue('mail');
    }

    public static function mailReady(): bool
    {
        $mailer = config('mail.mailers.'.config('mail.default'), []);

        return ($mailer['transport'] ?? null) === 'smtp'
            && filled($mailer['host'] ?? null)
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function handle(PasswordResetCodes $codes): void
    {
        if ($this->deliverBefore <= now()->timestamp || ! self::mailReady()) {
            return;
        }
        $code = $codes->issue($this->email, $this->requestId);
        if ($code === null) {
            return;
        }

        try {
            Mail::to($this->email)->locale($this->locale)->send(new PasswordResetCode($code));
        } catch (Throwable) {
            $codes->invalidate($this->email, $this->requestId);

            throw new RuntimeException('Password reset email delivery failed. Check the configured SMTP transport.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PasswordResetCodes::class)->invalidate($this->email, $this->requestId);
    }
}
