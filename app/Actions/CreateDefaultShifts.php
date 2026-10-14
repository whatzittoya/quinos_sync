<?php

namespace App\Actions;

use App\Models\Client;

class CreateDefaultShifts
{
    /**
     * Starting values copied from the Zali weekly sales PDF. The 10:00 and
     * 17:00 cutoffs are inferred from sample data, not confirmed: clients can
     * edit them in Settings.
     */
    public const DEFAULTS = [
        ['label' => 'Breakfast 8AM - 11:59AM', 'starts_at' => '00:00', 'ends_at' => '10:00'],
        ['label' => 'Lunch 12.00 PM - 5 PM', 'starts_at' => '10:00', 'ends_at' => '17:00'],
        ['label' => 'Dinner 5.01 PM - 11.59 PM', 'starts_at' => '17:00', 'ends_at' => '24:00'],
    ];

    public function __invoke(Client $client): void
    {
        foreach (self::DEFAULTS as $position => $shift) {
            $client->shifts()->create($shift + ['position' => $position]);
        }
    }
}
