<?php

namespace Waadby\OperationsAgent\Contracts;

interface RestoreReconciliationSink
{
    /** @param array<string, mixed> $journal */
    public function reconcile(array $journal): void;
}
