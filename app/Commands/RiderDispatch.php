<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * RiderDispatch — one tick of the live dispatch engine: lapse expired offers and
 * offer 'pending' deliveries to the nearest online rider. Run on a short cron
 * (e.g. every 30–60s) alongside the lazy dispatch that runs on the rider poll:
 *   php spark rider:dispatch
 */
final class RiderDispatch extends BaseCommand
{
    protected $group       = 'Ops';
    protected $name        = 'rider:dispatch';
    protected $description = 'Lapse expired delivery offers and dispatch pending deliveries to riders.';
    protected $usage       = 'rider:dispatch';

    public function run(array $params): int
    {
        $res = service('riderDispatchService')->run();
        CLI::write('rider:dispatch — expired ' . CLI::color((string) $res['expired'], 'yellow') . ', offered ' . CLI::color((string) $res['offered'], 'green'));

        return 0;
    }
}
