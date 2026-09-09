<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_sensitive_fields_are_redacted_even_when_nested(): void
    {
        $path = storage_path('logs/redaction-test.log');

        if (file_exists($path)) {
            unlink($path);
        }

        // Reuses the 'stderr' channel's own 'tap' config (rather than hardcoding
        // it here) so that removing the tap line in config/logging.php is what
        // the negative verification for this guard is supposed to catch.
        config([
            'logging.channels.redaction_test' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
                'tap' => config('logging.channels.stderr.tap', []),
            ],
        ]);

        Log::channel('redaction_test')->info('uji', [
            'password' => 'rahasia',
            'nested' => ['token' => 'abcdef'],
        ]);

        $contents = file_get_contents($path);

        $this->assertStringNotContainsString('rahasia', $contents);
        $this->assertStringNotContainsString('abcdef', $contents);

        unlink($path);
    }
}
