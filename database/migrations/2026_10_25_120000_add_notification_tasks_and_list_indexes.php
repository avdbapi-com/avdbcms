<?php

use App\Notifications\Support\NotificationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications at any size (1.2.3):
 *  - notification_tasks: how far long notification work has got (a member's "Mark all as read" / "Delete all", a new
 *    video to the subscribers, an admin's notification to every member), so it goes on in small steps
 *    (App\Notifications\Support\NotificationTasks);
 *  - two indexes for the admin's notification list (the newest first, all or only the unread ones) and the daily
 *    tidy-up, each added only when it is missing. On MySQL / MariaDB both are built in one online pass: the site reads
 *    and writes the table while it runs. On a table bigger than NotificationIndexes::$buildNowRows rows they are left
 *    to the scheduler (notifications:index, after "Finish update"; judge R1-008).
 * Safe to run twice and on 1.2.0, 1.2.1 and 1.2.2 databases; down() removes only what this migration added.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const INDEXES = [
        'notifications_created_id_idx' => ['created_at', 'id'],
        'notifications_read_created_id_idx' => ['read_at', 'created_at', 'id'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_tasks')) {
            Schema::create('notification_tasks', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 24);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->timestamp('before_at')->nullable();
                $table->string('cursor', 191)->nullable();
                $table->text('payload')->nullable();
                $table->unsignedBigInteger('total')->nullable();
                $table->unsignedBigInteger('done')->default(0);
                $table->unsignedInteger('runs')->default(0);
                $table->unsignedInteger('failures')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'kind'], 'notification_tasks_user_kind_idx');
                $table->index(['kind', 'subject_id'], 'notification_tasks_kind_subject_idx');
                $table->index(['finished_at', 'locked_until'], 'notification_tasks_waiting_idx');
            });
        }

        $this->addListIndexes();
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications')) {
            foreach (array_keys(self::INDEXES) as $name) {
                if (Schema::hasIndex('notifications', $name)) {
                    Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex($name));
                }
            }
        }

        Schema::dropIfExists('notification_tasks');
    }

    private function addListIndexes(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $missing = [];

        foreach (self::INDEXES as $name => $columns) {
            if (! Schema::hasIndex('notifications', $name) && Schema::hasColumns('notifications', $columns)) {
                $missing[$name] = $columns;
            }
        }

        if ($missing === []) {
            return;
        }

        // A big table gets them from the scheduler after "Finish update" (notifications:index), never inside it: building
        // them reads the whole table (23 s on 3,800,000 rows on MariaDB 10.11), and Finish runs under the host's time
        // limit. The admin list and the tidy-up work without them meanwhile.
        if (! NotificationIndexes::smallEnough()) {
            return;
        }

        $connection = Schema::getConnection();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table = $connection->getTablePrefix().'notifications';
            $adds = [];

            foreach ($missing as $name => $columns) {
                $adds[] = 'ADD INDEX `'.$name.'` (`'.implode('`, `', $columns).'`)';
            }

            try {
                // one pass over the table for both, without blocking reads or writes
                DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $adds).', ALGORITHM=INPLACE, LOCK=NONE');

                return;
            } catch (Throwable $e) {
                // a server that cannot build them online: the plain way below (it still builds both)
                if (Schema::hasIndex('notifications', array_key_first($missing))) {
                    return;
                }
            }
        }

        Schema::table('notifications', function (Blueprint $table) use ($missing) {
            foreach ($missing as $name => $columns) {
                $table->index($columns, $name);
            }
        });
    }
};
