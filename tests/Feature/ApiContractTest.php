<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Errors\ErrorCode;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    private function spec(): OpenApi
    {
        $path = realpath(base_path('docs/api/openapi.yaml'));

        /** @var OpenApi $spec */
        $spec = Reader::readFromYamlFile($path === false ? base_path('docs/api/openapi.yaml') : $path);

        return $spec;
    }

    public function test_the_specification_document_is_valid(): void
    {
        $spec = $this->spec();

        $this->assertTrue($spec->validate(), implode("\n", $spec->getErrors()));
    }

    public function test_the_error_code_enum_matches_the_specification(): void
    {
        $spec = $this->spec();

        $php = array_column(ErrorCode::cases(), 'value');
        /** @var array<int, string> $fromSpec */
        $fromSpec = $spec->components->schemas['ErrorCode']->enum;

        sort($php);
        sort($fromSpec);

        $this->assertSame([], array_diff($php, $fromSpec));
        $this->assertSame([], array_diff($fromSpec, $php));
    }
}
