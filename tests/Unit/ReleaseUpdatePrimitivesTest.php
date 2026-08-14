<?php

namespace Tests\Package\Operations\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Waadby\OperationsAgent\Services\ReleaseManifestValidator;
use Waadby\OperationsAgent\Updates\CodeApplyService;
use Waadby\OperationsAgent\Updates\CodeSnapshotService;
use Waadby\OperationsAgent\Updates\InstalledReleaseStore;
use Waadby\OperationsAgent\Updates\ReleaseCanonicalizer;
use Waadby\OperationsAgent\Updates\ReleasePackageVerifier;
use Waadby\OperationsAgent\Updates\ReleasePathPolicy;
use Waadby\OperationsAgent\Updates\ReleaseSignatureService;
use Waadby\OperationsAgent\Updates\UpdateSessionStore;
use ZipArchive;

final class ReleaseUpdatePrimitivesTest extends TestCase
{
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
}
