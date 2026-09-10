<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_sensitive_fields_are_redacted_even_when_nested(): void
    {
        $contents = $this->logWithContext([
            'password' => 'rahasia',
            'nested' => ['token' => 'abcdef'],
        ]);

        $this->assertStringNotContainsString('rahasia', $contents);
        $this->assertStringNotContainsString('abcdef', $contents);
    }

    public function test_a_sensitive_key_holding_an_array_is_redacted_whole(): void
    {
        $contents = $this->logWithContext([
            'lab_result' => ['hb' => 12.3, 'hiv' => 'reactive'],
            'token' => ['access' => 'zxcvbn'],
        ]);

        $this->assertStringNotContainsString('reactive', $contents);
        $this->assertStringNotContainsString('zxcvbn', $contents);
        $this->assertStringContainsString('[REDACTED]', $contents);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logWithContext(array $context): string
    {
        $path = storage_path('logs/redaction-test.log');

        if (file_exists($path)) {
            unlink($path);
        }

        config([
            'logging.channels.redaction_test' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
                'tap' => config('logging.channels.stderr.tap', []),
            ],
        ]);

        Log::channel('redaction_test')->info('uji', $context);

        $contents = file_get_contents($path);

        unlink($path);

        return $contents;
    }
}
