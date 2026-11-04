<?php

namespace App\Services\Maintenance;

use RuntimeException;

/** A restore stopped before it changed anything (failed check or failed safety backup). */
class RestoreAborted extends RuntimeException {}
