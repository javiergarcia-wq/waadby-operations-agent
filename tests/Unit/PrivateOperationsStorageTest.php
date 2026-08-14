<?php

namespace Tests\Package\Operations\Unit;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use Waadby\OperationsAgent\Remote\EnrollmentStore;
use Waadby\OperationsAgent\Support\OperationsPrivateStoragePathPolicy;
use Waadby\OperationsAgent\Updates\CodeSnapshotService;
use Waadby\OperationsAgent\Updates\InstalledReleaseStore;
use Waadby\OperationsAgent\Updates\UpdateSessionStore;

final class PrivateOperationsStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/private-operations-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->root.'/application/app');
        config([
            'waadby_operations.application.code' => 'waadby-access',
            'waadby_operations.application.environment' => 'testing',
            'waadby_operations.updates.state_path' => $this->root.'/state/installed-release.json',
            'waadby_operations.updates.snapshot_path' => $this->root.'/snapshots',
            'waadby_operations.remote_agent.state_path' => $this->root.'/agent',
            'waadby_operations.updates.chunk_bytes' => 16,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_private_directory_is_created_canonical_and_restrictive(): void
    {
        $directory = app(OperationsPrivateStoragePathPolicy::class)->prepareDirectory($this->root.'/normal/private');

        $this->assertSame(realpath($this->root.'/normal/private'), $directory);
        $this->assertDirectoryExists($directory);
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0, (fileperms($directory) ?: 0) & 0077);
        }
    }

    public function test_public_directory_and_relative_escape_are_rejected_before_creation(): void
    {
        $publicTarget = public_path('private-operations-forbidden');
        try {
            app(OperationsPrivateStoragePathPolicy::class)->prepareDirectory($publicTarget);
            $this->fail('public/ debe rechazarse.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($publicTarget);
        }

        $this->expectException(RuntimeException::class);
        app(OperationsPrivateStoragePathPolicy::class)->prepareDirectory(base_path('storage/../public/private-operations-forbidden'));
    }

    public function test_symlink_to_public_is_rejected(): void
    {
        $alias = $this->root.'/public-symlink';
        if (! @symlink(public_path(), $alias)) {
            $this->markTestSkipped('La plataforma no permite crear symlinks para esta prueba.');
        }
        try {
            $this->expectException(RuntimeException::class);
            app(OperationsPrivateStoragePathPolicy::class)->prepareDirectory($alias.'/state');
        } finally {
            @rmdir($alias);
            @unlink($alias);
        }
    }

    public function test_windows_junction_to_public_is_rejected_when_available(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('La fixture junction solo aplica a Windows.');
        }
        $junction = $this->root.'/public-junction';
        exec(sprintf('C:\\Windows\\System32\\cmd.exe /c mklink /J "%s" "%s"', $junction, public_path()), $output, $exitCode);
        if ($exitCode !== 0 || ! is_dir($junction)) {
            $this->markTestSkipped('La plataforma no permite crear la junction de prueba.');
        }
        try {
            $this->expectException(RuntimeException::class);
            app(OperationsPrivateStoragePathPolicy::class)->prepareDirectory($junction.'/state');
        } finally {
            @rmdir($junction);
        }
    }

    public function test_installed_release_is_private_and_atomic_replacement_remains_supported(): void
    {
        $store = app(InstalledReleaseStore::class);
        $store->write($this->installedState('1.0.0'));
        $store->write($this->installedState('1.1.0'));

        $this->assertSame('1.1.0', $store->read()['version']);
        $this->assertFileExists($store->path());
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0, (fileperms($store->path()) ?: 0) & 0077);
        }
    }

    public function test_installed_release_under_public_is_rejected_without_writing(): void
    {
        $path = public_path('operations-state/installed-release.json');
        config(['waadby_operations.updates.state_path' => $path]);
        try {
            app(InstalledReleaseStore::class)->write($this->installedState('1.0.0'));
            $this->fail('El estado instalado bajo public/ debe rechazarse.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_installed_release_symlink_parent_to_public_is_rejected(): void
    {
        $alias = $this->root.'/installed-public-symlink';
        if (! @symlink(public_path(), $alias)) {
            $this->markTestSkipped('La plataforma no permite crear el symlink de estado instalado.');
        }
        config(['waadby_operations.updates.state_path' => $alias.'/installed-release-forbidden.json']);
        try {
            app(InstalledReleaseStore::class)->write($this->installedState('1.0.0'));
            $this->fail('Un parent symlink de estado instalado debe rechazarse.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist(public_path('installed-release-forbidden.json'));
        } finally {
            @rmdir($alias);
            @unlink($alias);
        }
    }

    public function test_remote_update_state_and_package_are_private(): void
    {
        $store = app(UpdateSessionStore::class);
        $session = $store->create($this->sessionMetadata());
        $store->append($session['session_id'], 0, 0, 'private-package');

        $package = $store->packagePath($session['session_id']);
        $this->assertFileExists($package);
        $this->assertStringStartsWith(str_replace('\\', '/', realpath($this->root.'/agent')), str_replace('\\', '/', realpath($package)));
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0, (fileperms($package) ?: 0) & 0077);
        }
    }

    public function test_remote_update_and_enrollment_fail_closed_under_public(): void
    {
        $root = public_path('operations-agent-forbidden');
        config(['waadby_operations.remote_agent.state_path' => $root]);

        try {
            app(UpdateSessionStore::class)->create($this->sessionMetadata());
            $this->fail('La sesion remota bajo public/ debe rechazarse.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($root);
        }

        $enrollment = app(EnrollmentStore::class);
        $this->assertFalse($enrollment->isReady());
        try {
            $enrollment->put(['installation_id' => 'forbidden']);
            $this->fail('Enrollment bajo public/ debe rechazarse.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($root.'/enrollment.json');
        }
    }

    public function test_remote_state_symlink_to_public_rejects_session_and_enrollment(): void
    {
        $alias = $this->root.'/remote-public-symlink';
        $target = public_path('remote-state-target-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($target);
        if (! @symlink($target, $alias)) {
            File::deleteDirectory($target);
            $this->markTestSkipped('La plataforma no permite crear el symlink de estado remoto.');
        }
        config(['waadby_operations.remote_agent.state_path' => $alias]);
        try {
            try {
                app(UpdateSessionStore::class)->create($this->sessionMetadata());
                $this->fail('El estado remoto mediante symlink debe rechazarse.');
            } catch (RuntimeException) {
                $this->assertDirectoryDoesNotExist($target.'/updates');
            }
            $this->assertFalse(app(EnrollmentStore::class)->isReady());
        } finally {
            @rmdir($alias);
            @unlink($alias);
            File::deleteDirectory($target);
        }
    }

    public function test_snapshot_is_private_and_restore_outside_configured_root_is_rejected(): void
    {
        File::put($this->root.'/application/app/Version.php', 'old');
        $files = [['path' => 'app/Version.php', 'sha256' => hash('sha256', 'new'), 'size' => 3, 'operation' => 'replace']];
        $service = app(CodeSnapshotService::class);
        $snapshot = $service->create($this->root.'/application', 'private-snapshot', $files);

        $this->assertFileExists($snapshot['path'].'/snapshot.json');
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0, (fileperms($snapshot['path'].'/snapshot.json') ?: 0) & 0077);
        }

        $outside = $this->root.'/outside-snapshot';
        File::ensureDirectoryExists($outside);
        $this->expectException(RuntimeException::class);
        $service->restore($this->root.'/application', $outside, $snapshot['sha256']);
    }

    public function test_snapshot_root_under_public_is_rejected_without_snapshot_files(): void
    {
        $root = public_path('snapshots-forbidden');
        config(['waadby_operations.updates.snapshot_path' => $root]);
        try {
            app(CodeSnapshotService::class)->create($this->root.'/application', 'blocked-snapshot', []);
            $this->fail('Snapshot bajo public/ debe rechazarse.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($root);
        }
    }

    public function test_snapshot_root_symlink_to_public_is_rejected(): void
    {
        $alias = $this->root.'/snapshot-public-symlink';
        if (! @symlink(public_path(), $alias)) {
            $this->markTestSkipped('La plataforma no permite crear el symlink de snapshots.');
        }
        config(['waadby_operations.updates.snapshot_path' => $alias]);
        try {
            app(CodeSnapshotService::class)->create($this->root.'/application', 'blocked-snapshot-link', []);
            $this->fail('El root de snapshots mediante symlink debe rechazarse.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist(public_path('blocked-snapshot-link'));
        } finally {
            @rmdir($alias);
            @unlink($alias);
        }
    }

    /** @return array<string, string> */
    private function installedState(string $version): array
    {
        return [
            'application_code' => 'waadby-access',
            'version' => $version,
            'source_commit' => str_repeat('a', 40),
            'release_public_id' => 'release-'.$version,
            'applied_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionMetadata(): array
    {
        return [
            'installation_id' => 'installation-test',
            'manifest' => ['application_code' => 'waadby-access', 'version' => '1.1.0', 'source_commit' => str_repeat('b', 40)],
            'signature' => ['signature' => 'private'],
            'backup_id' => 'backup-test',
            'vault_verified' => true,
            'package_sha256' => hash('sha256', 'private-package'),
            'package_size' => 15,
        ];
    }
}
