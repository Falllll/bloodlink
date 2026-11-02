<?php

declare(strict_types=1);

namespace App\Modules\Chat\Infrastructure\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Kotak masuk percakapan petugas (kartu 495). Didaftarkan MANUAL lewat Gate::policy()
 * di AppServiceProvider -- policy di luar app/Policies tidak ditemukan auto-discovery.
 *
 * Fasilitas dicocokkan DI SINI, bukan lewat binding: percakapan fasilitas lain tetap
 * ditemukan route binding dan dijawab 403, bukan 404 -- sama dengan pola Donor.
 */
final class ConversationPolicy
{
    // Literal: ModChat tidak boleh bergantung ke DomIdentity (deptrac).
    private const string VIEW = 'chat.view';

    private const string RESPOND = 'chat.respond';

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(self::VIEW);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->hasPermissionTo(self::VIEW) && $this->sameFacility($user, $conversation);
    }

    public function assign(User $user, Conversation $conversation): bool
    {
        return $user->hasPermissionTo(self::RESPOND) && $this->sameFacility($user, $conversation);
    }

    /** Membalas hanya oleh petugas yang memegang percakapan itu. */
    public function respond(User $user, Conversation $conversation): bool
    {
        return $user->hasPermissionTo(self::RESPOND)
            && $this->sameFacility($user, $conversation)
            && $conversation->assigned_user_id === $user->id;
    }

    public function close(User $user, Conversation $conversation): bool
    {
        return $user->hasPermissionTo(self::RESPOND) && $this->sameFacility($user, $conversation);
    }

    /**
     * Percakapan tanpa fasilitas (belum dieskalasi) tidak milik siapa pun -- termasuk
     * operator global: kotak masuk berlingkup satu fasilitas.
     */
    private function sameFacility(User $user, Conversation $conversation): bool
    {
        return $user->facilityId() !== null && $user->facilityId() === $conversation->ownerFacilityId();
    }
}
