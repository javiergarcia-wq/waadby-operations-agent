# WAADBY Operations Agent

Canal de control común para aplicaciones Laravel gobernadas por WAADBY ACCESS.

## Integración

Instale una versión estable exacta y configure:

```php
'runtime' => App\Operations\ApplicationOperationsRuntime::class,
```

El runtime consumidor debe implementar `Waadby\OperationsAgent\Contracts\OperationsRuntime` y delegar en los motores operativos propios de la aplicación. El Agent aporta enrollment, autenticación firmada, replay protection, idempotencia, endpoints y reporting; no obliga a duplicar Backup, Restore ni Update.

Variables mínimas:

- `WAADBY_OPERATIONS_REMOTE_AGENT_ENABLED`
- `WAADBY_APPLICATION_CODE`
- `WAADBY_APPLICATION_ENVIRONMENT`

Enrollment:

```text
php artisan waadby:operations:enroll --access=https://access.example --token=<one-time-token>
```

El token se consume una vez y no se conserva. El estado firmado se guarda en storage privado y queda ligado a application code, environment e installation ID.

Los endpoints Apply permanecen deshabilitados salvo activación explícita. Un consumidor con motores propios debe mantenerlos deshabilitados hasta proporcionar una delegación certificada; el canal remoto nunca debe convertirse en un segundo motor operativo.
