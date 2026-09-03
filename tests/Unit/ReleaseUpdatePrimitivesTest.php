<?php

namespace Tests\Package\Operations\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Waadby\OperationsAgent\Console\Commands\ReleaseBuildCommand;
use Waadby\OperationsAgent\Services\ReleaseManifestValidator;
use Waadby\OperationsAgent\Updates\CodeApplyService;
use Waadby\OperationsAgent\Updates\CodeSnapshotService;
use Waadby\OperationsAgent\Updates\InstalledReleaseStore;
use Waadby\OperationsAgent\Updates\ReleaseCanonicalizer;
use Waadby\OperationsAgent\Updates\ReleasePackageVerifier;
use Waadby\OperationsAgent\Updates\ReleasePathPolicy;
use Waadby\OperationsAgent\Updates\ReleaseSignatureService;
use Waadby\OperationsAgent\Updates\UpdateDestinationPathPolicy;
use Waadby\OperationsAgent\Updates\UpdateSessionStore;
use ZipArchive;

final class ReleaseUpdatePrimitivesTest extends TestCase
{
    public function test_release_builder_excludes_git_metadata_in_direct_checkouts_and_worktrees(): void
    {
        $filter = new ReflectionMethod(ReleaseBuildCommand::class, 'isExcluded');
        $command = new ReleaseBuildCommand;

        $this->assertTrue($filter->invoke($command, '.git', false));
        $this->assertTrue($filter->invoke($command, '.git/config', false));
        $this->assertFalse($filter->invoke($command, '.github/workflows/ci.yml', false));
    }

    private string $root;

