<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Runner\LocalSyncService;
use App\Runner\RunnerApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
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

    /** The clients as the hosted app has them (it owns them), with whether a mobile API key is set. Refreshes the local copy. */
    public function hosted(RunnerApi $api, LocalSyncService $service): JsonResponse
    {
        return $this->hostedCall(function () use ($api, $service) {
            $tenants = $api->tenants();
            foreach ($tenants as $config) {
                $service->upsertClient($config);
            }

            return ['data' => array_map(fn ($t) => $this->public($t), $tenants)];
        });
    }

    /** Creates the client on the hosted app, then keeps a local copy like a refresh would. */
    public function store(Request $request, RunnerApi $api, LocalSyncService $service): JsonResponse
    {
        $data = $this->validated($request);

        return $this->hostedCall(function () use ($api, $service, $data) {
            $tenant = $api->createTenant($data)['tenant'];
            $service->upsertClient($tenant);

            return ['data' => $this->public($tenant)];
        }, 201);
    }

    public function update(Request $request, int $id, RunnerApi $api, LocalSyncService $service): JsonResponse
    {
        $data = $this->validated($request);

        return $this->hostedCall(function () use ($api, $service, $data, $id) {
            $tenant = $api->updateTenant($id, $data)['tenant'];
            $service->upsertClient($tenant);

            return ['data' => $this->public($tenant)];
        });
    }

    /** A new mobile API key. Hosted keeps only its hash, so the answer is the one chance to show it. */
    public function generateKey(int $id, RunnerApi $api): JsonResponse
    {
        return $this->hostedCall(function () use ($api, $id) {
            $result = $api->generateMobileToken($id);

            return ['token' => $result['token'], 'url' => $result['url'], 'data' => $this->public($result['tenant'])];
        });
    }

    public function removeKey(int $id, RunnerApi $api): JsonResponse
    {
        return $this->hostedCall(fn () => ['data' => $this->public($api->removeMobileToken($id)['tenant'])]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'quinos_email' => 'nullable|email|max:255',
            'quinos_password' => 'nullable|string|max:255',
            'timezone' => 'required|timezone:all',
        ]);
    }

    /** The Quinos password stays out of the browser. */
    private function public(array $tenant): array
    {
        return [
            'id' => $tenant['id'], 'name' => $tenant['name'], 'quinos_email' => $tenant['quinos_email'] ?? null,
            'has_quinos_password' => filled($tenant['quinos_password'] ?? null), 'timezone' => $tenant['timezone'],
            'has_mobile_api_token' => (bool) ($tenant['has_mobile_api_token'] ?? false),
        ];
    }

    private function hostedCall(callable $call, int $status = 200): JsonResponse
    {
        try {
            return response()->json($call(), $status);
        } catch (RuntimeException $e) {
            return response()->json(['message' => mb_substr($e->getMessage(), 0, 300)], 422);
        }
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
