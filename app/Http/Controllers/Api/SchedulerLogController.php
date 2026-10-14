<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalSyncRunItem;
use Illuminate\Http\Request;

/** What the local runner has done, one row per client and package, filterable by client and date. */
class SchedulerLogController extends Controller
{
    public function __invoke(Request $request): array
    {
        $f = $request->validate([
            'client_id' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|in:running,success,failed',
            'trigger' => 'nullable|in:scheduled,manual',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $page = LocalSyncRunItem::query()
            ->with('run:id,trigger')
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('started_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('started_at', '<=', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['trigger'] ?? null, fn ($q, $v) => $q->whereHas('run', fn ($r) => $r->where('trigger', $v)))
            ->orderByDesc('id')
            ->paginate($f['per_page'] ?? 20);

        return [
            'data' => $page->getCollection()->map(fn (LocalSyncRunItem $i) => [
                'id' => $i->id,
                'client_id' => $i->tenant_id,
                'client' => $i->tenant_name,
                'package' => $i->package,
                'trigger' => $i->run?->trigger ?? 'manual',
                'dates' => $i->dates,
                'status' => $i->status,
                'rows' => $i->rows,
                'started_at' => $i->started_at?->toIso8601String(),
                'finished_at' => $i->finished_at?->toIso8601String(),
                'error' => $i->error,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }
}
