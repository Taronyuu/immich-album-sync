<?php

namespace App\Sync\Sources;

interface SourceBackend
{
    /**
     * @return iterable<\App\Sync\DTO\RemoteAsset>
     */
    public function listAssets(): iterable;

    /**
     * Streams the asset's original bytes to a temp file and returns its path.
     * Caller owns the file and must unlink it.
     */
    public function downloadAsset(string $remoteId): string;
}
