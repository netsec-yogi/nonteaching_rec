<?php

namespace App\Mail\Pool;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * What a failed sending attempt means: for the account (cool it down?), for this email (try
 * another account, retry later, or give up) and for duplicates (could the server have accepted it?).
 */
final class SmtpFailure
{
    /** The server could not be reached or dropped the connection before the message was complete. */
    public const CONNECTION = 'connection';

    /** The account is being throttled (e.g. 421 4.7.0, too many logins). */
    public const RATE_LIMITED = 'rate_limited';

    /** The account's sending quota is used up (e.g. Gmail 5.4.5). */
    public const QUOTA = 'quota';

    /** Throttling of the sending domain or IP (e.g. Gmail 4.7.28): shared by every account on it. */
    public const DOMAIN_RATE_LIMITED = 'domain_rate_limited';

    /** Login rejected. Not a network failure: the account stays out until it is fixed or the cooldown ends. */
    public const AUTH = 'auth';

    /** The connection could not be secured as configured (e.g. TLS required but not offered). */
    public const CONFIG = 'config';

    /** The server refused the From address for this login (relay denied, not owned by user). */
    public const SENDER_REJECTED = 'sender_rejected';

    /** The recipient address was refused. No account will do better. */
    public const RECIPIENT = 'recipient';

    /** The message itself was refused (size, content policy). */
    public const MESSAGE = 'message';

    /** A temporary refusal tied to this message or recipient: retry later on any account. */
    public const DEFERRED = 'deferred';

    /** The connection failed after the whole message was sent: the server may have accepted it. */
    public const AMBIGUOUS = 'ambiguous';

    /** Not an SMTP failure (e.g. an attachment could not be read). */
    public const OTHER = 'other';

    private const ACCOUNT_PROBLEMS = [self::CONNECTION, self::RATE_LIMITED, self::QUOTA, self::DOMAIN_RATE_LIMITED,
        self::AUTH, self::CONFIG, self::SENDER_REJECTED, self::AMBIGUOUS];

    private const FAILOVER = [self::CONNECTION, self::RATE_LIMITED, self::QUOTA, self::DOMAIN_RATE_LIMITED,
        self::AUTH, self::CONFIG, self::SENDER_REJECTED];

    private function __construct(
        public readonly string $type,
        public readonly ?int $code,
        public readonly string $message,
        public readonly ?string $stage,
    ) {}

    public static function classify(Throwable $e, MailSecrets $secrets): self
    {
        $message = (string) $secrets->redact($e->getMessage() ?: $e::class);
        if (! $e instanceof TransportExceptionInterface) {
            return new self(self::OTHER, null, $message, null);
        }

        $code = (int) $e->getCode() ?: null;
        $stage = $e instanceof SmtpDeliveryException ? $e->stage : null;

        return new self(self::typeOf($code, $stage, $e->getMessage()), $code, $message, $stage);
    }

    private static function typeOf(?int $code, ?string $stage, string $text): string
    {
        preg_match('/\b([245])\.(\d{1,3})\.(\d{1,3})\b/', $text, $m);
        $enhanced = $m ? "$m[1].$m[2].$m[3]" : '';
        $class = $code ? intdiv($code, 100) : null;

        // No reply after the final ".": the message may have been accepted.
        if ($stage === 'end_of_data' && $code === null) {
            return self::AMBIGUOUS;
        }
        if (preg_match('/\b[45]\.7\.28\b|unusual rate/i', $text)) {
            return self::DOMAIN_RATE_LIMITED;
        }
        if ($enhanced === '5.4.5' || preg_match('/sending (limit|quota)|quota exceeded|daily user sending/i', $text)) {
            return self::QUOTA;
        }
        if ($stage === 'auth' || preg_match('/Failed to authenticate|authenticator supported|Username and Password not accepted/i', $text)) {
            return ($class === 4) ? self::RATE_LIMITED : self::AUTH;
        }
        if (preg_match('/TLS required|STARTTLS/i', $text)) {
            return self::CONFIG;
        }
        if ($code === null) {
            // No SMTP reply at all; the stage, if known, is before the end of the message.
            return self::CONNECTION;
        }
        if ($class === 4 && str_starts_with($enhanced, '4.7.')) {
            return self::RATE_LIMITED;
        }

        return match ($stage) {
            'mail_from' => $class === 5 ? self::SENDER_REJECTED : self::CONNECTION,
            'rcpt_to' => match (true) {
                $class === 5 && preg_match('/relay|not permitted|sender|not owned|authenticat/i', $text) === 1 => self::SENDER_REJECTED,
                $class === 5 => self::RECIPIENT,
                default => self::DEFERRED,
            },
            'data', 'end_of_data' => match (true) {
                $class === 5 && preg_match('/relay|sender address|not owned|not authori[sz]ed to send/i', $text) === 1 => self::SENDER_REJECTED,
                $class === 5 => self::MESSAGE,
                default => self::CONNECTION,
            },
            // Refused while connecting (greeting, STARTTLS): the server, not the message.
            'connect', 'greeting', 'starttls' => self::CONNECTION,
            // A transport that does not report the stage: only a temporary refusal is safe to read.
            default => $class === 4 ? self::CONNECTION : self::OTHER,
        };
    }

    /** The account should rest before it is used again. */
    public function affectsAccount(): bool
    {
        return in_array($this->type, self::ACCOUNT_PROBLEMS, true);
    }

    /** The message was certainly not accepted, and another account may succeed. */
    public function allowsFailover(): bool
    {
        return in_array($this->type, self::FAILOVER, true);
    }

    /** Retrying cannot help this email. */
    public function isPermanent(): bool
    {
        return in_array($this->type, [self::RECIPIENT, self::MESSAGE], true);
    }

    public function isAmbiguous(): bool
    {
        return $this->type === self::AMBIGUOUS;
    }

    public function isRateLimit(): bool
    {
        return in_array($this->type, [self::RATE_LIMITED, self::QUOTA, self::DOMAIN_RATE_LIMITED], true);
    }

    /** The cooldown key in config('mail.pool.cooldown') for this kind of failure. */
    public function cooldownKey(): string
    {
        return match ($this->type) {
            self::RATE_LIMITED, self::DOMAIN_RATE_LIMITED => 'rate_limited',
            self::QUOTA => 'quota',
            self::AUTH, self::CONFIG, self::SENDER_REJECTED => 'auth',
            default => 'connection',
        };
    }
}
