<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Remote\EnrollmentClient;

final class EnrollCommand extends Command
{
    protected $signature = 'waadby:operations:enroll {--access= : Origen HTTPS de WAADBY ACCESS} {--token= : Token de un solo uso}';

    protected $description = 'Enroll this application in a WAADBY Operations control plane';

    public function handle(EnrollmentClient $client): int
    {
        $access = (string) ($this->option('access') ?: '');
        $token = (string) ($this->option('token') ?: '');
        if ($access === '' || $token === '') {
            $this->error('Debe indicar --access y --token.');

            return self::FAILURE;
        }
        try {
            $identity = $client->enroll($access, $token);
            $this->info('Enrollment completado para '.$identity['installation_id'].'.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
