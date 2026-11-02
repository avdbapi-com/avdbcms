<?php

namespace Tests\Unit;

use Tests\TestCase;

class SanctumTokenLifetimeConfigTest extends TestCase
{
    /**
     * .env gives text: config('sanctum.expiration') must be minutes as an int (Carbon 3 refuses a string in
     * addMinutes(), which answered /api/login and /api/register with 500), null for "never", and never "forever" by
     * accident.
     */
    public function test_the_token_lifetime_from_the_env_file_is_a_number_of_minutes_or_null(): void
    {
        $cases = [
            '43200' => 43200,
            '60' => 60,
            '0' => null,
            '' => null,
            'null' => null,
            'false' => null,
            '-5' => 43200,
            '30d' => 43200,
        ];

        foreach ($cases as $value => $expected) {
            $this->assertSame($expected, $this->expirationFor((string) $value), 'SANCTUM_TOKEN_EXPIRATION='.$value);
        }

        $this->assertSame(43200, $this->expirationFor(null), 'SANCTUM_TOKEN_EXPIRATION not set');
    }

    /** What config/sanctum.php gives for this value, as it is (no return type: a string must stay a string). */
    private function expirationFor(?string $value): mixed
    {
        $key = 'SANCTUM_TOKEN_EXPIRATION';
        $saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

        try {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }

            return (require config_path('sanctum.php'))['expiration'];
        } finally {
            [$envValue, $serverValue, $processValue] = $saved;
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
            putenv($processValue === false ? $key : $key.'='.$processValue);
        }
    }
}
