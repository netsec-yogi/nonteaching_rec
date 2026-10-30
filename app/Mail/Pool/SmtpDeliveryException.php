<?php

namespace App\Mail\Pool;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/** A transport failure, with the SMTP stage it happened at (see TrackedSmtpTransport). */
final class SmtpDeliveryException extends TransportException
{
    public function __construct(TransportExceptionInterface $previous, public readonly string $stage)
    {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
