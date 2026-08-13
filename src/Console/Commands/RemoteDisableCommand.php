<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Remote\EnrollmentStore;

final class RemoteDisableCommand extends Command
{
    protected $signature = 'waadby:operations:remote:disable {--force : Confirm emergency local disable}';

    protected $description = 'Fail closed and disable remote Operations locally';

    public function handle(EnrollmentStore $store): int
    {
        if (! $this->option('force') && ! $this->confirm('¿Desactivar las operaciones remotas en este agente?')) {
            return self::FAILURE;
        }
        $store->disable();
        $this->warn('Remote Operations se ha desactivado localmente. Requiere un enrollment nuevo para reactivarse.');

        return self::SUCCESS;
    }
}
