<?php

namespace Tests\Feature\Sync;

use App\Models\Category;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Item;
use App\Models\SalesType;
use App\Services\Quinos\QuinosClient;
use App\Sync\MasterSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeQuinos;
use Tests\TestCase;

class MasterSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeQuinos $quinos;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quinos = FakeQuinos::install();
        $this->client = Client::factory()->create([
            'quinos_email' => $this->quinos->email,
            'quinos_password' => $this->quinos->password,
        ]);

        $this->quinos->lists['/departments'] = [
            ['id' => 1416, 'name' => 'BEVERAGE', 'categories' => [['id' => 7133, 'name' => 'COFFEE', 'department_id' => 1416]]],
            ['id' => 1415, 'name' => 'FOOD', 'categories' => [['id' => 7213, 'name' => 'MAINS', 'department_id' => 1415]]],
        ];
        $this->quinos->lists['/items'] = [
            ['id' => 1, 'code' => 'SLS-COFE-33', 'name' => 'MOCCACHINO', 'category_id' => 7133, 'active' => true, 'category' => ['department_id' => 1416]],
            ['id' => 2, 'code' => 'SLS-MAIN-02', 'name' => 'CHICKEN & RICE', 'category_id' => 7213, 'active' => true, 'category' => ['department_id' => 1415]],
            ['id' => 3, 'code' => 'MOD17', 'name' => 'A CUP', 'category_id' => 7151, 'active' => false, 'category' => ['department_id' => 1417]],
        ];
        $this->quinos->lists['/contacts'] = [['id' => 1437, 'name' => 'PASAR', 'supplier' => true, 'customer' => false, 'active' => true]];
    }

    private function master(): MasterSync
    {
        return new MasterSync(QuinosClient::for($this->client->fresh()), $this->client);
    }

    public function test_it_copies_departments_categories_items_sales_types_and_contacts(): void
    {
        $counts = $this->master()->sync();

        $this->assertSame(['departments' => 2, 'items' => 3, 'sales_types' => 2, 'contacts' => 1], $counts);
        $this->assertSame(['BEVERAGE', 'FOOD'], Department::orderBy('name')->pluck('name')->all());
        $this->assertSame(1416, (int) Category::where('name', 'COFFEE')->value('department_quinos_id'));

        $item = Item::where('code', 'SLS-COFE-33')->firstOrFail();
        $this->assertSame(1416, (int) $item->department_quinos_id, 'item knows its department through its category');
        $this->assertFalse(Item::where('code', 'MOD17')->value('active') > 0);

        $this->assertSame(['DINE IN', 'GRAB'], SalesType::orderBy('name')->pluck('name')->all());
        $this->assertTrue((bool) Contact::firstOrFail()->supplier);
        $this->assertNotNull($this->client->fresh()->master_synced_at);
    }

    public function test_every_page_of_items_is_read(): void
    {
        $this->master()->sync();

        $this->assertCount(2, $this->quinos->requests('GET', '/items'), '3 items at 2 per page');
        $this->assertSame(3, Item::count());
    }

    public function test_running_twice_updates_in_place(): void
    {
        $this->master()->sync();

        $this->quinos->lists['/items'][0]['name'] = 'MOCHA';
        $this->master()->sync();

        $this->assertSame(3, Item::count());
        $this->assertSame('MOCHA', Item::where('quinos_id', 1)->value('name'));
    }

    public function test_items_removed_in_quinos_are_removed_here(): void
    {
        $this->master()->sync();

        array_pop($this->quinos->lists['/items']);
        $this->master()->sync();

        $this->assertSame(['SLS-COFE-33', 'SLS-MAIN-02'], Item::orderBy('code')->pluck('code')->all());
    }

    public function test_an_empty_list_never_wipes_a_table(): void
    {
        $this->master()->sync();

        $this->quinos->lists['/items'] = [];
        $this->master()->sync();

        $this->assertSame(3, Item::count());
    }

    public function test_data_belongs_to_the_client_it_was_synced_for(): void
    {
        $other = Client::factory()->create();
        Item::create(['client_id' => $other->id, 'quinos_id' => 1, 'code' => 'OTHER', 'name' => 'Other client item']);

        $this->master()->sync();

        $this->assertSame(['OTHER'], Item::where('client_id', $other->id)->pluck('code')->all(), 'other client untouched');
        $this->assertSame(3, Item::where('client_id', $this->client->id)->count());
    }

    public function test_it_knows_when_it_is_stale(): void
    {
        config(['sync.master_ttl_hours' => 6]);

        $this->assertTrue($this->master()->isStale(), 'never synced');

        $this->client->forceFill(['master_synced_at' => now()->subHours(2)])->save();
        $this->assertFalse($this->master()->isStale());

        $this->client->forceFill(['master_synced_at' => now()->subHours(7)])->save();
        $this->assertTrue($this->master()->isStale());
    }
}
