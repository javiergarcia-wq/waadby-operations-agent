<?php

namespace Waadby\OperationsAgent\Contracts;

interface RestoreLifecycleHooks
{
    public function quiesce(): void;

    public function migrateForward(): void;

    public function smokeInternal(): void;

    public function resume(): void;

    public function smokeHttp(): void;
}
