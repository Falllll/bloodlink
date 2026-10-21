<?php

declare(strict_types=1);

namespace App\Modules\Request\Infrastructure\Policies;

use App\Models\BloodRequest;
use App\Models\User;

final class BloodRequestPolicy
{
    // Nama permission sengaja literal: ModRequest tidak boleh bergantung ke
    // App\Modules\Identity\Domain\Permission (dijaga deptrac).
    private const string CREATE = 'request.create';

    private const string VIEW = 'request.view';

    private const string APPROVE = 'request.approve';

    private const string REPORT = 'report.view';

    /** Mengeluarkan stok adalah tindakan inventory, bukan tindakan permintaan. */
    private const string RELEASE_STOCK = 'inventory.release';

    /**
     * Laporan agregat (kartu 440). Fasilitas mana yang dihitung diurus ReportScope,
     * sama seperti visibleTo() untuk baris.
     */
    public function viewReports(User $user): bool
    {
        return $user->hasPermissionTo(self::REPORT);
    }

    /** Gerbang jenis data; baris mana diurus visibleTo(). */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(self::VIEW);
    }

    public function view(User $user, BloodRequest $request): bool
    {
        // Izin dulu, sebelum cabang operator global: operator global pun butuh izin.
        if (! $user->hasPermissionTo(self::VIEW)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $request->ownerFacilityId();
    }

    /** Permintaan selalu milik satu fasilitas; operator tanpa fasilitas tidak bisa meminta. */
    public function create(User $user): bool
    {
        return $user->facilityId() !== null && $user->hasPermissionTo(self::CREATE);
    }

    /** Pemohon membatalkan permintaannya sendiri, atau penyetuju membatalkannya. */
    public function cancel(User $user, BloodRequest $request): bool
    {
        if (! $user->hasPermissionTo(self::CREATE) && ! $user->hasPermissionTo(self::APPROVE)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $request->ownerFacilityId();
    }

    /**
     * Melihat kandidat DONOR (kartu 410). Lebih ketat dari request.create: memanggil
     * donor menyentuh orang di luar fasilitas, dan role donor tidak boleh melihatnya.
     */
    public function viewDonorCandidates(User $user, BloodRequest $request): bool
    {
        if (! $user->hasPermissionTo(self::APPROVE)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $request->ownerFacilityId();
    }

    /**
     * Memulai, melihat, dan menghentikan kampanye panggilan donor (kartu 400).
     * Sama ketatnya dengan pratinjau kandidat: memanggil orang di luar fasilitas.
     */
    public function manageCampaign(User $user, BloodRequest $request): bool
    {
        return $this->viewDonorCandidates($user, $request);
    }

    /** Melihat kandidat dan mengalokasikan unit dari stok fasilitas peminta. */
    public function allocate(User $user, BloodRequest $request): bool
    {
        if (! $user->hasPermissionTo(self::RELEASE_STOCK)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $request->ownerFacilityId();
    }

    /** Memesan dan melepas reservasi: mengubah stok yang terlihat petugas fasilitas peminta. */
    public function reserve(User $user, BloodRequest $request): bool
    {
        return $this->stockAction($user, $request);
    }

    /** Mencatat hasil uji silang untuk unit yang dialokasikan ke permintaan ini. */
    public function recordCrossmatch(User $user, BloodRequest $request): bool
    {
        return $this->stockAction($user, $request);
    }

    /** Menerbitkan unit yang lolos crossmatch. */
    public function issue(User $user, BloodRequest $request): bool
    {
        return $this->stockAction($user, $request);
    }

    /** Mencatat transfusi unit yang sudah diterbitkan untuk permintaan ini. */
    public function recordTransfusion(User $user, BloodRequest $request): bool
    {
        return $this->stockAction($user, $request);
    }

    /** Melaporkan reaksi pasca-transfusi, dan membaca transfusi beserta reaksinya. */
    public function reportReaction(User $user, BloodRequest $request): bool
    {
        return $this->stockAction($user, $request);
    }

    /** Urutan cek: izin dulu, lalu operator global, lalu kecocokan fasilitas. */
    private function stockAction(User $user, BloodRequest $request): bool
    {
        if (! $user->hasPermissionTo(self::RELEASE_STOCK)) {
            return false;
        }

        if ($user->isGlobalOperator()) {
            return true;
        }

        return $user->facilityId() !== null && $user->facilityId() === $request->ownerFacilityId();
    }
}
