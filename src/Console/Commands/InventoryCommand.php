<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use Waadby\OperationsAgent\Contracts\OperationsRuntime;

class InventoryCommand extends Command
{
    protected $signature = 'waadby:operations:inventory {--json : Emitir JSON estructurado}';

    protected $description = 'Muestra el inventario seguro de la instalacion local';

    public function handle(OperationsRuntime $runtime): int
    {
        try {
            $inventory = $runtime->inventory();
            if ($this->option('json')) {
                $this->line(json_encode($inventory, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->table(['Campo', 'Valor'], collect($inventory)->map(fn (mixed $value, string $key): array => [
                    $key,
                    is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($value ?? 'no disponible'),
                ])->values()->all());
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
