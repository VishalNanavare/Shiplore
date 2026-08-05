<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * OrdersEscalate — scan confirmed sub-orders with no active claim and escalate
 * them through shop → vendor → admin if nobody acts within the timeout.
 *
 * Run every minute via cron:
 *   * * * * * cd /var/www/html && php spark orders:escalate >> /dev/null 2>&1
 */
final class OrdersEscalate extends BaseCommand
{
    protected $group       = 'Ops';
    protected $name        = 'orders:escalate';
    protected $description = 'Escalate unclaimed confirmed orders through shop → vendor → admin.';
    protected $usage       = 'orders:escalate';

    public function run(array $params): void
    {
        $start = microtime(true);
        CLI::write('[orders:escalate] scanning …', 'cyan');

        service('escalationService')->escalatePending();

        $ms = round((microtime(true) - $start) * 1000);
        CLI::write("[orders:escalate] done in {$ms}ms", 'green');
    }
}
