<?php

namespace Tests\Package\Operations\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Waadby\OperationsAgent\Services\ReleaseManifestValidator;
use Waadby\OperationsAgent\Services\SensitiveConfigurationCipher;
use Waadby\OperationsAgent\Support\ArchivePath;

class AgentPrimitivesTest extends TestCase
{
    #[DataProvider('unsafePaths')]
    public function test_archive_path_rejects_unsafe_names(string $path): void
    {
        $this->expectException(RuntimeException::class);
        ArchivePath::assertSafe($path);
    }

    public static function unsafePaths(): array
    {
        return [
            'parent traversal' => ['../outside'],
            'nested traversal' => ['storage/../../outside'],
            'unix absolute' => ['/etc/passwd'],
            'windows drive' => ['C:\\secrets\\file'],
            'empty path' => [''],
            'empty segment' => ['storage//file'],
        ];
    }

    #[DataProvider('safePaths')]
    public function test_archive_path_accepts_portable_relative_names(string $path, string $expected): void
    {
        $this->assertSame($expected, ArchivePath::assertSafe($path));
    }

    public static function safePaths(): array
    {
        return [
            ['manifest.json', 'manifest.json'],
            ['storage/app/file.txt', 'storage/app/file.txt'],
            ['database\\database.sql', 'database/database.sql'],
        ];
    }

    public function test_plain_environment_file_is_detected_at_any_depth(): void
    {
        $this->assertTrue(ArchivePath::isPlainEnvironmentFile('snapshot/.env'));
        $this->assertFalse(ArchivePath::isPlainEnvironmentFile('configuration.enc'));
    }

    public function test_cipher_fails_closed_without_backup_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se puede crear un backup de desastre');
        app(SensitiveConfigurationCipher::class)->encrypt(['DB_PASSWORD' => 'synthetic'], '');
    }

    public function test_cipher_uses_authenticated_sodium_envelope_without_plaintext(): void
    {
        $envelope = app(SensitiveConfigurationCipher::class)->encrypt(['DB_PASSWORD' => 'synthetic-secret'], 'base64:'.base64_encode(str_repeat('k', 32)));
        $decoded = json_decode($envelope, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('sodium_secretbox', $decoded['cipher']);
        $this->assertSame(1, $decoded['format_version']);
        $this->assertStringNotContainsString('synthetic-secret', $envelope);
        $this->assertNotEmpty(base64_decode($decoded['nonce'], true));
        $this->assertNotEmpty(base64_decode($decoded['ciphertext'], true));
    }

    public function test_cipher_accepts_hex_key_and_rejects_short_key(): void
    {
        $cipher = app(SensitiveConfigurationCipher::class);
        $this->assertTrue($cipher->hasValidKey(str_repeat('ab', 32)));
        $this->assertFalse($cipher->hasValidKey('short'));
    }

    public function test_release_manifest_validator_accepts_v1_contract(): void
    {
        $this->assertSame([], app(ReleaseManifestValidator::class)->errors($this->validManifest()));
    }

    #[DataProvider('requiredManifestFields')]
    public function test_release_manifest_validator_requires_each_contract_field(string $field): void
    {
        $manifest = $this->validManifest();
        unset($manifest[$field]);

        $this->assertNotEmpty(app(ReleaseManifestValidator::class)->errors($manifest));
    }

    public static function requiredManifestFields(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'manifest_version', 'application_code', 'version', 'source_commit', 'package_sha256',
            'backup_required', 'maintenance_required', 'database', 'configuration', 'healthchecks',
        ]);
    }

    public function test_release_manifest_rejects_secret_default(): void
    {
        $manifest = $this->validManifest();
        $manifest['configuration']['new_variables'][] = [
            'name' => 'NEW_SECRET', 'required' => true, 'sensitive' => true, 'description' => 'Secret', 'default' => 'forbidden',
        ];

        $this->assertStringContainsString('no puede declarar default', implode(' ', app(ReleaseManifestValidator::class)->errors($manifest)));
    }

    public function test_release_manifest_rejects_malformed_sha_and_variable_name(): void
    {
        $manifest = $this->validManifest();
        $manifest['package_sha256'] = 'bad';
        $manifest['configuration']['new_variables'][] = [
            'name' => '../ENV', 'required' => false, 'sensitive' => false, 'description' => 'Bad',
        ];
        $errors = implode(' ', app(ReleaseManifestValidator::class)->errors($manifest));

        $this->assertStringContainsString('64 caracteres', $errors);
        $this->assertStringContainsString('name no es valido', $errors);
    }

    private function validManifest(): array
    {
        return [
            'manifest_version' => 1,
            'application_code' => 'waadby-access',
            'version' => '1.1.0',
            'minimum_version' => '1.0.0',
            'maximum_version' => null,
            'source_commit' => str_repeat('1', 40),
            'package_sha256' => str_repeat('a', 64),
            'backup_required' => true,
            'maintenance_required' => true,
            'database' => ['migrations' => true],
            'configuration' => ['new_variables' => []],
            'healthchecks' => ['/health'],
        ];
    }
}
