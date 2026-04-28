<?php

namespace App\Sync\Sources;

use App\Sync\DTO\RemoteAsset;
use App\Sync\ImmichClient;
use Carbon\CarbonImmutable;

abstract class AbstractImmichSource implements SourceBackend
{
    private ?ImmichClient $client = null;

    abstract protected function buildClient(): ImmichClient;

    abstract protected function rawAssets(): iterable;

    protected function client(): ImmichClient
    {
        return $this->client ??= $this->buildClient();
    }

    public function listAssets(): iterable
    {
        foreach ($this->rawAssets() as $asset) {
            yield new RemoteAsset(
                remoteId: $asset['id'],
                filename: $asset['originalFileName'] ?? ($asset['id'] . '.bin'),
                checksum: $asset['checksum'] ?? null,
                mimeType: $asset['originalMimeType'] ?? null,
                takenAt: $this->parseDate($asset['fileCreatedAt'] ?? null),
                modifiedAt: $this->parseDate($asset['fileModifiedAt'] ?? null),
                sizeBytes: isset($asset['exifInfo']['fileSizeInByte']) ? (int) $asset['exifInfo']['fileSizeInByte'] : null,
            );
        }
    }

    public function downloadAsset(string $remoteId): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'immich-sync-');
        if ($tempPath === false) {
            throw new \RuntimeException('Cannot allocate temp file for download');
        }

        $this->client()->downloadOriginalToFile($remoteId, $tempPath);

        return $tempPath;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
