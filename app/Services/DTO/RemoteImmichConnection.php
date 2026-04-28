<?php

namespace App\Services\DTO;

final readonly class RemoteImmichConnection
{
    public function __construct(
        public string $baseUrl,
        public string $immichUserId,
        public string $email,
        public ?string $name,
        public bool $isAdmin,
        public string $apiKeyId,
        public string $apiKeySecret,
    ) {}
}
