<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Peran tinggal di pivot Spatie (model_has_roles), yang TIDAK diamati AuditObserver.
 * Tanpa baris ini, "siapa memberi siapa peran admin, kapan" tidak terjawab. Bentuk
 * barisnya sama dengan yang ditulis AuditObserver; action-nya 'role_assigned'.
 */
final class RecordStaffAssignment
{
    /**
     * @param  array{facility_id: string|null, role: string|null}  $before
     * @param  array{facility_id: string|null, role: string|null}  $after
     */
    public function handle(User $target, array $before, array $after): void
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $target->getKey(),
            'action' => 'role_assigned',
            'actor_id' => $actor?->id,
            'actor_facility_id' => $actor?->facility_id,
            'changes' => ['before' => $before, 'after' => $after],
            'trace_id' => Context::get('trace_id'),
            'ip' => request()->ip(),
            'occurred_at' => now(),
        ]);
    }
}
