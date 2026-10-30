<?php

namespace App\Mail\Pool;

use RuntimeException;

/** An email given up on. Its message is already free of credentials. */
final class MailDeliveryFailed extends RuntimeException {}
