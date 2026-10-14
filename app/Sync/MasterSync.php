<?php

namespace App\Sync;

use App\Models\Category;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Item;
use App\Models\SalesType;
use App\Services\Quinos\QuinosClient;
use Illuminate\Database\Eloquent\Model;

/**
 * Copies the reference data reports depend on: departments + categories,
 * items (which item belongs to which department), sales types and contacts.
 *
 * Mirrors Quinos: rows that no longer exist there are removed here too, but
 * only after a list was read completely and was not empty.
 */
class MasterSync
{
    /** Kinds that can be refreshed on their own: key => [label, model whose updated_at shows the last refresh]. */
    public const KINDS = [
        'departments' => ['Departments & categories', Department::class],
        'items' => ['Items', Item::class],
        'sales_types' => ['Sales types', SalesType::class],
        'contacts' => ['Contacts', Contact::class],
    ];

    public function __construct(private readonly QuinosClient $quinos, private readonly Client $client) {}

    /**
     * @param  list<string>|null  $kinds  null = all of them
     * @return array<string, int> rows per kind
     */
    public function sync(?array $kinds = null): array
    {
        $kinds ??= array_keys(self::KINDS);
        $counts = [];

        foreach (array_keys(self::KINDS) as $kind) {
            if (in_array($kind, $kinds, true)) {
                $counts[$kind] = $this->{lcfirst(str_replace('_', '', ucwords($kind, '_')))}();
            }
        }

        // The "fresh enough" check of day syncs looks at this, so only a full refresh moves it.
        if (count($counts) === count(self::KINDS)) {
            $this->client->forceFill(['master_synced_at' => now()])->save();
        }

        return $counts;
    }

    public function isStale(): bool
    {
        $syncedAt = $this->client->master_synced_at;

        return $syncedAt === null || $syncedAt->lt(now()->subHours(config('sync.master_ttl_hours')));
    }

    private function departments(): int
    {
        $departments = [];
        $categories = [];

        foreach ($this->quinos->rows('/departments') as $row) {
            $departments[] = ['quinos_id' => $row['id'], 'name' => Parse::str($row['name']) ?? ''];

            foreach ($row['categories'] ?? [] as $category) {
                $categories[] = [
                    'quinos_id' => $category['id'],
                    'department_quinos_id' => $category['department_id'] ?? $row['id'],
                    'name' => Parse::str($category['name']) ?? '',
                ];
            }
        }

        $this->mirror(Department::class, $departments, ['name']);
        $this->mirror(Category::class, $categories, ['department_quinos_id', 'name']);

        return count($departments);
    }

    private function items(): int
    {
        $items = [];

        foreach ($this->quinos->rows('/items', ['search' => '', 'type' => '']) as $row) {
            $items[] = [
                'quinos_id' => $row['id'],
                'code' => Parse::str($row['code'] ?? null),
                'name' => Parse::str($row['name'] ?? '') ?? '',
                'category_quinos_id' => $row['category_id'] ?? null,
                'department_quinos_id' => $row['category']['department_id'] ?? null,
                'active' => (bool) ($row['active'] ?? true),
            ];
        }

        $this->mirror(Item::class, $items, ['code', 'name', 'category_quinos_id', 'department_quinos_id', 'active']);

        return count($items);
    }

    private function salesTypes(): int
    {
        $rows = [];

        foreach ($this->quinos->rows('/salesTypes') as $row) {
            $rows[] = [
                'quinos_id' => $row['id'],
                'name' => Parse::str($row['name']) ?? '',
                'active' => (bool) ($row['active'] ?? true),
            ];
        }

        $this->mirror(SalesType::class, $rows, ['name', 'active']);

        return count($rows);
    }

    private function contacts(): int
    {
        $rows = [];

        foreach ($this->quinos->rows('/contacts') as $row) {
            $rows[] = [
                'quinos_id' => $row['id'],
                'name' => Parse::str($row['name']) ?? '',
                'supplier' => (bool) ($row['supplier'] ?? false),
                'customer' => (bool) ($row['customer'] ?? false),
                'active' => (bool) ($row['active'] ?? true),
            ];
        }

        $this->mirror(Contact::class, $rows, ['name', 'supplier', 'customer', 'active']);

        return count($rows);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $update  columns refreshed when the row already exists
     */
    private function mirror(string $model, array $rows, array $update): void
    {
        if ($rows === []) {
            return; // never wipe a table because a list came back empty
        }

        $now = now();

        foreach (array_chunk($rows, 200) as $chunk) {
            $model::upsert(
                array_map(fn ($r) => $r + ['client_id' => $this->client->id, 'created_at' => $now, 'updated_at' => $now], $chunk),
                ['client_id', 'quinos_id'],
                [...$update, 'updated_at'],
            );
        }

        $model::where('client_id', $this->client->id)
            ->whereNotIn('quinos_id', array_column($rows, 'quinos_id'))
            ->delete();
    }
}
