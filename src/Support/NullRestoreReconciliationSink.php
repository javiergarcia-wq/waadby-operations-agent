<?php

namespace Waadby\OperationsAgent\Support;

use Waadby\OperationsAgent\Contracts\RestoreReconciliationSink;

final class NullRestoreReconciliationSink implements RestoreReconciliationSink
{
    public function reconcile(array $journal): void {}
}
