<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\Identity\Application\IssuePasswordReset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Seluruh kerja lupa-password yang bergantung pada ADA-TIDAKNYA akun (kartu 485).
 *
 * Kenapa di antrean, bukan di controller: controller lalu membalas 202 dengan kerja
 * yang identik untuk email yang ada maupun tidak -- validasi format plus satu push
 * antrean. Pesan identik dengan durasi berbeda tetap membocorkan akun; mencari user,
 * menulis token, dan merender email di sini membuat durasi respons tidak bergantung
 * pada keberadaan akun.
 *
 * ShouldBeEncrypted: payload-nya alamat email, dan payload antrean berakhir di Redis
 * dan failed_jobs. Terenkripsi dengan APP_KEY, bukan teks polos.
 */
final class SendPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $email,
    ) {}

    public function handle(IssuePasswordReset $issue): void
    {
        $user = User::query()->where('email', $this->email)->where('is_active', true)->first();

        // Akun tidak ada atau nonaktif: diam. Tidak ada email "akun tidak ditemukan" --
        // itu juga kebocoran, ke kotak masuk siapa pun yang mengetik alamat itu.
        if ($user === null) {
            return;
        }

        $issue->handle($user);
    }
}
