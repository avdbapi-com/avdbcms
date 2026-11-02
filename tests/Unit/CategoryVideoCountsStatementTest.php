<?php

namespace Tests\Unit;

use App\Support\CategoryVideoCounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * R3-022: which statements change the kept category counts (App\Support\CategoryVideoCounts::changes()): every write
 * that can move a video in or out of a count, in the forms MySQL / MariaDB and SQLite get them from the models, the query
 * builder and raw statements; and none of the writes a page view or a watch sends all the time (views, likes, comment
 * counts, a creator's avatar, sign-ins), so the counts are not worked out again for nothing.
 */
class CategoryVideoCountsStatementTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function changing(): array
    {
        return [
            'model: a new video' => ['insert into `videos` (`uuid`, `title`, `user_id`, `status`) values (?, ?, ?, ?)'],
            'sqlite: a new video' => ['insert into "videos" ("uuid", "title") values (?, ?)'],
            'import: insert ignore' => ['insert ignore into `videos` (`avdb_id`, `title`) values (?, ?), (?, ?)'],
            'sqlite: insert or ignore' => ['insert or ignore into "videos" ("avdb_id") values (?)'],
            'upsert' => ['insert into `videos` (`xvid_id`, `title`) values (?, ?) on duplicate key update `title` = values(`title`)'],
            'replace' => ['replace into videos (id, title) values (1, "a")'],
            'model: publish' => ['update `videos` set `status` = ?, `published_at` = ?, `videos`.`updated_at` = ? where `id` = ?'],
            'model: category' => ['update `videos` set `category_id` = ?, `updated_at` = ? where `id` = ?'],
            'bulk: category (soft-delete scope in where)' => ['update `videos` set `category_id` = ?, `videos`.`updated_at` = ? where `id` in (?, ?) and `videos`.`deleted_at` is null'],
            'bulk: visibility' => ['update `videos` set `status` = ?, `videos`.`updated_at` = ? where `id` in (?, ?, ?) and `status` != ? and `videos`.`deleted_at` is null'],
            'model: to the bin' => ['update `videos` set `deleted_at` = ?, `videos`.`updated_at` = ? where `id` = ?'],
            'model: restore' => ['update `videos` set `deleted_at` = ?, `videos`.`updated_at` = ? where `id` = ?'],
            'sqlite: schedule' => ['update "videos" set "scheduled_at" = ?, "updated_at" = ? where "id" = ?'],
            'language' => ['update `videos` set `language` = ? where `id` = ?'],
            'uploaded' => ['update `videos` set `uploaded_at` = ?, `file` = ? where `id` = ?'],
            'owner moved' => ['update `videos` set `user_id` = ? where `user_id` = ?'],
            'imported id' => ['update videos set avdb_id = null where id = 5'],
            'a value with a subquery before the where' => ['update `videos` set `views` = (select count(*) from `views` where `video_id` = `videos`.`id`), `status` = ? where `id` = ?'],
            'multi-table update' => ['update `videos` inner join `users` on `users`.`id` = `videos`.`user_id` set `videos`.`status` = ? where `users`.`banned_at` is not null'],
            'delete' => ['delete from `videos` where `id` in (?, ?)'],
            'sqlite: delete' => ['delete from "videos" where "id" = ?'],
            'multi-table delete' => ['delete `videos` from `videos` inner join `users` on `users`.`id` = `videos`.`user_id` where `users`.`id` = ?'],
            'truncate' => ['truncate table `videos`'],
            'sqlite truncate' => ['delete from "videos"'],
            'ban a member' => ['update `users` set `banned_at` = ?, `users`.`updated_at` = ? where `id` in (?, ?) and `users`.`deleted_at` is null'],
            'unban' => ['update `users` set `banned_at` = ? where `id` = ?'],
            'confirm an e-mail' => ['update `users` set `email_verified_at` = ?, `updated_at` = ? where `id` = ?'],
            'delete an account (bin)' => ['update `users` set `deleted_at` = ?, `users`.`updated_at` = ? where `id` = ?'],
            'delete an account' => ['delete from `users` where `id` = ?'],
            'a category' => ['update `categories` set `in_menu` = ?, `updated_at` = ? where `id` = ?'],
            'a new category' => ['insert into `categories` (`title`, `slug`) values (?, ?)'],
            'delete a category' => ['delete from `categories` where `id` = ?'],
            'leading whitespace' => ["\n   UPDATE videos SET status = 2 WHERE id = 7"],
            'schema-qualified' => ['update `site`.`videos` set `status` = ? where `id` = ?'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function unchanged(): array
    {
        return [
            'a read' => ['select * from `videos` where `status` = ? and `deleted_at` is null'],
            'a count' => ['select count(*) as aggregate from `videos` where `category_id` = ?'],
            'select for update' => ['select * from `videos` where `id` = ? for update'],
            'a view' => ['update `videos` set `views` = `views` + 1 where `id` = ?'],
            'likes' => ['update `videos` set `likes_count` = ?, `dislikes_count` = ? where `id` = ?'],
            'comments' => ['update `videos` set `comments_count` = `comments_count` + 1, `videos`.`updated_at` = ? where `id` = ? and `videos`.`deleted_at` is null'],
            'duration sync' => ['update `videos` set `duration` = ?, `avdb_duration_sync_attempted_at` = ? where `id` = ?'],
            'ban details' => ['update `videos` set `status_before_ban` = ?, `status_before_ban_at` = ? where `id` = ?'],
            'a video file' => ['update `videos` set `file` = null where `id` = ?'],
            'status only in where' => ['update `videos` set `views` = ? where `status` = ?'],
            'an avatar' => ["update `users` set `avatar` = 'avdb-creator-0a1e7d5a3e821f0e7e41ee94.svg', `updated_at` = '2026-10-28 21:40:48' where `id` = 79 and (`avatar` is null or `avatar` = '')"],
            'sign in' => ['update `users` set `remember_token` = ?, `last_login_at` = ? where `id` = ?'],
            'a sign-up' => ['insert into `users` (`username`, `email`, `password`) values (?, ?, ?)'],
            'other tables' => ['update `video_tags` set `tag_id` = ? where `video_id` = ?'],
            'video translations' => ['insert into `video_translations` (`video_id`, `locale`, `title`) values (?, ?, ?)'],
            'user reports' => ['delete from `user_reports` where `id` = ?'],
            'playlists of videos' => ['delete from `playlist_has_videos` where `video_id` = ?'],
            // a name that ENDS like it: the studio's playlist editor and the row-times conversion write these often
            'a playlist reordered' => ['update `playlist_has_videos` set `position` = ? where `playlist_id` = ? and `video_id` = ?'],
            'a playlist row put in the site time zone' => ['update `playlist_has_videos` set `added_at` = ? where `id` = ?'],
            'a playlist row, unquoted' => ['update playlist_has_videos set position = 3 where id = 9'],
            'a table named like it' => ['update `videos_old` set `status` = ?'],
            'a table ending like it' => ['update `user_videos` set `seen` = 1 where `id` = ?'],
            'the cache' => ["insert into `cache` (`key`, `value`, `expiration`) values ('catalogue.category_counts.stamp', ?, ?)"],
        ];
    }

    #[DataProvider('changing')]
    public function test_a_write_that_can_move_a_video_in_or_out_of_a_count_changes_the_stamp(string $sql): void
    {
        $this->assertTrue(CategoryVideoCounts::changes($sql), $sql);
    }

    #[DataProvider('unchanged')]
    public function test_reads_and_frequent_writes_that_change_no_count_keep_it(string $sql): void
    {
        $this->assertFalse(CategoryVideoCounts::changes($sql), $sql);
    }

    public function test_a_table_prefix_is_followed(): void
    {
        $this->assertTrue(CategoryVideoCounts::changes('update `avd_videos` set `status` = ? where `id` = ?', 'avd_'));
        $this->assertFalse(CategoryVideoCounts::changes('update `videos` set `status` = ? where `id` = ?', 'avd_'), 'another table without the prefix');
        $this->assertTrue(CategoryVideoCounts::changes('delete from `avd_users` where `id` = ?', 'avd_'));
    }
}
