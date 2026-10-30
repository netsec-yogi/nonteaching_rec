<?php

namespace App\Mail\Pool;

/**
 * One configured sending account. Holds what may be shown and logged — never the login or the
 * password, which stay in the mailer configuration (see MailSecrets for redaction).
 */
final class MailAccount
{
    /**
     * @param  list<string>  $problems  configuration errors: the account is not used until they are fixed
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $mailer,
        public readonly bool $primary,
        public readonly bool $enabled,
        public readonly ?string $fromAddress,
        public readonly ?string $fromName,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $encryption,
        public readonly ?int $perMinute = null,
        public readonly ?int $dailyLimit = null,
        public readonly array $problems = [],
        public readonly array $warnings = [],
    ) {}

    public function usable(): bool
    {
        return $this->enabled && $this->problems === [];
    }

    public function domain(): ?string
    {
        return $this->fromAddress && str_contains($this->fromAddress, '@')
            ? mb_strtolower(substr(strrchr($this->fromAddress, '@'), 1)) : null;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name, 'label' => $this->label, 'primary' => $this->primary, 'enabled' => $this->enabled,
            'from_address' => $this->fromAddress, 'from_name' => $this->fromName,
            'host' => $this->host, 'port' => $this->port, 'encryption' => $this->encryption,
            'per_minute' => $this->perMinute, 'daily_limit' => $this->dailyLimit,
            'problems' => $this->problems, 'warnings' => $this->warnings,
        ];
    }
}
