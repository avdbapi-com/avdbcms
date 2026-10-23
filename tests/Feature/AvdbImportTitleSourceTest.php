<?php

namespace Tests\Feature;

use App\Jobs\SyncAvdbDurationBatch;
use App\Models\User;
use App\Models\Video;
use App\Support\RemoteVideoDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvdbImportTitleSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_import_page_renders_movie_code_title_toggle(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertSee('id="useMovieCodeTitle"', false)
            ->assertSee('use_movie_code_title', false)
            ->assertSee('Use movie_code as video title', false);
    }

    public function test_admin_import_page_renders_starter_view_controls(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertSee('id="starterViewsMode"', false)
            ->assertSee('starter_views_mode', false)
            ->assertSee('Generate views', false);
    }

    public function test_admin_import_page_renders_creator_assignment_toggle(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.avdb-import.index'))
            ->assertOk()
            ->assertSee('id="creatorAssignmentEnabled"', false)
            ->assertSee('creator_assignment_enabled', false)
            ->assertSee('Auto-create creator profiles from AVDB metadata', false);
    }

    public function test_avdb_import_uses_api_name_as_title_by_default(): void
    {
        User::factory()->create(['id' => 1]);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('videos', [
            'avdb_id' => 79643,
            'title' => 'BOKD-197 AV debut full API name',
            'slug' => 'bokd-197-av-debut-full-api-name',
            'avdb_code' => 'BOKD-197',
        ]);
    }

    public function test_avdb_category_import_defers_upload18_playlist_duration(): void
    {
        User::factory()->create(['id' => 1]);
        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, 'avdbapi.com')) {
                return Http::response([
                    'total' => 1,
                    'pagecount' => 1,
                    'list' => [$this->avdbItem([
                        'time' => '',
                        'episodes' => [
                            'server_data' => [
                                'Full' => [
                                    'link_embed' => 'https://upload18.org/play/index/category-duration',
                                ],
                            ],
                        ],
                    ])],
                ], 200);
            }

            return Http::response('', 404);
        });

        $this->artisan('avdb:import', [
            '--category' => '1',
            '--pages' => '1',
            '--pagesize' => '1',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $video = Video::where('avdb_id', 79643)->firstOrFail();

        $this->assertNull($video->getRawOriginal('duration'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'upload18.org'));
    }

    public function test_avdb_import_completion_queues_duration_backfill_jobs(): void
    {
        $this->withRuntimeEnv('AVDB_DURATION_BACKFILL_MODE', 'queue', function (): void {
            config(['queue.default' => 'database']);
            User::factory()->create(['id' => 1]);
            Queue::fake();
            Http::fake(function ($request) {
                $url = (string) $request->url();

                if (str_contains($url, 'avdbapi.com')) {
                    return Http::response([
                        'total' => 1,
                        'pagecount' => 1,
                        'list' => [$this->avdbItem([
                            'time' => '',
                            'episodes' => [
                                'server_data' => [
                                    'Full' => [
                                        'link_embed' => 'https://upload18.org/play/index/import-auto-duration',
                                    ],
                                ],
                            ],
                        ])],
                    ], 200);
                }

                return Http::response('', 404);
            });

            $this->artisan('avdb:import', [
                '--category' => '1',
                '--pages' => '1',
                '--pagesize' => '1',
                '--skip-thumbs' => true,
                '--skip-tags' => true,
            ])->assertExitCode(0);

            Queue::assertPushed(SyncAvdbDurationBatch::class);
            Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'upload18.org'));
        });
    }

    public function test_avdb_duration_sync_falls_back_to_upload18_playlist_duration(): void
    {
        User::factory()->create(['id' => 1]);
        $video = Video::factory()->create([
            'user_id' => 1,
            'avdb_id' => 79643,
            'duration' => 0,
        ]);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, 'avdbapi.com')) {
                return Http::response([
                    'list' => [$this->avdbItem([
                        'time' => '',
                        'episodes' => [
                            'server_data' => [
                                'Full' => [
                                    'link_embed' => 'https://upload18.org/play/index/pkpd-418',
                                ],
                            ],
                        ],
                    ])],
                ], 200);
            }

            if ($url === 'https://upload18.org/play/index/pkpd-418') {
                return Http::response('<script>window.PLAYER_CONFIG = { m3u8: "/play/token_hash?hash=test" };</script>', 200);
            }

            if (str_contains($url, '/play/token_hash')) {
                return Http::response("#EXTM3U\n#EXTINF:60.000,\nseg-1.ts\n#EXTINF:2.800,\nseg-2.ts\n", 200);
            }

            return Http::response('', 404);
        });

        $this->artisan('avdb:sync-durations', [
            '--limit' => '1',
            '--chunk' => '1',
        ])->assertExitCode(0);

        $this->assertSame(63, (int) $video->refresh()->getRawOriginal('duration'));
    }

    public function test_avdb_duration_sync_fills_normal_backlog_after_priority_ids(): void
    {
        User::factory()->create(['id' => 1]);
        $priorityVideo = Video::factory()->create([
            'user_id' => 1,
            'avdb_id' => 90001,
            'duration' => null,
            'published_at' => now(),
        ]);
        $backlogVideo = Video::factory()->create([
            'user_id' => 1,
            'avdb_id' => 90002,
            'duration' => null,
            'published_at' => now()->subDay(),
        ]);

        Cache::put('avdb:duration-priority-video-ids', [$priorityVideo->id], now()->addHour());
        Http::fake(function ($request) {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);
            $ids = array_filter(array_map('intval', explode(',', (string) ($query['ids'] ?? ''))));

            return Http::response([
                'list' => array_map(
                    fn (int $id): array => ['id' => $id, 'time' => '01:02:03'],
                    $ids
                ),
            ], 200);
        });

        $this->artisan('avdb:sync-durations', [
            '--limit' => '2',
            '--chunk' => '2',
        ])->assertExitCode(0);

        $this->assertSame(3723, (int) $priorityVideo->refresh()->getRawOriginal('duration'));
        $this->assertSame(3723, (int) $backlogVideo->refresh()->getRawOriginal('duration'));
    }

    public function test_avdb_duration_sync_does_not_cache_remote_null_result(): void
    {
        $link = 'https://upload18.org/play/index/retry-null-duration';
        $cache = new \ReflectionProperty(RemoteVideoDuration::class, 'cache');
        $cached = $cache->getValue();
        unset($cached[$link]);
        $cache->setValue(null, $cached);

        Http::fake(fn () => Http::response('', 503));

        $this->assertNull(RemoteVideoDuration::fromPlayableLink($link));

        $this->assertArrayNotHasKey($link, $cache->getValue());
    }

    public function test_avdb_duration_sync_pauses_remote_lookup_after_repeated_failures(): void
    {
        User::factory()->create(['id' => 1]);
        foreach (range(1, 4) as $offset) {
            Video::factory()->create([
                'user_id' => 1,
                'avdb_id' => 91000 + $offset,
                'duration' => null,
                'published_at' => now()->subSeconds($offset),
            ]);
        }

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, 'avdbapi.com')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
                $ids = array_filter(array_map('intval', explode(',', (string) ($query['ids'] ?? ''))));

                return Http::response([
                    'list' => array_map(
                        fn (int $id): array => $this->avdbItem([
                            'id' => $id,
                            'time' => '',
                            'episodes' => [
                                'server_data' => [
                                    'Full' => ['link_embed' => "https://upload18.org/play/index/fail-{$id}"],
                                ],
                            ],
                        ]),
                        $ids
                    ),
                ], 200);
            }

            return Http::response('', 404);
        });

        $this->artisan('avdb:sync-durations', [
            '--limit' => '4',
            '--chunk' => '4',
        ])->assertExitCode(0);

        $this->assertSame(4, Video::whereNotNull('avdb_duration_sync_attempted_at')->count());
        $upload18Requests = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_contains((string) $record[0]->url(), 'upload18.org'))
            ->count();
        $this->assertSame(4, $upload18Requests);
    }

    public function test_avdb_duration_scheduler_queues_laravel_jobs_without_remote_http(): void
    {
        $this->withRuntimeEnv('AVDB_DURATION_BACKFILL_MODE', 'queue', function (): void {
            config(['queue.default' => 'database']);
            User::factory()->create(['id' => 1]);
            $video = Video::factory()->create([
                'user_id' => 1,
                'avdb_id' => 79643,
                'duration' => null,
            ]);

            Queue::fake();
            Http::fake();

            $this->artisan('avdb:queue-duration-sync', [
                '--limit' => '1',
                '--chunk' => '1',
            ])->assertExitCode(0);

            Queue::assertPushed(SyncAvdbDurationBatch::class, function (SyncAvdbDurationBatch $job) use ($video): bool {
                return $job->videoIds === [$video->id];
            });
            Http::assertNothingSent();
        });
    }

    public function test_avdb_duration_scheduler_runs_inline_in_auto_mode(): void
    {
        $this->withRuntimeEnv('AVDB_DURATION_BACKFILL_MODE', 'auto', function (): void {
            config(['queue.default' => 'database']);
            User::factory()->create(['id' => 1]);
            $video = Video::factory()->create([
                'user_id' => 1,
                'avdb_id' => 79643,
                'duration' => null,
            ]);

            Queue::fake();
            Http::fake(function ($request) {
                $url = (string) $request->url();
                if (str_contains($url, 'avdbapi.com')) {
                    return Http::response([
                        'list' => [$this->avdbItem([
                            'time' => '01:02:03',
                            'episodes' => ['server_data' => []],
                        ])],
                    ], 200);
                }

                return Http::response('', 404);
            });

            $this->artisan('avdb:queue-duration-sync', [
                '--limit' => '1',
                '--chunk' => '1',
                '--max-runtime' => '5',
            ])->assertExitCode(0);

            Queue::assertNothingPushed();
            $this->assertSame(3723, (int) $video->fresh()->getRawOriginal('duration'));
        });
    }

    public function test_avdb_duration_scheduler_does_not_run_remote_work_on_sync_queue(): void
    {
        $this->withRuntimeEnv('AVDB_DURATION_BACKFILL_MODE', 'queue', function (): void {
            config(['queue.default' => 'sync']);
            User::factory()->create(['id' => 1]);
            Video::factory()->create([
                'user_id' => 1,
                'avdb_id' => 79643,
                'duration' => null,
            ]);

            Http::fake();

            $this->artisan('avdb:queue-duration-sync', [
                '--limit' => '1',
                '--chunk' => '1',
            ])->assertExitCode(0);

            Http::assertNothingSent();
        });
    }

    public function test_avdb_duration_queue_job_falls_back_to_upload18_playlist_duration(): void
    {
        User::factory()->create(['id' => 1]);
        $video = Video::factory()->create([
            'user_id' => 1,
            'avdb_id' => 79643,
            'duration' => 0,
        ]);

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, 'avdbapi.com')) {
                return Http::response([
                    'list' => [$this->avdbItem([
                        'time' => '',
                        'episodes' => [
                            'server_data' => [
                                'Full' => [
                                    'link_embed' => 'https://upload18.org/play/index/queued-duration',
                                ],
                            ],
                        ],
                    ])],
                ], 200);
            }

            if ($url === 'https://upload18.org/play/index/queued-duration') {
                return Http::response('<script>window.PLAYER_CONFIG = { m3u8: "/play/token_hash?hash=queued" };</script>', 200);
            }

            if (str_contains($url, '/play/token_hash')) {
                return Http::response("#EXTM3U\n#EXTINF:40.000,\nseg-1.ts\n#EXTINF:4.400,\nseg-2.ts\n", 200);
            }

            return Http::response('', 404);
        });

        (new SyncAvdbDurationBatch([$video->id]))->handle();

        $this->assertSame(44, (int) $video->refresh()->getRawOriginal('duration'));
    }

    public function test_avdb_import_can_use_movie_code_as_base_and_localized_title(): void
    {
        User::factory()->create(['id' => 1]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/vi/api.php/provide/vod')) {
                return Http::response([
                    'list' => [$this->avdbItem([
                        'name' => 'BOKD-197 Vietnamese translated title',
                        'description' => 'Vietnamese translated description.',
                    ])],
                ], 200);
            }

            return Http::response([
                'list' => [$this->avdbItem()],
            ], 200);
        });

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--api-lang' => 'vi',
            '--translated-only' => true,
            '--movie-code-title' => true,
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $video = Video::where('avdb_id', 79643)->firstOrFail();

        $this->assertSame('BOKD-197', $video->getRawOriginal('title'));
        $this->assertSame('bokd-197', $video->slug);
        $this->assertSame('BOKD-197', $video->avdb_code);

        $this->assertDatabaseHas('video_translations', [
            'video_id' => $video->id,
            'locale' => 'vi',
            'title' => 'BOKD-197',
            'description' => 'Vietnamese translated description.',
        ]);
    }

    public function test_full_language_import_accepts_locale_titles_that_match_english(): void
    {
        User::factory()->create(['id' => 1]);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--full-langs' => true,
            '--locales' => 'vi,ko',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $video = Video::where('avdb_id', 79643)->firstOrFail();

        $this->assertDatabaseHas('video_translations', [
            'video_id' => $video->id,
            'locale' => 'vi',
            'title' => 'BOKD-197 AV debut full API name',
        ]);
        $this->assertDatabaseHas('video_translations', [
            'video_id' => $video->id,
            'locale' => 'ko',
            'title' => 'BOKD-197 AV debut full API name',
        ]);
    }

    public function test_avdb_import_can_seed_starter_views_without_raw_view_rows(): void
    {
        User::factory()->create(['id' => 1]);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
            '--starter-views' => 'natural',
            '--starter-min' => '123',
            '--starter-max' => '123',
            '--starter-daily-stats' => true,
        ])->assertExitCode(0);

        $video = Video::where('avdb_id', 79643)->firstOrFail();

        $this->assertSame(123, (int) $video->views);
        $this->assertSame(0, DB::table('views')->where('video_id', $video->id)->count());
        $this->assertSame(123, (int) DB::table('video_view_daily_stats')
            ->where('video_id', $video->id)
            ->sum('views_count'));
    }

    public function test_avdb_import_can_seed_existing_zero_view_video_once(): void
    {
        User::factory()->create(['id' => 1]);
        $video = Video::factory()->create([
            'user_id' => 1,
            'avdb_id' => 79643,
            'views' => 0,
        ]);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
            '--starter-views' => 'natural',
            '--starter-min' => '77',
            '--starter-max' => '77',
            '--starter-daily-stats' => true,
            '--starter-existing-zero' => true,
        ])->assertExitCode(0);

        $this->assertSame(77, (int) $video->refresh()->views);
        $this->assertSame(77, (int) DB::table('video_view_daily_stats')
            ->where('video_id', $video->id)
            ->sum('views_count'));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
            '--starter-views' => 'natural',
            '--starter-min' => '77',
            '--starter-max' => '77',
            '--starter-daily-stats' => true,
            '--starter-existing-zero' => true,
        ])->assertExitCode(0);

        $this->assertSame(77, (int) $video->refresh()->views);
        $this->assertSame(77, (int) DB::table('video_view_daily_stats')
            ->where('video_id', $video->id)
            ->sum('views_count'));
    }

    public function test_avdb_import_assigns_video_to_generated_director_creator(): void
    {
        User::factory()->create(['id' => 1, 'username' => 'admin']);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $video = Video::where('avdb_id', 79643)->firstOrFail();
        $creator = $video->user()->firstOrFail();

        $this->assertNotSame(1, (int) $creator->id);
        $this->assertSame('Director Name', $creator->username);
        $this->assertSame('director', $creator->avdb_creator_source);
        $this->assertSame('Director Name', $creator->avdb_creator_name);
        $this->assertNotNull($creator->avdb_creator_key);
        $this->assertNotNull($creator->email_verified_at);
        $this->assertIsArray($creator->channel_customization);
        $this->assertStringContainsString('Director Name', $creator->description);
    }

    public function test_avdb_import_can_disable_generated_creator_assignment(): void
    {
        User::factory()->create(['id' => 1, 'username' => 'admin']);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
            '--creator-assignment' => '0',
        ])->assertExitCode(0);

        $video = Video::where('avdb_id', 79643)->firstOrFail();

        $this->assertSame(1, (int) $video->user_id);
        $this->assertDatabaseMissing('users', [
            'avdb_creator_source' => 'director',
            'avdb_creator_name' => 'Director Name',
        ]);
    }

    public function test_avdb_import_falls_back_to_first_actor_when_director_is_missing(): void
    {
        Storage::fake();
        User::factory()->create(['id' => 1, 'username' => 'admin']);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem([
                'director' => '',
                'actor' => 'First Actor, Second Actor',
            ])],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $creator = Video::where('avdb_id', 79643)->firstOrFail()->user()->firstOrFail();

        $this->assertSame('First Actor', $creator->username);
        $this->assertSame('actor', $creator->avdb_creator_source);
        $this->assertNotNull($creator->avatar);
        $this->assertStringEndsWith('.svg', $creator->avatar);
        Storage::assertExists(User::AVATAR_FOLDER.'/'.$creator->avatar);
        $avatar = Storage::get(User::AVATAR_FOLDER.'/'.$creator->avatar);
        $this->assertStringContainsString('data-avdb-avatar="actor"', $avatar);
        $this->assertStringContainsString('<animate', $avatar);
    }

    public function test_avdb_import_keeps_admin_owner_when_people_are_missing(): void
    {
        User::factory()->create(['id' => 1, 'username' => 'admin']);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem([
                'director' => '',
                'actor' => '',
                'tag' => 'Solo Tag',
                'category' => '',
            ])],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $creator = Video::where('avdb_id', 79643)->firstOrFail()->user()->firstOrFail();

        $this->assertSame('admin', $creator->username);
        $this->assertNull($creator->avdb_creator_source);
    }

    public function test_avdb_reimport_reassigns_old_admin_import_to_generated_creator(): void
    {
        User::factory()->create(['id' => 1, 'username' => 'admin']);
        $video = Video::factory()->create([
            'user_id' => 1,
            'avdb_id' => 79643,
        ]);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $video->refresh();
        $this->assertNotSame(1, (int) $video->user_id);
        $this->assertSame('Director Name', $video->user->username);
    }

    public function test_avdb_reimport_does_not_steal_manually_assigned_existing_video(): void
    {
        User::factory()->create(['id' => 1, 'username' => 'admin']);
        $manualOwner = User::factory()->create(['username' => 'Manual Owner']);
        $video = Video::factory()->create([
            'user_id' => $manualOwner->id,
            'avdb_id' => 79643,
        ]);
        Http::fake(fn () => Http::response([
            'list' => [$this->avdbItem()],
        ], 200));

        $this->artisan('avdb:import', [
            '--ids' => '79643',
            '--skip-thumbs' => true,
            '--skip-tags' => true,
        ])->assertExitCode(0);

        $this->assertSame((int) $manualOwner->id, (int) $video->refresh()->user_id);
    }

    private function avdbItem(array $overrides = []): array
    {
        return array_merge([
            'id' => 79643,
            'name' => 'BOKD-197 AV debut full API name',
            'slug' => 'bokd-197-av-debut-full-api-name',
            'movie_code' => 'BOKD-197',
            'description' => 'English full description.',
            'type_id' => 1,
            'type_name' => 'Censored',
            'country' => 'Japan',
            'actor' => 'Misaki Kaede',
            'director' => 'Director Name',
            'writer' => 'Studio Name',
            'category' => ['Drama', 'Featured'],
            'vod_pubdate' => '2026-10-09',
            'vod_time' => '2026-05-02 00:00:00',
            'episodes' => [
                'server_data' => 'bokd-197',
            ],
        ], $overrides);
    }

    private function withRuntimeEnv(string $key, string $value, callable $callback): void
    {
        $previousEnv = $_ENV[$key] ?? null;
        $previousServer = $_SERVER[$key] ?? null;

        $_ENV[$key] = $value;
        unset($_SERVER[$key]);

        try {
            $callback();
        } finally {
            if ($previousEnv === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previousServer;
            }
        }
    }
}
