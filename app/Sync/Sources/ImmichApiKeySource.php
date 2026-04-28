<?php

namespace App\Sync\Sources;

use App\Sync\ImmichClient;

class ImmichApiKeySource extends AbstractImmichSource
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
}
