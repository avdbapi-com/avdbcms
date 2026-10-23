<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DatabaseCreate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:create {name?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new MySQL database based on the database config file or the provided name';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() : int
    {
        $name = $this->argument('name') ?: config("database.connections.mysql.database");

        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            $this->error('Database name may only contain letters, numbers, and underscores.');

            return Command::FAILURE;
        }

        config(["database.connections.mysql.database" => null]);

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

        config(["database.connections.mysql.database" => $name]);

        return Command::SUCCESS;
    }
}
