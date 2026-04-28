<?php

namespace App\Sync\Sources;

interface WritableSourceBackend extends SourceBackend
{
    /**
     * @param  array<int, array{id: string, checksum: string}>  $items
     * @return array  raw /api/assets/bulk-upload-check response
     */
    public function bulkUploadCheck(array $items): array;

    /**
     * Upload a file from a local temp path to the source Immich.
     *
     * @return array  raw /api/assets response (must include 'id')
     */
    public function uploadAsset(
        string $filePath,
        string $filename,
        string $checksumSha1,
        string $fileCreatedAt,
        string $fileModifiedAt,
        ?string $deviceAssetId = null,
        ?string $deviceId = null,
    ): array;

    /**
     * Add asset IDs to the *source* album this backend was constructed for.
     *
     * @param  array<int, string>  $assetIds
     */
    public function addAssetsToSourceAlbum(array $assetIds): void;
}
