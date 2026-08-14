<?php

namespace Waadby\OperationsAgent\Support;

final class ReleaseBuildOutputPathPolicy
{
    public function __construct(private readonly OperationsPrivateStoragePathPolicy $privateStorage) {}

    public function prepare(string $configuredOutput, string $workingDirectory, string $publicDirectory): string
    {
        return $this->privateStorage->prepareDirectory($configuredOutput, $workingDirectory, $publicDirectory);
    }
}