    private string $private;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/f6d-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->root);
        $pair = sodium_crypto_sign_keypair();
        $this->private = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
        config([
            'waadby_operations.application.code' => 'waadby-access',
            'waadby_operations.updates.trusted_keys' => json_encode(['test-2026' => $this->public], JSON_THROW_ON_ERROR),
            'waadby_operations.updates.maximum_package_bytes' => 1048576,
            'waadby_operations.updates.maximum_uncompressed_bytes' => 2097152,
            'waadby_operations.updates.maximum_file_bytes' => 1048576,
            'waadby_operations.updates.maximum_files' => 100,
            'waadby_operations.updates.state_path' => $this->root.'/state/installed-release.json',
            'waadby_operations.updates.snapshot_path' => $this->root.'/snapshots',
            'waadby_operations.remote_agent.state_path' => $this->root.'/agent',
            'waadby_operations.updates.chunk_bytes' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_release_manifest_v2_is_strict_and_v1_remains_compatible(): void
    {
        $validator = app(ReleaseManifestValidator::class);
        $this->assertSame([], $validator->errors($this->manifest(str_repeat('a', 64), str_repeat('b', 64))));
        $v1 = $this->manifest(str_repeat('a', 64), str_repeat('b', 64));
        $v1['manifest_version'] = 1;
        unset($v1['package'], $v1['deployment']);
        unset($v1['database']['rollback_policy']);
        $this->assertSame([], $validator->errors($v1));
    }

    public function test_ed25519_signature_and_complete_package_are_verified(): void
    {
        [$manifest, $signature, $package] = $this->release(['app/Example.php' => '<?php return true;']);
        $result = app(ReleasePackageVerifier::class)->verify($manifest, $signature, $package);
        $this->assertSame(hash_file('sha256', $package), $result['package_sha256']);
        $this->assertSame('app/Example.php', $result['files'][0]['path']);
    }

    public function test_unknown_signing_key_is_rejected(): void
    {
        [$manifest, $signature] = $this->release(['app/Example.php' => 'safe']);
        $signature['key_id'] = 'unknown';
        $this->expectException(RuntimeException::class);
        app(ReleaseSignatureService::class)->verify($manifest, $signature);
    }

    public function test_manifest_tampering_is_rejected(): void
    {
        [$manifest, $signature] = $this->release(['app/Example.php' => 'safe']);
        $manifest['version'] = '9.9.9';
        $this->expectException(RuntimeException::class);
        app(ReleaseSignatureService::class)->verify($manifest, $signature);
    }

    public function test_package_tampering_is_rejected(): void
    {
        [$manifest, $signature, $package] = $this->release(['app/Example.php' => 'safe']);
        file_put_contents($package, 'tampered', FILE_APPEND);
        $this->expectException(RuntimeException::class);
        app(ReleasePackageVerifier::class)->verify($manifest, $signature, $package);
    }

    #[DataProvider('unsafeReleasePaths')]
    public function test_protected_and_non_portable_paths_are_rejected(string $path): void
    {
        $this->expectException(RuntimeException::class);
        app(ReleasePathPolicy::class)->assertSafe($path);
    }

    public static function unsafeReleasePaths(): array
    {
        return [
            'env' => ['.env'], 'env production' => ['.env.production'], 'storage' => ['storage/app/data'],
            'public storage' => ['public/storage/file'], 'git' => ['.git/config'], 'backup' => ['backups/latest.zip'],
            'traversal' => ['app/../.env'], 'absolute' => ['/etc/passwd'], 'drive' => ['C:\\secret'],
            'nul' => ["app/file\0.php"], 'windows reserved' => ['app/CON.txt'],
            'updater runtime' => ['packages/waadby-operations-agent/src/Updates/UpdateExecutor.php'],
        ];
    }

    public function test_snapshot_apply_and_restore_cover_replace_create_delete_and_installed_state(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        File::put($this->root.'/application/app/replace.txt', 'old');
        File::put($this->root.'/application/app/delete.txt', 'delete-me');
        app(InstalledReleaseStore::class)->write(['application_code' => 'waadby-access', 'version' => '1.0.0', 'source_commit' => str_repeat('1', 40), 'release_public_id' => 'old', 'applied_at' => now()->toIso8601String()]);
        $files = [
            ['path' => 'app/replace.txt', 'sha256' => hash('sha256', 'new'), 'size' => 3, 'operation' => 'replace'],
            ['path' => 'app/new.txt', 'sha256' => hash('sha256', 'created'), 'size' => 7, 'operation' => 'replace'],
            ['path' => 'app/delete.txt', 'sha256' => str_repeat('0', 64), 'size' => 0, 'operation' => 'delete'],
        ];
        File::ensureDirectoryExists($this->root.'/staging/app');
        File::put($this->root.'/staging/app/replace.txt', 'new');
        File::put($this->root.'/staging/app/new.txt', 'created');
        $snapshot = app(CodeSnapshotService::class)->create($this->root.'/application', 'session-1', $files);
        app(CodeApplyService::class)->apply($this->root.'/application', $this->root.'/staging', $files);
        $this->assertSame('new', File::get($this->root.'/application/app/replace.txt'));
        $this->assertFileDoesNotExist($this->root.'/application/app/delete.txt');
        app(CodeSnapshotService::class)->restore($this->root.'/application', $snapshot['path'], $snapshot['sha256']);
        $this->assertSame('old', File::get($this->root.'/application/app/replace.txt'));
        $this->assertSame('delete-me', File::get($this->root.'/application/app/delete.txt'));
        $this->assertFileDoesNotExist($this->root.'/application/app/new.txt');
        $this->assertSame('1.0.0', app(InstalledReleaseStore::class)->read()['version']);
    }

    public function test_corrupt_snapshot_fails_closed(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        File::put($this->root.'/application/app/a.txt', 'old');
        $snapshot = app(CodeSnapshotService::class)->create($this->root.'/application', 'session-corrupt', [['path' => 'app/a.txt', 'sha256' => hash('sha256', 'new'), 'size' => 3, 'operation' => 'replace']]);
        File::put($snapshot['path'].'/files/app/a.txt', 'corrupt');
        $this->expectException(RuntimeException::class);
        app(CodeSnapshotService::class)->verify($snapshot['path'], $snapshot['sha256']);
    }

    public function test_06_normal_target_inside_canonical_root_is_allowed(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        $target = app(UpdateDestinationPathPolicy::class)->resolveFile($this->root.'/application', 'app/Normal.php');
        $this->assertSame(str_replace('/', DIRECTORY_SEPARATOR, $this->root.'/application/app/Normal.php'), $target);
    }

    public function test_07_target_symlink_is_rejected(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        File::put($this->root.'/outside.txt', 'outside');
        $link = $this->root.'/application/app/Linked.php';
        $this->createSymlink($this->root.'/outside.txt', $link);
        try {
            app(UpdateDestinationPathPolicy::class)->resolveFile($this->root.'/application', 'app/Linked.php');
            $this->fail('Un target symlink debe rechazarse.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            @unlink($link);
        }
    }

    public function test_08_parent_symlink_outside_root_is_rejected(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        File::ensureDirectoryExists($this->root.'/outside');
        $link = $this->root.'/application/app/Modules';
        $this->createSymlink($this->root.'/outside', $link, true);
        try {
            app(UpdateDestinationPathPolicy::class)->resolveFile($this->root.'/application', 'app/Modules/New.php');
            $this->fail('Un parent symlink fuera del root debe rechazarse.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            @rmdir($link);
            @unlink($link);
        }
    }

    public function test_09_parent_symlink_inside_root_is_also_rejected(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app/RealModules');
        $link = $this->root.'/application/app/AliasModules';
        $this->createSymlink($this->root.'/application/app/RealModules', $link, true);
        try {
            app(UpdateDestinationPathPolicy::class)->resolveFile($this->root.'/application', 'app/AliasModules/New.php');
            $this->fail('Un alias interno tambien debe rechazarse.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            @rmdir($link);
            @unlink($link);
        }
    }

    public function test_10_windows_junction_outside_root_is_rejected_when_available(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('La fixture junction solo aplica a Windows.');
        }
        File::ensureDirectoryExists($this->root.'/application/app');
        File::ensureDirectoryExists($this->root.'/junction-outside');
        $junction = $this->root.'/application/app/JunctionModules';
        exec(sprintf('C:\\Windows\\System32\\cmd.exe /c mklink /J "%s" "%s"', $junction, $this->root.'/junction-outside'), $output, $exitCode);
        if ($exitCode !== 0 || ! is_dir($junction)) {
            $this->markTestSkipped('La plataforma no permite crear la junction de integracion.');
        }
        try {
            app(UpdateDestinationPathPolicy::class)->resolveFile($this->root.'/application', 'app/JunctionModules/New.php');
            $this->fail('Una junction fuera del root debe rechazarse.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            @rmdir($junction);
        }
    }

    public function test_11_delete_through_target_symlink_is_rejected(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        File::put($this->root.'/outside-delete.txt', 'keep');
        $link = $this->root.'/application/app/Delete.php';
        $this->createSymlink($this->root.'/outside-delete.txt', $link);
        try {
            app(CodeApplyService::class)->apply($this->root.'/application', $this->root.'/staging', [['path' => 'app/Delete.php', 'sha256' => str_repeat('0', 64), 'size' => 0, 'operation' => 'delete']]);
            $this->fail('Delete no debe atravesar un symlink.');
        } catch (RuntimeException) {
            $this->assertSame('keep', File::get($this->root.'/outside-delete.txt'));
        } finally {
            @unlink($link);
        }
    }

    public function test_12_snapshot_create_rejects_parent_symlink(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        File::ensureDirectoryExists($this->root.'/snapshot-outside');
        File::put($this->root.'/snapshot-outside/File.php', 'outside');
        $link = $this->root.'/application/app/Linked';
        $this->createSymlink($this->root.'/snapshot-outside', $link, true);
        try {
            app(CodeSnapshotService::class)->create($this->root.'/application', 'linked-create', [['path' => 'app/Linked/File.php', 'sha256' => hash('sha256', 'new'), 'size' => 3, 'operation' => 'replace']]);
            $this->fail('Snapshot create no debe leer mediante un parent symlink.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            @rmdir($link);
            @unlink($link);
        }
    }

    public function test_13_snapshot_restore_rejects_parent_symlink(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app/Linked');
        File::put($this->root.'/application/app/Linked/File.php', 'old');
        $files = [['path' => 'app/Linked/File.php', 'sha256' => hash('sha256', 'new'), 'size' => 3, 'operation' => 'replace']];
        $snapshot = app(CodeSnapshotService::class)->create($this->root.'/application', 'linked-restore', $files);
        File::deleteDirectory($this->root.'/application/app/Linked');
        File::ensureDirectoryExists($this->root.'/restore-outside');
        $link = $this->root.'/application/app/Linked';
        $this->createSymlink($this->root.'/restore-outside', $link, true);
        try {
            app(CodeSnapshotService::class)->restore($this->root.'/application', $snapshot['path'], $snapshot['sha256']);
            $this->fail('Snapshot restore no debe escribir mediante un parent symlink.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->root.'/restore-outside/File.php');
        } finally {
            @rmdir($link);
            @unlink($link);
        }
    }

    public function test_14_new_normal_directory_is_created_and_revalidated(): void
    {
        File::ensureDirectoryExists($this->root.'/application/app');
        File::ensureDirectoryExists($this->root.'/staging/app/NewDirectory');
        File::put($this->root.'/staging/app/NewDirectory/File.php', 'safe');
        app(CodeApplyService::class)->apply($this->root.'/application', $this->root.'/staging', [['path' => 'app/NewDirectory/File.php', 'sha256' => hash('sha256', 'safe'), 'size' => 4, 'operation' => 'replace']]);
        $this->assertSame('safe', File::get($this->root.'/application/app/NewDirectory/File.php'));
    }

    public function test_15_lexical_escape_outside_root_is_impossible(): void
    {
        File::ensureDirectoryExists($this->root.'/application');
        $this->expectException(RuntimeException::class);
        app(UpdateDestinationPathPolicy::class)->resolveFile($this->root.'/application', '../outside.php');
    }

    public function test_chunk_session_is_sequential_resumable_and_never_returns_manifest(): void
    {
        $session = app(UpdateSessionStore::class)->create(['installation_id' => 'installation-test', 'manifest' => ['application_code' => 'waadby-access', 'version' => '1.1.0', 'source_commit' => str_repeat('2', 40)], 'signature' => ['signature' => 'hidden'], 'backup_id' => 'backup-test', 'vault_verified' => true, 'package_sha256' => hash('sha256', 'abcdefghijkl'), 'package_size' => 12]);
        $first = app(UpdateSessionStore::class)->append($session['session_id'], 0, 0, 'abcdefgh');
        $this->assertSame(8, $first['received_bytes']);
        $this->assertSame(1, $first['next_chunk_index']);
        $resumed = app(UpdateSessionStore::class)->append($session['session_id'], 1, 8, 'ijkl');
        $this->assertSame(12, $resumed['received_bytes']);
        $this->assertArrayNotHasKey('manifest', $resumed);
        $this->assertArrayNotHasKey('signature', $resumed);
    }

    public function test_wrong_chunk_offset_is_rejected(): void
    {
        $session = app(UpdateSessionStore::class)->create(['installation_id' => 'installation-test', 'manifest' => ['application_code' => 'waadby-access', 'version' => '1.1.0', 'source_commit' => str_repeat('2', 40)], 'signature' => [], 'backup_id' => 'backup-test', 'vault_verified' => false, 'package_sha256' => str_repeat('a', 64), 'package_size' => 8]);
        $this->expectException(RuntimeException::class);
        app(UpdateSessionStore::class)->append($session['session_id'], 0, 4, 'abcd');
    }

    /** @param array<string, string> $files @return array{array<string,mixed>,array<string,mixed>,string} */
    private function release(array $files): array
    {
        $rows = [];
        foreach ($files as $path => $contents) {
            $rows[] = ['path' => $path, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'operation' => 'replace'];
        }
        $filesJson = app(ReleaseCanonicalizer::class)->json($rows)."\n";
        $package = $this->root.'/package-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $zip->open($package, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(ReleasePackageVerifier::FILES_MANIFEST, $filesJson);
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();
        $manifest = $this->manifest(hash_file('sha256', $package), hash('sha256', $filesJson));
        $signature = app(ReleaseSignatureService::class)->sign($manifest, $this->private, 'test-2026');

        return [$manifest, $signature, $package];
    }

    /** @return array<string, mixed> */
    private function manifest(string $packageSha, string $filesSha): array
    {
        return ['manifest_version' => 2, 'application_code' => 'waadby-access', 'version' => '1.1.0', 'minimum_version' => null, 'maximum_version' => null, 'source_commit' => str_repeat('2', 40), 'package_sha256' => $packageSha, 'package' => ['format' => 'zip', 'files_manifest_sha256' => $filesSha], 'deployment' => ['backward_compatible_with_previous' => true, 'requires_operations_agent' => true, 'minimum_operations_agent_version' => '1.1.0'], 'backup_required' => true, 'maintenance_required' => true, 'requirements' => ['php' => '>=8.3', 'extensions' => ['sodium', 'zip']], 'database' => ['migrations' => false, 'rollback_policy' => 'forward_only'], 'configuration' => ['new_variables' => []], 'healthchecks' => ['/health']];
    }

    private function createSymlink(string $target, string $link, bool $directory = false): void
    {
        if (! @symlink($target, $link)) {
            if ($directory && PHP_OS_FAMILY === 'Windows') {
                exec(sprintf('C:\\Windows\\System32\\cmd.exe /c mklink /J "%s" "%s"', $link, $target), $output, $exitCode);
                if ($exitCode === 0 && is_dir($link)) {
                    return;
                }
            }
            $kind = $directory ? 'directorio' : 'fichero';
            $this->markTestSkipped("La plataforma no permite crear el symlink de {$kind} de integracion.");
        }
    }
}
