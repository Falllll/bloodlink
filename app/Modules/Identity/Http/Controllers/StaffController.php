<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Application\AssignStaff;
use App\Modules\Identity\Application\CreateStaffAccount;
use App\Modules\Identity\Application\SetStaffActive;
use App\Modules\Identity\Application\StaffDirectory;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Http\Requests\AssignStaffRequest;
use App\Modules\Identity\Http\Requests\StaffListRequest;
use App\Modules\Identity\Http\Requests\StoreStaffRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Manajemen akun staf (kartu 487): buat akun, atur peran + fasilitas, nonaktifkan.
 * Aturan siapa-boleh-apa ada di UserPolicy. Respons tidak pernah memuat password,
 * hash, token, maupun id numerik.
 */
final class StaffController
{
    use AppliesListQuery;

    public function __construct(private readonly StaffDirectory $directory) {}

    public function index(StaffListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $actor = $this->actor($request);
        $query = $this->directory->query();

        // Admin fasilitas hanya melihat petugas fasilitasnya; operator global semuanya.
        if (! $actor->isGlobalOperator()) {
            $query->where('users.facility_id', $actor->facilityId());
        }

        /** @var CursorPaginator<int, User> $page */
        $page = $this->listing($query, $request);

        return ApiResponse::paginated($page, transform: fn (User $user): array => StaffDirectory::present($user));
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::success(StaffDirectory::present($this->directory->find($user)));
    }

    public function store(StoreStaffRequest $request, CreateStaffAccount $create): JsonResponse
    {
        $facility = $this->facility((string) $request->validated('facility_id'));
        Gate::authorize('create', [User::class, $facility]);

        $user = $create->handle(
            $facility,
            (string) $request->validated('name'),
            (string) $request->validated('email'),
            Role::from((string) $request->validated('role')),
        );

        return ApiResponse::success(StaffDirectory::present($this->directory->find($user)))->setStatusCode(201);
    }

    public function assign(AssignStaffRequest $request, User $user, AssignStaff $assign): JsonResponse
    {
        $facility = $this->facility((string) $request->validated('facility_id'));
        Gate::authorize('assign', [$user, $facility]);

        $assign->handle($user, $facility, Role::from((string) $request->validated('role')));

        return ApiResponse::success(StaffDirectory::present($this->directory->find($user)));
    }

    public function deactivate(User $user, SetStaffActive $active): JsonResponse
    {
        Gate::authorize('deactivate', $user);

        $active->deactivate($user);

        return ApiResponse::success(StaffDirectory::present($this->directory->find($user)));
    }

    public function activate(User $user, SetStaffActive $active): JsonResponse
    {
        Gate::authorize('activate', $user);

        $active->activate($user);

        return ApiResponse::success(StaffDirectory::present($this->directory->find($user)));
    }

    private function facility(string $publicId): Facility
    {
        return Facility::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
