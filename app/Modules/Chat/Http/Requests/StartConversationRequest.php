<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Requests;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/** Pengunjung anonim membuka percakapan (kartu 495). Tanpa authorize(): rute ini publik. */
final class StartConversationRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            // Opsional: pengunjung yang sudah tahu fasilitasnya. Hanya yang sedang beroperasi.
            'facility_id' => ['nullable', 'uuid', Rule::exists('facilities', 'public_id')->where('is_active', true)],
        ];
    }

    public function message(): string
    {
        return (string) $this->validated('message');
    }

    public function facilityPublicId(): ?string
    {
        $value = $this->validated('facility_id');

        return $value === null ? null : (string) $value;
    }
}
