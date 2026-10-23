<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Pivots\Subscription;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use App\Models\Pivots\PlaylistVideo;
use App\Models\View;
use Illuminate\Database\Seeder;

class ModelSeeder extends Seeder
{

    //use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run() : void
    {
        $users = User::factory(20)->create();
        $userIds = User::query()->pluck('id');

        $users->each(function($user) use ($userIds) {
            Video::factory(rand(1, 20))->create(['user_id' => $user->id])->each(function ($video) use ($userIds) {
                Comment::factory(rand(0, 10))->createQuietly([
                    'video_id' => $video->id,
                    'user_id' => $userIds->random(),
                ])->each(function ($comment) use ($video, $userIds) {
                    Comment::factory(rand(0, 5))->createQuietly([
                        'parent_id' => $comment->id,
                        'video_id' => $video->id,
                        'user_id' => $userIds->random(),
                    ])->each(function ($comment) use ($video) {
                        Interaction::factory(rand(0, 5))->createQuietly(['likeable_type' => Comment::class, 'likeable_id' => $comment->id]);
                    });
                    Interaction::factory(rand(0, 10))->create(['likeable_type' => Comment::class, 'likeable_id' => $comment->id]);
                    Report::factory(fake()->optional(0.1,  0)->numberBetween(1, 1))->create(['reportable_type' => Comment::class, 'reportable_id' => $comment->id]);
                });
                Interaction::factory(rand(0, 20))->create(['likeable_type' => Video::class, 'likeable_id' => $video->id]);
                View::factory(rand(0, 30))->create(['video_id' => $video->id]);
                Report::factory(fake()->optional( 0.1,  0)->numberBetween(1, 1))->create(['reportable_type' => Video::class, 'reportable_id' => $video->id]);
            });
        });

        $users->each(function($user) {
            Report::factory(fake()->optional( 0.1, 0)->numberBetween(1, 1))->create(['reportable_type' => User::class, 'reportable_id' => $user->id]);
            Subscription::factory(rand(0, 20))->create(['subscriber_id' => $user->id]);
            Playlist::factory(rand(0, 1))->create(['user_id' => $user->id])->each(function($playlist) {
                PlaylistVideo::factory(rand(0, 4))->create(['playlist_id' => $playlist->id]);
            });
        });
    }
}
