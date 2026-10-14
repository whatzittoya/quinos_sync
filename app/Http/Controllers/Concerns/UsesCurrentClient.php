<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;

trait UsesCurrentClient
{
    /** The client the page is working on, sent by the UI in the X-Client-Id header. */
    protected function currentClient(): Client
    {
        $id = request()->header('X-Client-Id');

        abort_unless(filled($id) && ctype_digit((string) $id), 422, 'Select a client first.');

        return Client::findOrFail((int) $id);
    }
}
