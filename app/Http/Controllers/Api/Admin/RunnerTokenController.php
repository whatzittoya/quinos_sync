<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\RunnerToken;
use App\Models\RunnerImport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class RunnerTokenController extends Controller
{
    public function index(): array
    {
        return ['data' => RunnerToken::latest()->get(), 'imports' => RunnerImport::latest()->limit(50)->get()];
    }
    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $secret = Str::random(64);
        $token = RunnerToken::create($data + ['token_hash' => hash('sha256', $secret), 'created_by' => $request->user()->id]);
        return response()->json(['data' => $token, 'secret' => $secret], 201)->header('Cache-Control', 'no-store');
    }
    public function revoke(RunnerToken $runnerToken): array
    {
        $runnerToken->update(['revoked_at' => now()]);
        return ['ok' => true];
    }
    public function destroy(RunnerToken $runnerToken): array
    {
        $runnerToken->delete();
        return ['ok' => true];
    }
}
