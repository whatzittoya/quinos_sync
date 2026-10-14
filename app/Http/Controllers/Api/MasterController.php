<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\UsesCurrentClient;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Item;
use App\Models\SalesType;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Read-only view of the reference data synced from Quinos. */
class MasterController extends Controller
{
    use UsesCurrentClient;

    /** kind => [model, searchable columns, sort column] */
    private const KINDS = [
        'items' => [Item::class, ['code', 'name'], 'name'],
        'departments' => [Department::class, ['name'], 'name'],
        'categories' => [Category::class, ['name'], 'name'],
        'sales-types' => [SalesType::class, ['name'], 'name'],
        'contacts' => [Contact::class, ['name'], 'name'],
    ];

    public function index(Request $request, string $kind): array
    {
        $this->currentClient();

        abort_unless(isset(self::KINDS[$kind]), 404);

        [$model, $searchable, $sort] = self::KINDS[$kind];

        $search = trim((string) $request->query('search', ''));

        $page = $model::query()
            ->when($search !== '', function ($query) use ($searchable, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(function ($q) use ($searchable, $like) {
                    foreach ($searchable as $column) {
                        $q->orWhere($column, 'like', $like);
                    }
                });
            })
            ->orderBy($sort)
            ->paginate(50)
            ->through(fn ($row) => collect($row->toArray())->except(['client_id', 'created_at', 'updated_at'])->all());

        return [
            'data' => $page->items(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            // The sync rewrites updated_at on every row it sees, so the newest one is the last pull.
            'last_synced_at' => ($latest = $model::query()->max('updated_at')) ? Carbon::parse($latest)->toIso8601String() : null,
        ];
    }
}
