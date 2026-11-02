<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Role;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Akun staf beserta fasilitas dan perannya, dalam SATU query (kartu 487). Peran dibaca
 * dari pivot Spatie di team fasilitas akun itu sendiri (model_has_roles.facility_id =
 * users.facility_id) -- bukan team yang sedang terikat di request, yang milik aktor.
 *
 * Staf = berperan staf di team fasilitasnya. facility_id hanya syarat tambahan, bukan
 * buktinya: donor bisa saja punya facility_id, dan pernah muncul di sini sebagai staf.
 * Query ini melindungi DAFTAR; mutasi dilindungi UserPolicy::view().
 */
final class StaffDirectory
{
    /** @return Builder<User> */
    public function query(): Builder
    {
        return User::query()
            ->whereNotNull('users.facility_id')
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->whereColumn('model_has_roles.facility_id', 'users.facility_id')
                ->where('model_has_roles.model_type', User::class)
                ->whereIn('roles.name', array_map(fn (Role $role): string => $role->value, StaffRoles::ASSIGNABLE)))
            ->select('users.*')
            ->addSelect([
                'facility_public_id' => DB::table('facilities')
                    ->whereColumn('facilities.id', 'users.facility_id')
                    ->select('public_id')
                    ->limit(1),
                'role_name' => DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->whereColumn('model_has_roles.facility_id', 'users.facility_id')
                    ->where('model_has_roles.model_type', User::class)
                    ->orderBy('roles.name')
                    ->select('roles.name')
                    ->limit(1),
            ]);
    }

    public function find(User $user): User
    {
        return $this->query()->whereKey($user->getKey())->firstOrFail();
    }

    /**
     * Tanpa id numerik, tanpa password/hash, tanpa token apa pun.
     *
     * @return array<string, mixed>
     */
    public static function present(User $user): array
    {
        $createdAt = $user->getAttribute('created_at');

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'facility_id' => $user->getAttribute('facility_public_id'),
            'role' => $user->getAttribute('role_name'),
            'is_active' => (bool) $user->is_active,
            'created_at' => $createdAt instanceof DateTimeInterface ? $createdAt->format(DateTimeInterface::ATOM) : null,
        ];
    }
}
