<?php

declare(strict_types=1);

namespace App\Libraries\Payment;

/**
 * AbstractGateway — shared driver behaviour. The default testConnection checks
 * that every required field is present and non-empty; drivers may override to
 * add a live probe.
 */
abstract class AbstractGateway implements PaymentGatewayInterface
{
    public function testConnection(array $config): array
    {
        $missing = [];
        foreach ($this->fields() as $f) {
            if (trim((string) ($config[$f['key']] ?? '')) === '') {
                $missing[] = $f['label'];
            }
        }

        if ($missing !== []) {
            return ['ok' => false, 'message' => 'Missing required field(s): ' . implode(', ', $missing)];
        }

        return ['ok' => true, 'message' => $this->label() . ' configuration is valid and ready.'];
    }
}
