<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Helper bersama untuk anggaran query dan rencana query (kartu 445).
 */
trait InspectsQueries
{
    /**
     * Rencana EXPLAIN sebagai satu string.
     *
     * Seq scan selalu dimatikan: tabel test terlalu kecil, tanpa itu planner memilih
     * seq scan dan assertion index tidak berarti apa-apa. $disableSort OPSIONAL dan
     * sengaja terpisah: test yang meng-assert ketiadaan node Sort (FEFO) butuh sort
     * dimatikan supaya index yang TIDAK bisa memberi urutan tetap memaksa Sort muncul;
     * test lain tidak boleh ikut dilemahkan olehnya.
     *
     * @param  QueryBuilder|EloquentBuilder<*>|string  $query
     * @param  list<mixed>  $bindings  Hanya untuk $query berupa string SQL
     */
    protected function queryPlan(QueryBuilder|EloquentBuilder|string $query, array $bindings = [], bool $disableSort = false): string
    {
        // SET LOCAL hanya berlaku di dalam transaksi (RefreshDatabase); di luar itu
        // ia diam-diam tidak berefek dan test-nya hijau tanpa membuktikan apa pun.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('queryPlan() butuh transaksi terbuka, mis. RefreshDatabase.');
        }

        DB::statement('SET LOCAL enable_seqscan = off');

        if ($disableSort) {
            DB::statement('SET LOCAL enable_sort = off');
            DB::statement('SET LOCAL enable_incremental_sort = off');
        }

        [$sql, $bindings] = is_string($query) ? [$query, $bindings] : [$query->toSql(), $query->getBindings()];

        return implode("\n", array_map(
            fn (object $row): string => (string) $row->{'QUERY PLAN'},
            DB::select('EXPLAIN '.$sql, $bindings),
        ));
    }

    /** Jumlah query yang dijalankan $run. */
    protected function countQueries(callable $run): int
    {
        return $this->countMatching($run, fn (): bool => true);
    }

    /**
     * Jumlah query yang menyentuh $table. Nama tabel dicocokkan BERSAMA tanda kutip
     * gandanya: Postgres grammar mengutip identifier, dan tanpa kutip 'deferrals'
     * juga mencocok kolom seperti active_deferrals_count.
     */
    protected function countQueriesOn(string $table, callable $run): int
    {
        return $this->countMatching($run, fn (QueryExecuted $q): bool => str_contains($q->sql, '"'.$table.'"'));
    }

    /**
     * Jumlah query satu GET JSON yang harus sukses.
     *
     * Didahului satu GET yang TIDAK dihitung: request pertama ikut membayar token,
     * user, dan pemuatan permission (7 query), request berikutnya memakai user yang
     * sudah dipegang guard. Tanpa pemanasan, dua pengukuran dalam satu test tidak setara.
     *
     * @param  array<string, string>  $headers
     */
    protected function getCost(string $uri, array $headers): int
    {
        $this->getJson($uri, $headers)->assertOk();

        return $this->countQueries(fn () => $this->getJson($uri, $headers)->assertOk());
    }

    /** @param callable(QueryExecuted): bool $matches */
    private function countMatching(callable $run, callable $matches): int
    {
        // DB::listen tidak bisa dilepas: listener ini tetap hidup sampai aplikasi test
        // dibuang. Karena itu hitungan dan saklarnya variabel LOKAL per panggilan,
        // bukan properti kelas -- listener panggilan sebelumnya menulis ke variabel
        // yang sudah tidak dibaca siapa pun.
        $count = 0;
        $active = true;

        DB::listen(function (QueryExecuted $query) use (&$count, &$active, $matches): void {
            if ($active && $matches($query)) {
                $count++;
            }
        });

        try {
            $run();
        } finally {
            $active = false;
        }

        return $count;
    }
}
