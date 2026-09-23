<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Modules\Donor\Domain\ConsentPurpose;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class RecordDonorConsentRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purposes' => ['required', 'array', 'min:1'],
            'purposes.*' => ['required', Rule::enum(ConsentPurpose::class)],
        ];
    }

    /** @return list<string> */
    public function purposes(): array
    {
        /** @var list<string> $purposes */
        $purposes = $this->validated('purposes');

        return $purposes;
    }
}
