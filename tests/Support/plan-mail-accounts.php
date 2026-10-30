<?php

use App\Mail\Pool\MailPool;
use Illuminate\Contracts\Console\Kernel;

/**
 * Boots the application in its own process and plans <count> emails through the account pool,
 * printing one JSON line per email: {"sequence": n, "account": "name"}. MailPoolTest runs several
 * at once to show round robin holds across workers. Argument 2 is the pool configuration (JSON).
 */
[, $count, $config] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(json_decode($config, true));

$pool = $app->make(MailPool::class);
for ($i = 0; $i < (int) $count; $i++) {
    $plan = $pool->plan();
    echo json_encode(['sequence' => $plan['sequence'], 'account' => $plan['accounts'][0]->name ?? null]), "\n";
}
