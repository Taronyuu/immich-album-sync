<?php

namespace App\Sync\Sources;

use App\Sync\ImmichClient;

class ImmichApiKeySource extends AbstractImmichSource implements WritableSourceBackend
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $albumId,
    ) {}

    protected function buildClient(): ImmichClient
    {
        return ImmichClient::withApiKey($this->baseUrl, $this->apiKey);
    }

    protected function rawAssets(): iterable
    {
        $album = $this->client()->getAlbum($this->albumId);

        foreach (($album['assets'] ?? []) as $asset) {
            yield $asset;
        }
    }

    public function bulkUploadCheck(array $items): array
    {
        return $this->client()->bulkUploadCheck($items);
    }

    public function uploadAsset(
        string $filePath,
        string $filename,
        string $checksumSha1,
        string $fileCreatedAt,
        string $fileModifiedAt,
        ?string $deviceAssetId = null,
        ?string $deviceId = null,
    ): array {
        return $this->client()->uploadAsset(
            filePath: $filePath,
            filename: $filename,
            checksumSha1: $checksumSha1,
            fileCreatedAt: $fileCreatedAt,
            fileModifiedAt: $fileModifiedAt,
            deviceAssetId: $deviceAssetId,
            deviceId: $deviceId,
        );
    }

    public function addAssetsToSourceAlbum(array $assetIds): void
    {
        if (empty($assetIds)) {
            return;
        }

        $this->client()->addAssetsToAlbum($this->albumId, $assetIds);
    }
}
