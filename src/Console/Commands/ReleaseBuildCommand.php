<?php

namespace Waadby\OperationsAgent\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Waadby\OperationsAgent\Updates\ReleaseCanonicalizer;
use Waadby\OperationsAgent\Updates\ReleasePackageVerifier;
use Waadby\OperationsAgent\Updates\ReleasePathPolicy;
use Waadby\OperationsAgent\Updates\ReleaseSignatureService;
use ZipArchive;

final class ReleaseBuildCommand extends Command
{
    protected $signature = 'waadby:operations:release:build
        {--release-version= : Semantic release version (avoids Symfony global --version)}
        {--output= : Private output directory}
        {--previous-manifest= : Previous waadby-release-files.json}
        {--include-vendor : Include the installed production vendor tree}
        {--migrations : Declare that the release runs migrations}
        {--rollback-policy=forward_only : forward_only or rollback_safe}
        {--healthcheck=* : Same-origin healthcheck path}';

    protected $description = 'Build a deterministic, signed WAADBY Release Manifest V2 package';

    public function handle(ReleaseSignatureService $signatures, ReleaseCanonicalizer $canonicalizer, ReleasePathPolicy $paths): int
    {
        try {
            $version = trim((string) $this->option('release-version'));
            $output = trim((string) $this->option('output'));
            if ($version === '' || $output === '') {
                throw new RuntimeException('--release-version y --output son obligatorios.');
            }
            $output = $this->absolute($output);
            if (! is_dir($output) && ! mkdir($output, 0700, true) && ! is_dir($output)) {
                throw new RuntimeException('No se pudo crear el directorio de salida privado.');
            }
            $files = $this->collect((bool) $this->option('include-vendor'), $paths, $output);
            $previous = $this->previousPaths($this->option('previous-manifest'));
            foreach (array_diff($previous, array_keys($files)) as $deleted) {
                $paths->assertSafe($deleted);
                $files[$deleted] = null;
            }
            ksort($files, SORT_STRING);
            $fileManifest = [];
            foreach ($files as $path => $absolute) {
                $fileManifest[] = $absolute === null
                    ? ['path' => $path, 'sha256' => str_repeat('0', 64), 'size' => 0, 'operation' => 'delete']
                    : ['path' => $path, 'sha256' => hash_file('sha256', $absolute), 'size' => filesize($absolute), 'operation' => 'replace'];
            }
            $filesJson = $canonicalizer->json($fileManifest)."\n";
            $filesSha = hash('sha256', $filesJson);
            $package = $output.DIRECTORY_SEPARATOR.'waadby-release-'.$version.'.zip';
            $this->writePackage($package, $files, $filesJson);
            $healthchecks = $this->option('healthcheck') ?: ['/health'];
            $rollback = (string) $this->option('rollback-policy');
            if (! in_array($rollback, ['forward_only', 'rollback_safe'], true)) {
                throw new RuntimeException('--rollback-policy debe ser forward_only o rollback_safe.');
            }
            $manifest = [
                'manifest_version' => 2,
                'application_code' => (string) config('waadby_operations.application.code'),
                'version' => $version,
                'minimum_version' => null,
                'maximum_version' => null,
                'source_commit' => $this->commit(),
                'package_sha256' => hash_file('sha256', $package),
                'package' => ['format' => 'zip', 'files_manifest_sha256' => $filesSha],
                'deployment' => [
                    'backward_compatible_with_previous' => false,
                    'requires_operations_agent' => true,
                    'minimum_operations_agent_version' => '1.1.0',
                ],
                'backup_required' => true,
                'maintenance_required' => true,
                'requirements' => ['php' => '>=8.3', 'extensions' => ['sodium', 'zip']],
                'database' => ['migrations' => (bool) $this->option('migrations'), 'rollback_policy' => $rollback],
                'configuration' => ['new_variables' => []],
                'healthchecks' => array_values($healthchecks),
            ];
            $private = (string) config('waadby_operations.updates.signing_private_key');
            $keyId = (string) config('waadby_operations.updates.signing_key_id');
            if ($private === '' || $keyId === '') {
                throw new RuntimeException('La clave privada de build y su key_id deben estar provisionadas por entorno seguro.');
            }
            $signature = $signatures->sign($manifest, $private, $keyId);
            $this->writeJson($output.DIRECTORY_SEPARATOR.'waadby-release-manifest.json', $manifest);
            $this->writeJson($output.DIRECTORY_SEPARATOR.'waadby-release-signature.json', $signature);
            @chmod($package, 0600);
            $this->components->info("Release {$version} firmado con {$keyId}; ".count($fileManifest).' operaciones.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, string|null> */
    private function collect(bool $includeVendor, ReleasePathPolicy $paths, string $output): array
    {
        $root = base_path();
        $result = [];
        $excludedDirectories = ['.git', 'storage', 'backups', 'node_modules', 'logs', 'vault', 'waadby-vault'];
        if (! $includeVendor) {
            $excludedDirectories[] = 'vendor';
        }
        $finder = Finder::create()->files()->in($root)->exclude($excludedDirectories)->ignoreVCS(true)->ignoreDotFiles(false);
        foreach ($finder as $file) {
            $absolute = $file->getRealPath();
            if (! is_string($absolute) || str_starts_with(strtolower($absolute), strtolower($output.DIRECTORY_SEPARATOR))) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $lower = strtolower($relative);
            $excluded = str_starts_with($lower, '.env')
                || preg_match('#^(?:storage|backups|node_modules|logs|vault|waadby-vault)(?:/|$)#', $lower)
                || preg_match('#^packages/waadby-operations-agent/src/(?:updates|jobs/executeupdatesession\.php|http/controllers/remoteupdatecontroller\.php|http/middleware/verifyremoteoperationsrequest\.php)(?:/|$)#', $lower)
                || (! $includeVendor && preg_match('#^vendor(?:/|$)#', $lower));
            if ($excluded) {
                continue;
            }
            $paths->assertSafe($relative, allowUpdaterRuntime: true);
            $result[$relative] = $absolute;
        }

        return $result;
    }

    /** @return list<string> */
    private function previousPaths(mixed $path): array
    {
        if (! is_string($path) || trim($path) === '') {
            return [];
        }
        $value = json_decode((string) file_get_contents($this->absolute($path)), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('--previous-manifest debe apuntar a un files manifest valido.');
        }

        return array_values(array_filter(array_map(fn (mixed $row): mixed => is_array($row) ? ($row['path'] ?? null) : null, $value), 'is_string'));
    }

    /** @param array<string, string|null> $files */
    private function writePackage(string $path, array $files, string $filesJson): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el ZIP del release.');
        }
        try {
            $zip->addFromString(ReleasePackageVerifier::FILES_MANIFEST, $filesJson);
            $zip->setMtimeName(ReleasePackageVerifier::FILES_MANIFEST, 315532800);
            foreach ($files as $relative => $source) {
                if ($source === null) {
                    continue;
                }
                if (! $zip->addFile($source, $relative)) {
                    throw new RuntimeException('No se pudo incluir un fichero en el release.');
                }
                $zip->setMtimeName($relative, 315532800);
            }
        } finally {
            $zip->close();
        }
    }

    private function writeJson(string $path, array $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('No se pudo escribir un artefacto de release completo.');
        }
        @chmod($path, 0600);
    }

    private function commit(): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->setTimeout(5);
        $process->mustRun();
        $value = trim($process->getOutput());
        if (! preg_match('/^[a-f0-9]{40}$/', $value)) {
            throw new RuntimeException('No se pudo determinar source_commit.');
        }

        return $value;
    }

    private function absolute(string $path): string
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) ? $path : base_path($path);
    }
}
