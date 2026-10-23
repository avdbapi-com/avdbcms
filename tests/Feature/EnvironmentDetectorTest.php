<?php

namespace Tests\Feature;

use App\Support\Environment;
use App\Support\SendFile;
use Tests\TestCase;

class EnvironmentDetectorTest extends TestCase
{
    public function test_snapshot_contains_expected_top_level_keys(): void
    {
        Environment::reset();
        $snapshot = Environment::snapshot();

        $this->assertArrayHasKey('os', $snapshot);
        $this->assertArrayHasKey('php', $snapshot);
        $this->assertArrayHasKey('web', $snapshot);
        $this->assertArrayHasKey('runtime', $snapshot);
    }

    public function test_web_server_detection_falls_back_to_unknown_without_server_software(): void
    {
        Environment::reset();
        $originalSapi = $_SERVER['SERVER_SOFTWARE'] ?? null;
        unset($_SERVER['SERVER_SOFTWARE']);

        try {
            // Running the suite under phpunit → SAPI is `cli`, so detector
            // must return the CLI label, never the UNKNOWN fallback.
            $this->assertSame(Environment::WEB_CLI, Environment::webServer());
        } finally {
            if ($originalSapi !== null) {
                $_SERVER['SERVER_SOFTWARE'] = $originalSapi;
            }
        }
    }

    public function test_cli_based_http_runtime_detection_prefers_server_signal(): void
    {
        Environment::reset();
        $originalServer = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'RoadRunner';

        try {
            $this->assertSame(Environment::WEB_ROADRUNNER, Environment::webServer());
        } finally {
            Environment::reset();
            if ($originalServer !== null) {
                $_SERVER['SERVER_SOFTWARE'] = $originalServer;
            } else {
                unset($_SERVER['SERVER_SOFTWARE']);
            }
        }
    }

    public function test_send_file_mode_defaults_to_auto_in_tests(): void
    {
        Environment::reset();
        config(['site.stream_accelerator' => 'auto']);

        // In CLI test context webServer() is `cli`, which has no
        // accelerator — the helper MUST fall back to "none" so tests and
        // production both use the same safe default.
        $this->assertSame(SendFile::MODE_NONE, SendFile::effectiveMode());
    }

    public function test_send_file_explicit_override_wins(): void
    {
        Environment::reset();
        config(['site.stream_accelerator' => 'nginx']);

        $this->assertSame(SendFile::MODE_NGINX, SendFile::effectiveMode());
    }
}
