<?php

declare(strict_types=1);

namespace App\Game\Field\Repository;

use App\Game\Field\TileFeature;
use Doctrine\DBAL\Connection;

final readonly class TileRepository
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * Batch-load tile features for multiple tile IDs.
     *
     * @param list<string> $tileIds
     * @return array<string, list<TileFeature>> Map of tileId => features
     */
    public function getFeaturesByTileIds(array $tileIds): array
    {
        if ($tileIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT tile_id, features FROM field.tile WHERE tile_id = ANY(:ids)',
            ['ids' => '{' . implode(',', $tileIds) . '}'],
        );

        $result = [];
        foreach ($rows as $row) {
            $features = json_decode($row['features'], true);
            if (\is_array($features) && $features !== []) {
                $result[$row['tile_id']] = array_map(
                    static fn(string $f) => TileFeature::from($f),
                    $features,
                );
            }
        }

        return $result;
    }
}
