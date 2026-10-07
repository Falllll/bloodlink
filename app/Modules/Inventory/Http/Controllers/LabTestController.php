<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\BloodBatch;
use App\Models\TtiTestResult;
use App\Models\TtiTestType;
use App\Modules\Inventory\Application\RecordAboRhDetermination;
use App\Modules\Inventory\Application\RecordTtiTestResult;
use App\Modules\Inventory\Application\ResolveTtiPanel;
use App\Modules\Inventory\Domain\TtiPanelVerdict;
use App\Modules\Inventory\Http\Requests\RecordAboRhDeterminationRequest;
use App\Modules\Inventory\Http\Requests\RecordTtiTestResultRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class LabTestController
{
    /**
     * Panel, hasil, golongan, dan vonis skrining sebuah unit. Untuk komponen
     * turunan, semuanya dibaca dari unit asal donasinya.
     */
    public function index(BloodBatch $bloodBatch, ResolveTtiPanel $resolveTtiPanel): JsonResponse
    {
        Gate::authorize('view', $bloodBatch);

        $source = $bloodBatch->parent ?? $bloodBatch;
        $panel = $resolveTtiPanel->forFacility($source->facility_id);
        $results = $source->ttiTestResults()->with('testType')->orderBy('id')->get();

        $screening = [];
        foreach ($results->where('is_confirmatory', false) as $result) {
            $screening[$result->testType->code->value] = $result->result;
        }

        return ApiResponse::success([
            'unit_id' => $bloodBatch->public_id,
            'source_unit_id' => $source->public_id,
            'panel' => $panel->map(fn (TtiTestType $type): array => [
                'test_code' => $type->code->value,
                'label' => $type->label,
                'requirement' => $type->requirement->value,
            ])->values()->all(),
            'tti_results' => $results->map(fn (TtiTestResult $result): array => $result->toApiArray())->values()->all(),
            'abo_rh' => $source->aboRhDetermination?->toApiArray(),
            'screening_verdict' => TtiPanelVerdict::decide(
                $panel->map(fn (TtiTestType $type) => $type->code)->values()->all(),
                $screening,
            )->value,
        ]);
    }

    public function storeTtiResult(
        RecordTtiTestResultRequest $request,
        BloodBatch $bloodBatch,
        RecordTtiTestResult $recordTtiTestResult,
    ): JsonResponse {
        Gate::authorize('recordLabResult', $bloodBatch);

        $result = $recordTtiTestResult->handle(
            $bloodBatch,
            $request->testCode(),
            $request->result(),
            $request->isConfirmatory(),
            $request->testedAt(),
            $request->user()?->id,
        );

        return ApiResponse::success($result->toApiArray())->setStatusCode(201);
    }

    public function storeAboRh(
        RecordAboRhDeterminationRequest $request,
        BloodBatch $bloodBatch,
        RecordAboRhDetermination $recordAboRhDetermination,
    ): JsonResponse {
        Gate::authorize('recordLabResult', $bloodBatch);

        $determination = $recordAboRhDetermination->handle(
            $bloodBatch,
            $request->validated('blood_group'),
            $request->validated('rh_factor'),
            $request->determinedAt(),
            $request->user()?->id,
        );

        return ApiResponse::success($determination->toApiArray())->setStatusCode(201);
    }
}
