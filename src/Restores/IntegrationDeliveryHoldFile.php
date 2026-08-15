<?php

namespace Waadby\OperationsAgent\Restores;

use RuntimeException;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;

final class IntegrationDeliveryHoldFile
{
    public function __construct(private readonly OperationsPrivateStoragePathPolicy $paths) {}

    public function enable(string $restoreId, string $reason): void
    {
        $state = ['enabled' => true, 'restore_id' => $restoreId, 'reason' => mb_substr($reason, 0, 500), 'enabled_at' => now()->utc()->toIso8601String()];
        $state['sha256'] = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $path = $this->paths->prepareFile((string) config('waadby_operations.restores.integration_hold_path'));
        $temporary = $path.'.tmp';
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('No se pudo persistir integration delivery hold restore.');
        }
        $this->paths->protectFile($temporary);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo publicar integration delivery hold restore.');
        }
        $this->paths->protectFile($path);
    }
}
