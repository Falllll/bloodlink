<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Hapus idempotency key yang lebih tua dari 24 jam';

    public function handle(): int
    {
        $deleted = DB::table('idempotency_keys')
            ->where('created_at', '<', now()->subDay())
            ->delete();

        $this->info("Deleted {$deleted} idempotency key(s) older than 24 hours.");

        return self::SUCCESS;
    }
}
