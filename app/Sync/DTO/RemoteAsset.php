<?php

namespace App\Sync\DTO;

use Carbon\CarbonImmutable;

final readonly class RemoteAsset
{
    public function __construct(
        public string $remoteId,
        public string $filename,
        public ?string $checksum,
        public ?string $mimeType,
        public ?CarbonImmutable $takenAt,
        public ?CarbonImmutable $modifiedAt,
        public ?int $sizeBytes = null,
    ) {}
}
