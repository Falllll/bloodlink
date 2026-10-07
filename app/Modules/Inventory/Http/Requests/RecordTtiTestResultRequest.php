<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\TtiResult;
use App\Modules\Inventory\Domain\TtiTestCode;
use App\Shared\Http\StrictRequest;
use DateTimeImmutable;
use Illuminate\Validation\Rule;

final class RecordTtiTestResultRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'test_code' => ['required', Rule::enum(TtiTestCode::class)],
            // Tiga nilai, bukan boolean: indeterminate punya penanganan sendiri (§5).
            'result' => ['required', Rule::enum(TtiResult::class)],
            'is_confirmatory' => ['sometimes', 'boolean'],
            'tested_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function testCode(): TtiTestCode
    {
        return TtiTestCode::from($this->validated('test_code'));
    }

    public function result(): TtiResult
    {
        return TtiResult::from($this->validated('result'));
    }

    public function isConfirmatory(): bool
    {
        return (bool) $this->validated('is_confirmatory', false);
    }

    public function testedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->validated('tested_at'));
    }
}
