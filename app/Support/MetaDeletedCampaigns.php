<?php

namespace App\Support;

class MetaDeletedCampaigns
{
    private const STORAGE_FILE = 'meta_deleted_campaigns.json';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $path = storage_path('app/'.self::STORAGE_FILE);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $decoded
        ))));
    }

    public static function contains(?string $metaId): bool
    {
        $metaId = trim((string) $metaId);

        if ($metaId === '') {
            return false;
        }

        return in_array($metaId, self::all(), true);
    }

    public static function remember(?string $metaId): void
    {
        $metaId = trim((string) $metaId);

        if ($metaId === '') {
            return;
        }

        $ids = self::all();
        $ids[] = $metaId;

        $path = storage_path('app/'.self::STORAGE_FILE);
        file_put_contents($path, json_encode(array_values(array_unique($ids)), JSON_PRETTY_PRINT));
    }
}
