<?php

namespace App\Support;

use App\Models\LoraNodeLocation;

class LoraNodeRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $registry = config('lora_nodes', []);
        foreach (LoraNodeLocation::query()->get() as $location) {
            if (! isset($registry[$location->node_id])) {
                continue;
            }
            $registry[$location->node_id] = array_replace($registry[$location->node_id], [
                'location_label' => $location->location_label,
                'latitude' => $location->latitude === null ? null : (float) $location->latitude,
                'longitude' => $location->longitude === null ? null : (float) $location->longitude,
            ]);
        }

        return $registry;
    }
}
