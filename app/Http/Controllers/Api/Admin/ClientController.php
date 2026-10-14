<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\CreateDefaultShifts;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index()
    {
        return ClientResource::collection(Client::orderBy('name')->get());
    }

    public function store(Request $request, CreateDefaultShifts $defaults): JsonResponse
    {
        $client = Client::create($this->validated($request));
        $defaults($client);

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $data = $this->validated($request, updating: true);

        // An empty password field means "keep the stored one".
        if (blank($data['quinos_password'] ?? null)) {
            unset($data['quinos_password']);
        }

        $client->update($data);

        return new ClientResource($client);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quinos_email' => ['nullable', 'email', 'max:255'],
            'quinos_password' => ['nullable', 'string', 'max:255'],
            'quinos_company_id' => ['nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone:all'],
            'shift_assign_by' => ['sometimes', Rule::in(['open_time', 'close_time'])],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
