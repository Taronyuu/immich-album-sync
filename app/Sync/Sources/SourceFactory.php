<?php

namespace App\Sync\Sources;

use App\Models\Album;
use InvalidArgumentException;

class SourceFactory
{
    public function for(Album $album): SourceBackend
    {
        return match ($album->source_type) {
            'immich-shared-link' => new ImmichSharedLinkSource(
                baseUrl: $album->source_base_url,
                shareKey: $this->required($album, 'source_share_key'),
                sharePassword: $album->source_share_password,
            ),
            'immich-api-key' => new ImmichApiKeySource(
                baseUrl: $album->source_base_url,
                apiKey: $this->required($album, 'source_api_key'),
                albumId: $this->required($album, 'source_album_id'),
            ),
            default => throw new InvalidArgumentException("Unsupported source_type: {$album->source_type}"),
        };
    }

    private function required(Album $album, string $field): string
    {
        $value = $album->{$field};

        if (empty($value)) {
            throw new InvalidArgumentException("Album #{$album->id} ({$album->source_type}) is missing required field {$field}");
        }

        return (string) $value;
    }
}
