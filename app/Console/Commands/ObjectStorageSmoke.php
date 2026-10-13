<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bukti bahwa disk object storage benar-benar tersambung. Dijalankan tangan,
 * TIDAK didaftarkan ke scheduler dan TIDAK masuk `composer check` -- ia butuh
 * Garage hidup, dan test yang gagal karena container mati adalah sinyal palsu.
 */
final class ObjectStorageSmoke extends Command
{
    protected $signature = 'storage:smoke {--keep : jangan hapus objek ujinya}';

    protected $description = 'Tulis, baca, lalu hapus satu objek di disk s3 untuk membuktikan sambungannya';

    public function handle(): int
    {
        $disk = Storage::disk('s3');
        $key = 'smoke/'.now()->format('YmdHis').'-'.Str::random(8).'.txt';
        $payload = 'bloodlink storage smoke '.Str::uuid();

        try {
            $disk->put($key, $payload);

            if ($disk->get($key) !== $payload) {
                $this->error("Object {$key} was read back with different content.");

                return self::FAILURE;
            }

            if ($this->option('keep')) {
                $this->info("Kept {$key} for inspection.");
            } else {
                $disk->delete($key);

                if ($disk->exists($key)) {
                    $this->error("Object {$key} still exists after delete.");

                    return self::FAILURE;
                }
            }
        } catch (Throwable $e) {
            $this->error('Object storage smoke test failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Object storage OK: wrote, read back%s %s in bucket "%s" at %s.',
            $this->option('keep') ? '' : ', and deleted',
            $key,
            (string) config('filesystems.disks.s3.bucket'),
            (string) config('filesystems.disks.s3.endpoint'),
        ));

        return self::SUCCESS;
    }
}
