<?php

namespace App\Mail\Pool;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Symfony's ESMTP transport, noting how far each message got. A failure before the end of DATA
 * means the server cannot have accepted the message, so another account may send it; a failure
 * after the final "." without a reply is ambiguous — the server may have accepted it — and the
 * message must not be sent again automatically. Registered as the "pool_smtp" mail transport.
 */
final class TrackedSmtpTransport extends EsmtpTransport
{
    private string $stage = 'connect';

    private ?string $lastResponse = null;

    /** Builds the transport from a mailer configuration, as Laravel's own smtp transport does. */
    public static function fromConfig(array $config): self
    {
        $port = (int) ($config['port'] ?? 0);
        $scheme = $config['scheme'] ?? ($port === 465 ? 'smtps' : 'smtp');
        $autoTls = filter_var($config['auto_tls'] ?? true, FILTER_VALIDATE_BOOL);

        $transport = new self((string) $config['host'], $port, $scheme === 'smtps' ? true : ($autoTls ? null : false));
        $transport->setAutoTls($autoTls);
        $transport->setRequireTls(filter_var($config['require_tls'] ?? false, FILTER_VALIDATE_BOOL));
        if (! empty($config['username'])) {
            $transport->setUsername((string) $config['username']);
        }
        if (! empty($config['password'])) {
            $transport->setPassword((string) $config['password']);
        }
        if (! empty($config['local_domain'])) {
            $transport->setLocalDomain((string) $config['local_domain']);
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            if (isset($config['timeout'])) {
                $stream->setTimeout((float) $config['timeout']);
            }
            if (isset($config['source_ip'])) {
                $stream->setSourceIp((string) $config['source_ip']);
            }
        }

        return $transport;
    }

    public function executeCommand(string $command, array $codes): string
    {
        $this->stage = match (true) {
            $command === "\r\n.\r\n" => 'end_of_data',
            str_starts_with($command, 'MAIL FROM') => 'mail_from',
            str_starts_with($command, 'RCPT TO') => 'rcpt_to',
            str_starts_with($command, 'DATA') => 'data',
            str_starts_with($command, 'AUTH') => 'auth',
            str_starts_with($command, 'STARTTLS') => 'starttls',
            str_starts_with($command, 'EHLO'), str_starts_with($command, 'HELO') => 'greeting',
            default => $this->stage, // AUTH continuation lines, RSET, NOOP
        };
        $response = parent::executeCommand($command, $codes);
        if ($this->stage === 'end_of_data') {
            $this->lastResponse = trim($response);
        }

        return $response;
    }

    protected function doSend(SentMessage $message): void
    {
        $this->stage = 'connect';
        $this->lastResponse = null;
        try {
            parent::doSend($message);
        } catch (TransportExceptionInterface $e) {
            throw new SmtpDeliveryException($e, $this->stage);
        }
    }

    /** The server's reply to the end of the last message it accepted. */
    public function lastResponse(): ?string
    {
        return $this->lastResponse;
    }
}
