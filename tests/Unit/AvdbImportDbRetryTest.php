<?php

namespace Tests\Unit;

use App\Console\Commands\ImportAvdbVideos;
use Illuminate\Database\QueryException;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class AvdbImportDbRetryTest extends TestCase
{
    public function test_windows_socket_permission_errors_are_treated_as_transient(): void
    {
        $exception = new QueryException(
            'mysql',
            'select * from videos where avdb_id = ?',
            [149],
            new PDOException('SQLSTATE[HY000] [2002] An attempt was made to access a socket in a way forbidden by its access permissions')
        );

        $method = new ReflectionMethod(ImportAvdbVideos::class, 'isTransientDatabaseError');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(new ImportAvdbVideos(), $exception));
    }
}
