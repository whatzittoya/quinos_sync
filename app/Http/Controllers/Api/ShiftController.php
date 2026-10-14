<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\UsesCurrentClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientShift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    use UsesCurrentClient;

    public function show(): array
    {
        $client = $this->currentClient();

        return $this->payload($client);
    }

    public function update(Request $request): array
    {
        $client = $this->currentClient();

        $data = $request->validate([
            'shift_assign_by' => ['required', Rule::in(['open_time', 'close_time'])],
            'shifts' => ['required', 'array', 'min:1', 'max:12'],
            'shifts.*.label' => ['required', 'string', 'max:100'],
            'shifts.*.starts_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'shifts.*.ends_at' => ['required', 'regex:/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/'],
        ]);

        foreach ($data['shifts'] as $i => $shift) {
            if ($shift['starts_at'] >= $shift['ends_at']) {
                throw ValidationException::withMessages([
                    "shifts.$i.ends_at" => 'End time must be after start time.',
                ]);
            }
        }

        DB::transaction(function () use ($client, $data) {
            $client->update(['shift_assign_by' => $data['shift_assign_by']]);
            ClientShift::where('client_id', $client->id)->delete();

            foreach (array_values($data['shifts']) as $position => $shift) {
                $client->shifts()->create($shift + ['position' => $position]);
            }
        });

        return $this->payload($client->fresh());
    }

    private function payload(Client $client): array
    {
        return [
            'shift_assign_by' => $client->shift_assign_by,
            'shifts' => $client->shifts()->get(['label', 'starts_at', 'ends_at']),
        ];
    }
}
