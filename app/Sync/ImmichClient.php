<?php

namespace App\Sync;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ImmichClient
{
    private ?CookieJar $cookieJar = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly ?string $bearerToken = null,
        private readonly ?string $shareKey = null,
        private readonly int $timeoutSeconds = 60,
    ) {}

    public static function withApiKey(string $baseUrl, string $apiKey): self
    {
        return new self($baseUrl, apiKey: $apiKey);
    }

    public static function withBearerToken(string $baseUrl, string $bearerToken): self
    {
        return new self($baseUrl, bearerToken: $bearerToken);
    }

    public static function withShareKey(string $baseUrl, string $shareKey): self
    {
        return new self($baseUrl, shareKey: $shareKey);
    }

    public static function anonymous(string $baseUrl): self
    {
        return new self($baseUrl);
    }

    public function login(string $email, string $password): array
    {
        return $this->request()
            ->post($this->url('/api/auth/login'), ['email' => $email, 'password' => $password])
            ->throw()
            ->json();
    }

    public function me(): array
    {
        return $this->request()->get($this->url('/api/users/me'))->throw()->json();
    }

    public function createApiKey(string $name, array $permissions): array
    {
        return $this->request()
            ->post($this->url('/api/api-keys'), ['name' => $name, 'permissions' => $permissions])
            ->throw()
            ->json();
    }

    public function deleteApiKey(string $id): void
    {
        $this->request()->delete($this->url("/api/api-keys/{$id}"))->throw();
    }

    public function getMySharedLink(?string $password = null): array
    {
        if ($password !== null && $password !== '') {
            $this->request()
                ->post($this->url('/api/shared-links/login'), ['password' => $password])
                ->throw();
        }

        return $this->request()->get($this->url('/api/shared-links/me'))->throw()->json();
    }

    public function getAlbum(string $id): array
    {
        return $this->request()->get($this->url("/api/albums/{$id}"))->throw()->json();
    }

    public function bulkUploadCheck(array $items): array
    {
        return $this->request()
            ->post($this->url('/api/assets/bulk-upload-check'), ['assets' => $items])
            ->throw()
            ->json();
    }

    public function downloadOriginalToFile(string $assetId, string $destinationPath): void
    {
        $response = $this->request()
            ->withOptions(['sink' => $destinationPath])
            ->get($this->url("/api/assets/{$assetId}/original"));

        if (! $response->successful()) {
            @unlink($destinationPath);
            throw new RequestException($response);
        }
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
        $stream = fopen($filePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Cannot open {$filePath} for upload");
        }

        try {
            return $this->request()
                ->withHeaders(['x-immich-checksum' => $checksumSha1])
                ->attach('assetData', $stream, $filename)
                ->post($this->url('/api/assets'), [
                    'deviceAssetId' => $deviceAssetId ?? ('immich-album-sync-' . sha1($filename . $checksumSha1)),
                    'deviceId' => $deviceId ?? 'immich-album-sync',
                    'fileCreatedAt' => $fileCreatedAt,
                    'fileModifiedAt' => $fileModifiedAt,
                ])
                ->throw()
                ->json();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function createAlbum(string $name, ?string $description = null): array
    {
        return $this->request()
            ->post($this->url('/api/albums'), array_filter([
                'albumName' => $name,
                'description' => $description,
            ]))
            ->throw()
            ->json();
    }

    public function listMyAlbums(): array
    {
        return $this->request()->get($this->url('/api/albums'))->throw()->json();
    }

    public function listSharedAlbums(): array
    {
        return $this->request()->get($this->url('/api/albums'), ['shared' => 'true'])->throw()->json();
    }

    public function addAssetsToAlbum(string $albumId, array $assetIds): array
    {
        return $this->request()
            ->put($this->url("/api/albums/{$albumId}/assets"), ['ids' => array_values($assetIds)])
            ->throw()
            ->json();
    }

    public function removeAssetsFromAlbum(string $albumId, array $assetIds): array
    {
        return $this->request()
            ->delete($this->url("/api/albums/{$albumId}/assets"), ['ids' => array_values($assetIds)])
            ->throw()
            ->json();
    }

    public function trashAssets(array $assetIds): void
    {
        $this->request()
            ->post($this->url('/api/assets/trash'), ['ids' => array_values($assetIds)])
            ->throw();
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()->timeout($this->timeoutSeconds);

        if ($this->apiKey !== null) {
            $request = $request->withHeaders(['x-api-key' => $this->apiKey]);
        }

        if ($this->bearerToken !== null) {
            $request = $request->withToken($this->bearerToken);
        }

        if ($this->shareKey !== null) {
            $request = $request->withHeaders(['x-immich-share-key' => $this->shareKey]);
            $this->cookieJar ??= new CookieJar();
            $request = $request->withOptions(['cookies' => $this->cookieJar]);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    public function response(): ?Response
    {
        return null;
    }
}
