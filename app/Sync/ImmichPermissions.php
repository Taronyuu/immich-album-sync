<?php

namespace App\Sync;

final class ImmichPermissions
{
    public const SYNC_SCOPES = [
        'asset.upload',
        'asset.read',
        'asset.download',
        'album.create',
        'album.read',
        'album.update',
        'albumAsset.create',
        'albumAsset.delete',
    ];

    public const AUTO_PROVISION_KEY_NAME = 'Immich Album Sync (auto-provisioned)';
}
