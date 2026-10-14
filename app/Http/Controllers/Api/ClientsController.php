<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Runner\LocalSyncService;
use App\Runner\RunnerApi;
use Illuminate\Http\Request;
use Throwable;

/** The clients (hosted tenants) this machine works with, and which of them the scheduler automates. */
class ClientsController extends Controller
{
    /** Local copy of the clients. It is refreshed from the hosted app when asked to, or when still empty. */
    public function index(Request $request, RunnerApi $api, LocalSyncService $service): array
    {
        $warning = null;

        if ($request->boolean('refresh') || ! Client::exists()) {
            try {
                foreach ($api->tenants() as $config) {
                    $service->upsertClient($config);
                }
            } catch (Throwable $e) {
                $warning = 'Could not refresh clients from the hosted app: '.mb_substr($e->getMessage(), 0, 200);
            }
        }

        return [
            'data' => Client::orderBy('name')->get(['id', 'name', 'timezone', 'scheduled']),
            'warning' => $warning,
        ];
    }

    /** Choose which clients the scheduled tasks run for. */
    public function schedule(Request $request): array
    {
        $data = $request->validate(['client_ids' => 'present|array', 'client_ids.*' => 'integer']);

        Client::query()->update(['scheduled' => false]);
        Client::whereIn('id', $data['client_ids'])->update(['scheduled' => true]);

        return ['ok' => true, 'scheduled' => Client::where('scheduled', true)->count()];
    }
}
