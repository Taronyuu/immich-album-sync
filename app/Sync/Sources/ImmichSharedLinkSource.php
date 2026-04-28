<?php

namespace App\Sync\Sources;

use App\Sync\ImmichClient;

class ImmichSharedLinkSource extends AbstractImmichSource
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $shareKey,
        private readonly ?string $sharePassword = null,
    ) {}

    protected function buildClient(): ImmichClient
    {
        return ImmichClient::withShareKey($this->baseUrl, $this->shareKey);
    }

    protected function rawAssets(): iterable
    {
        $client = $this->client();
        $link = $client->getMySharedLink($this->sharePassword);

        if (! ($link['allowDownload'] ?? false)) {
            throw new \RuntimeException('Shared link does not allow download');
        }

        if (($link['type'] ?? null) === 'ALBUM' && isset($link['album']['id'])) {
            $album = $client->getAlbum($link['album']['id']);

            foreach (($album['assets'] ?? []) as $asset) {
                yield $asset;
            }

            return;
        }

        foreach (($link['assets'] ?? []) as $asset) {
            yield $asset;
        }
    }
}
