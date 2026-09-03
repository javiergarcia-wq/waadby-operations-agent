<?php

namespace Waadby\OperationsAgent\Services;

use Throwable;
use Waadby\OperationsAgent\OperationsAgent;
use Waadby\OperationsAgent\Remote\EnrollmentStore;

final class AgentStatus
{
    public function __construct(private readonly EnrollmentStore $enrollment) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        try {
            $identity = $this->enrollment->get();
        } catch (Throwable) {
            $identity = null;
        }

        return [
            'agent_version' => OperationsAgent::VERSION,
            'enabled' => (bool) config('waadby_operations.remote_agent.enabled', false),
            'enrolled' => $this->enrollment->isReady(),
            'application_code' => (string) config('waadby_operations.application.code', ''),
            'environment' => (string) config('waadby_operations.application.environment', ''),
            'installation_id' => is_array($identity) ? ($identity['installation_id'] ?? null) : null,
            'local_installation_id' => is_array($identity) ? ($identity['local_installation_id'] ?? null) : null,
            'access_origin' => is_array($identity) ? ($identity['access_origin'] ?? null) : null,
            'jwks_uri' => is_array($identity) ? ($identity['jwks_uri'] ?? null) : null,
            'last_contact_at' => is_array($identity) ? ($identity['last_contact_at'] ?? null) : null,
            'last_inventory_at' => is_array($identity) ? ($identity['last_inventory_at'] ?? null) : null,
            'capabilities' => (array) config('waadby_operations.capabilities', []),
        ];
    }
}
