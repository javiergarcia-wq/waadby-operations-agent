<?php

namespace Tests\Package\Operations\Unit;

use RuntimeException;
use Tests\TestCase;
use Waadby\OperationsAgent\Vault\VaultEnvelopeCipher;

class VaultEnvelopeCipherTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'base64:'.base64_encode(str_repeat('v', 32));
        config(['waadby_operations.vault.chunk_bytes' => 65536]);
    }

    public function test_encrypts_and_decrypts_small_backup(): void
    {
        [$envelope, $encrypted] = $this->encrypt('hello-vault');
        $this->assertSame('hello-vault', $this->decrypt($encrypted)['plain']);
        $this->assertSame(11, $envelope['source_size']);
    }

    public function test_processes_multiple_chunks(): void
    {
        $plain = random_bytes(200000);
        [, $encrypted] = $this->encrypt($plain);
        $this->assertSame($plain, $this->decrypt($encrypted)['plain']);
    }

    public function test_wrong_key_fails(): void
    {
        [, $encrypted] = $this->encrypt('secret');
        $this->expectException(RuntimeException::class);
        $this->decrypt($encrypted, 'base64:'.base64_encode(str_repeat('x', 32)));
    }

    public function test_altered_header_fails_authentication(): void
    {
        [, $encrypted] = $this->encrypt('secret');
        $altered = str_replace('billing', 'billinx', $encrypted);
        $this->expectException(RuntimeException::class);
        $this->decrypt($altered);
    }

    public function test_altered_ciphertext_fails_authentication(): void
    {
        [, $encrypted] = $this->encrypt(str_repeat('a', 1000));
        $encrypted[strlen($encrypted) - 10] = chr(ord($encrypted[strlen($encrypted) - 10]) ^ 1);
        $this->expectException(RuntimeException::class);
        $this->decrypt($encrypted);
    }

    public function test_truncated_stream_fails(): void
    {
        [, $encrypted] = $this->encrypt('secret');
        $this->expectException(RuntimeException::class);
        $this->decrypt(substr($encrypted, 0, -5));
    }

    public function test_missing_final_tag_fails(): void
    {
        [, $encrypted] = $this->encrypt('secret');
        $finalLength = unpack('Nlength', substr($encrypted, -21, 4))['length'];
        $this->expectException(RuntimeException::class);
        $this->decrypt(substr($encrypted, 0, -(4 + $finalLength)));
    }

    public function test_source_sha_is_preserved(): void
    {
        $plain = random_bytes(512);
        [$result, $encrypted] = $this->encrypt($plain);
        $this->assertSame(hash('sha256', $plain), $result['source_sha256']);
        $this->assertSame($result['source_sha256'], $this->decrypt($encrypted)['result']['source_sha256']);
    }

    public function test_source_size_is_preserved(): void
    {
        $plain = random_bytes(777);
        [$result, $encrypted] = $this->encrypt($plain);
        $this->assertSame(777, $result['source_size']);
        $this->assertSame(777, $this->decrypt($encrypted)['result']['source_size']);
    }

    public function test_key_is_not_present_in_envelope(): void
    {
        [, $encrypted] = $this->encrypt('secret');
        $this->assertStringNotContainsString(str_repeat('v', 32), $encrypted);
        $this->assertStringNotContainsString($this->key, $encrypted);
    }

    public function test_plaintext_is_not_present_in_envelope(): void
    {
        [, $encrypted] = $this->encrypt('very-distinct-plaintext-marker');
        $this->assertStringNotContainsString('very-distinct-plaintext-marker', $encrypted);
    }

    public function test_key_id_is_safe_header_metadata(): void
    {
        [, $encrypted] = $this->encrypt('secret');
        $this->assertStringContainsString('vault-key-2026', $encrypted);
        $this->assertSame('vault-key-2026', $this->decrypt($encrypted)['result']['header']['key_id']);
    }

    /** @return array{array<string, mixed>, string} */
    private function encrypt(string $plain): array
    {
        $source = tmpfile();
        $destination = tmpfile();
        fwrite($source, $plain);
        rewind($source);
        $result = app(VaultEnvelopeCipher::class)->encrypt($source, $destination, [
            'source_backup_id' => '00000000-0000-0000-0000-000000000001', 'application_code' => 'billing',
            'application_version' => '1.0.0', 'source_sha256' => hash('sha256', $plain), 'source_size' => strlen($plain),
        ], $this->key, 'vault-key-2026', 65536);
        rewind($destination);
        $encrypted = stream_get_contents($destination);
        fclose($source);
        fclose($destination);

        return [$result, $encrypted];
    }

    /** @return array{result: array<string, mixed>, plain: string} */
    private function decrypt(string $encrypted, ?string $key = null): array
    {
        $source = tmpfile();
        $destination = tmpfile();
        fwrite($source, $encrypted);
        rewind($source);
        $result = app(VaultEnvelopeCipher::class)->decrypt($source, $destination, $key ?? $this->key);
        rewind($destination);
        $plain = stream_get_contents($destination);
        fclose($source);
        fclose($destination);

        return compact('result', 'plain');
    }
}
