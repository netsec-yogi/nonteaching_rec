<?php

namespace App\Services\Maintenance;

use RuntimeException;

/** A backup file is missing, unreadable, truncated, altered or cannot be decrypted. */
class BackupIntegrityException extends RuntimeException {}
