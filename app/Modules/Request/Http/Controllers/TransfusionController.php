<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Controllers;

use App\Models\BloodRequestAllocation;
use App\Models\Transfusion;
use App\Models\TransfusionReaction;
use App\Models\User;
use App\Modules\Request\Application\RecordTransfusion;
use App\Modules\Request\Application\ReportTransfusionReaction;
use App\Modules\Request\Http\Requests\RecordTransfusionRequest;
use App\Modules\Request\Http\Requests\ReportTransfusionReactionRequest;
use App\Shared\Http\ApiResponse;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Catatan transfusi dan laporan reaksi pasca-transfusi (hemovigilans, kartu 370). */
final class TransfusionController
{
    public function store(RecordTransfusionRequest $request, BloodRequestAllocation $allocation, RecordTransfusion $recordTransfusion): JsonResponse
    {
        Gate::authorize('recordTransfusion', $allocation->bloodRequest);

        /** @var User $user */
        $user = $request->user();

        $completedAt = $request->validated('completed_at');
        $volume = $request->validated('volume_transfused_ml');

        $transfusion = $recordTransfusion->handle(
            $allocation,
            $user,
            new DateTimeImmutable($request->validated('started_at')),
            $completedAt === null ? null : new DateTimeImmutable($completedAt),
            $volume === null ? null : (int) $volume,
            $request->validated('note'),
        );

        return ApiResponse::success($this->present($transfusion))->setStatusCode(201);
    }

    public function reportReaction(ReportTransfusionReactionRequest $request, Transfusion $transfusion, ReportTransfusionReaction $reportReaction): JsonResponse
    {
        Gate::authorize('reportReaction', $transfusion->allocation->bloodRequest);

        /** @var User $user */
        $user = $request->user();

        $reaction = $reportReaction->handle(
            $transfusion,
            $user,
            new DateTimeImmutable($request->validated('observed_at')),
            $request->validated('description'),
            $request->validated('reaction_type_code'),
        );

        return ApiResponse::success($reaction->toApiArray())->setStatusCode(201);
    }

    public function show(Transfusion $transfusion): JsonResponse
    {
        Gate::authorize('reportReaction', $transfusion->allocation->bloodRequest);

        return ApiResponse::success($this->present($transfusion));
    }

    /** @return array<string, mixed> */
    private function present(Transfusion $transfusion): array
    {
        return $transfusion->toApiArray() + [
            'reactions' => $transfusion->reactions()
                ->with('type')
                ->orderBy('observed_at')
                ->orderBy('id')
                ->get()
                ->map(fn (TransfusionReaction $reaction): array => $reaction->setRelation('transfusion', $transfusion)->toApiArray())
                ->all(),
        ];
    }
}
