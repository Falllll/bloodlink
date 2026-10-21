<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Exceptions;

use App\Modules\Notification\Domain\DeliveryFailureKind;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * Transport menolak satu pesan (kartu 470). Pengirim yang MEMUTUSKAN jenisnya;
 * job hanya mematuhinya: TRANSIENT diulang dengan backoff, PERMANENT langsung
 * dead-letter tanpa menghabiskan sisa percobaan.
 *
 * $failureCode berukuran kolom notification_deliveries.failure_code. Pesannya TIDAK
 * memuat alamat, token, maupun isi pesan: ia berakhir di failure_detail, log, dan
 * failed_jobs.
 */
final class DeliveryFailed extends DomainException
{
    private function __construct(
        public readonly DeliveryFailureKind $kind,
        public readonly string $failureCode,
        string $detail,
        // Alamatnya sendiri mati (mis. FCM UNREGISTERED): job mencabut token push-nya.
        public readonly bool $addressIsDead = false,
    ) {
        parent::__construct($detail);
    }

    public static function transient(string $failureCode, string $detail): self
    {
        return new self(DeliveryFailureKind::TRANSIENT, substr($failureCode, 0, 64), $detail);
    }

    public static function permanent(string $failureCode, string $detail, bool $addressIsDead = false): self
    {
        return new self(DeliveryFailureKind::PERMANENT, substr($failureCode, 0, 64), $detail, $addressIsDead);
    }

    public function isPermanent(): bool
    {
        return $this->kind === DeliveryFailureKind::PERMANENT;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::NOTIFICATION_DELIVERY_FAILED;
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
