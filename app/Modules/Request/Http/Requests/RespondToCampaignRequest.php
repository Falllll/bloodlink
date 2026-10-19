<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Modules\Request\Domain\CampaignResponse;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class RespondToCampaignRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'response' => ['required', Rule::enum(CampaignResponse::class)],
        ];
    }

    public function campaignResponse(): CampaignResponse
    {
        return CampaignResponse::from($this->validated('response'));
    }
}
