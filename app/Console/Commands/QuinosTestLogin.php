<?php

namespace App\Console\Commands;

use App\Exceptions\Quinos\QuinosException;
use App\Models\Client;
use App\Services\Quinos\QuinosClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class QuinosTestLogin extends Command
{
    protected $signature = 'quinos:test-login
        {client? : Client id or exact name}
        {--all : Test every active client that has a Quinos login}';

    protected $description = 'Log in to quinosbackend.com with a client\'s saved credentials and show who we are signed in as';

    public function handle(): int
    {
        $clients = $this->clients();

        if ($clients->isEmpty()) {
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($clients as $client) {
            $failed += $this->test($client) ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function test(Client $client): bool
    {
        $this->line("<options=bold>{$client->name}</> — logging in as {$client->quinos_email}");

        try {
            $page = QuinosClient::for($client)->login();
        } catch (QuinosException $e) {
            $this->error('  ✗ '.$e->getMessage());

            return false;
        }

        $this->info('  ✓ Signed in to Quinos'.($page->companyName ? " as company “{$page->companyName}”" : '').($page->companyId ? " (id {$page->companyId})" : '').'.');

        if ($page->companyId && blank($client->quinos_company_id)) {
            $client->update(['quinos_company_id' => $page->companyId]);
            $this->line('  Saved the company id on the client.');
        } elseif ($page->companyId && $client->quinos_company_id !== $page->companyId) {
            $this->warn("  ! This login belongs to company {$page->companyId}, but the client has {$client->quinos_company_id} saved.");
        }

        $salesTypes = collect($page->rows())->pluck('name')->filter()->implode(', ');
        $this->line('  Sales types: '.($salesTypes ?: '(none)'));

        return true;
    }

    /** @return Collection<int, Client> */
    private function clients(): Collection
    {
        if ($this->option('all')) {
            $clients = Client::where('active', true)->orderBy('name')->get()
                ->filter(fn (Client $c) => $c->hasQuinosCredentials())->values();

            if ($clients->isEmpty()) {
                $this->error('No active client has a Quinos email and password saved.');
            }

            return $clients;
        }

        $argument = $this->argument('client');

        if ($argument === null) {
            $this->error('Give a client id or name, or use --all.');

            return collect();
        }

        $client = ctype_digit($argument)
            ? Client::find((int) $argument)
            : Client::whereRaw('lower(name) = ?', [mb_strtolower($argument)])->first();

        if ($client === null) {
            $this->error("No client matches “{$argument}”.");

            return collect();
        }

        return collect([$client]);
    }
}
