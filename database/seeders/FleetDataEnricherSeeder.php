<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\Interaction;
use App\Enums\PlaylistStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FleetDataEnricherSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Categories
        $targetCategories = [
            ['title' => '4K Ultra HD', 'slug' => '4k-ultra-hd', 'icon' => 'tv', 'in_menu' => 1, 'position' => 1],
            ['title' => 'Trending Now', 'slug' => 'trending-popular', 'icon' => 'fire', 'in_menu' => 1, 'position' => 2],
            ['title' => 'Japanese AV', 'slug' => 'japanese-av', 'icon' => 'star', 'in_menu' => 1, 'position' => 3],
            ['title' => 'English Subtitle', 'slug' => 'english-subtitle', 'icon' => 'closed-captioning', 'in_menu' => 1, 'position' => 4],
            ['title' => 'Censored', 'slug' => 'censored', 'icon' => 'shield-halved', 'in_menu' => 1, 'position' => 5],
            ['title' => 'Uncensored', 'slug' => 'uncensored', 'icon' => 'lock-open', 'in_menu' => 1, 'position' => 6],
            ['title' => 'Uncensored Leaked', 'slug' => 'uncensored-leaked', 'icon' => 'droplet', 'in_menu' => 1, 'position' => 7],
            ['title' => 'Cosplay & Anime', 'slug' => 'cosplay-anime', 'icon' => 'mask', 'in_menu' => 1, 'position' => 8],
            ['title' => 'Amateur & Couples', 'slug' => 'amateur', 'icon' => 'camera', 'in_menu' => 1, 'position' => 9],
            ['title' => 'Chinese AV', 'slug' => 'chinese-av', 'icon' => 'dragon', 'in_menu' => 1, 'position' => 10],
            ['title' => 'Hentai & 3D', 'slug' => 'hentai', 'icon' => 'wand-magic-sparkles', 'in_menu' => 1, 'position' => 11],
            ['title' => 'VR 360 & POV', 'slug' => 'vr-pov', 'icon' => 'vr-cardboard', 'in_menu' => 1, 'position' => 12],
            ['title' => 'Verified Studios', 'slug' => 'verified-studios', 'icon' => 'circle-check', 'in_menu' => 1, 'position' => 13],
            ['title' => 'Romance & Sensual', 'slug' => 'romance-sensual', 'icon' => 'heart', 'in_menu' => 1, 'position' => 14],
            ['title' => 'Cyberpunk & Sci-Fi', 'slug' => 'cyberpunk-scifi', 'icon' => 'microchip', 'in_menu' => 1, 'position' => 15],
            ['title' => 'Retro & Vintage', 'slug' => 'retro-vintage', 'icon' => 'record-vinyl', 'in_menu' => 1, 'position' => 16],
            ['title' => 'ASMR & Audio', 'slug' => 'asmr-audio', 'icon' => 'headphones', 'in_menu' => 1, 'position' => 17],
            ['title' => 'Big Tits', 'slug' => 'big-tits', 'icon' => 'gem', 'in_menu' => 1, 'position' => 18],
            ['title' => 'Creampie', 'slug' => 'creampie', 'icon' => 'water', 'in_menu' => 1, 'position' => 19],
            ['title' => 'Anal', 'slug' => 'anal', 'icon' => 'bolt', 'in_menu' => 1, 'position' => 20],
            ['title' => 'Teen (18+)', 'slug' => 'teen', 'icon' => 'graduation-cap', 'in_menu' => 1, 'position' => 21],
            ['title' => 'MILF & Mature', 'slug' => 'milf-mature', 'icon' => 'crown', 'in_menu' => 1, 'position' => 22],
            ['title' => 'Asian Exclusive', 'slug' => 'asian-exclusive', 'icon' => 'globe', 'in_menu' => 1, 'position' => 23],
            ['title' => 'Western & European', 'slug' => 'western-european', 'icon' => 'earth-americas', 'in_menu' => 1, 'position' => 24],
            ['title' => 'Solo & Camgirls', 'slug' => 'solo-cam', 'icon' => 'user', 'in_menu' => 1, 'position' => 25],
            ['title' => 'Behind The Scenes', 'slug' => 'behind-the-scenes', 'icon' => 'clapperboard', 'in_menu' => 1, 'position' => 26],
            ['title' => 'Outdoor & Public', 'slug' => 'outdoor-public', 'icon' => 'mountain-sun', 'in_menu' => 1, 'position' => 27],
            ['title' => 'Parody & Comedy', 'slug' => 'parody-comedy', 'icon' => 'face-laugh-squint', 'in_menu' => 1, 'position' => 28],
            ['title' => 'Midnight Special', 'slug' => 'midnight-special', 'icon' => 'moon', 'in_menu' => 1, 'position' => 29],
            ['title' => 'Staff Picks & Awards', 'slug' => 'staff-picks', 'icon' => 'award', 'in_menu' => 1, 'position' => 30],
        ];

        foreach ($targetCategories as $tc) {
            Category::updateOrCreate(
                ['slug' => $tc['slug']],
                ['title' => $tc['title'], 'icon' => $tc['icon'], 'in_menu' => $tc['in_menu'], 'position' => $tc['position']]
            );
        }

        // 2. Tags
        $popularTags = [
            '4k', '1080p', '60fps', 'japanese', 'english-sub', 'trending', 'featured', 'exclusive',
            'remastered', 'cinema', 'top-rated', 'new-release', 'verified', 'cosplay', 'vr360',
            'pov', 'amateur', 'studio', 'milf', 'creampie', 'subtitled', 'retro', 'behind-the-scenes',
            'hdr', 'ultra-hd', 'tokyo-hot', 's1', 'moodyz', 'cyberpunk', 'asmr', 'theater-edition',
            'director-cut', 'uncensored', 'censored', 'bikini', 'schoolgirl', 'office-lady', 'lingerie',
            'massage', 'maid', 'nurse', 'cheerleader', 'goddess', 'high-bitrate'
        ];

        $tagIds = [];
        foreach ($popularTags as $tname) {
            $slug = Str::slug($tname);
            $tag = Tag::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucwords(str_replace('-', ' ', $tname)), 'usage_count' => rand(50, 450)]
            );
            $tagIds[] = $tag->id;
        }

        // Link tags to sample videos
        $randomVideoIds = Video::inRandomOrder()->take(400)->pluck('id')->toArray();
        $now = now()->toDateTimeString();
        $pivotBatch = [];
        foreach ($randomVideoIds as $vid) {
            $shuffledTags = $tagIds;
            shuffle($shuffledTags);
            $picked = array_slice($shuffledTags, 0, rand(3, 5));
            foreach ($picked as $tid) {
                $pivotBatch[] = [
                    'video_id' => $vid,
                    'tag_id' => $tid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (!empty($pivotBatch)) {
            foreach (array_chunk($pivotBatch, 500) as $chunk) {
                DB::table('video_tags')->insertOrIgnore($chunk);
            }
        }

        // 3. Community Users
        $communityUsers = [
            ['username' => 'CinemaCurator_JP', 'desc' => 'Tokyo-based 4K film archivist & Japanese AV enthusiast. Curating top cinematic releases.', 'country' => 'JP'],
            ['username' => 'AestheticVault', 'desc' => 'High-resolution streaming connoisseur. 1080P & 60FPS enthusiast.', 'country' => 'US'],
            ['username' => 'SubTitleNinja', 'desc' => 'Dedicated translator and Japanese culture lover. All uploads fully subtitled.', 'country' => 'GB'],
            ['username' => 'RetroVHS_Archivist', 'desc' => 'Preserving 90s and 2000s classics with digital remastering.', 'country' => 'CA'],
            ['username' => 'CosplayHaven4K', 'desc' => 'Cosplay, anime conventions, and fantasy realism productions.', 'country' => 'TW'],
            ['username' => 'CyberPunkVisuals', 'desc' => 'Neon aesthetics, synthwave playlists, and futuristic visual streams.', 'country' => 'DE'],
            ['username' => 'AudioPhileStreamer', 'desc' => 'Spatial audio, binaural ASMR, and studio acoustic mastering reviewer.', 'country' => 'AU'],
            ['username' => 'MidnightVoyeur', 'desc' => 'Night owl curator. Deep tracks, sensual romance, and midnight specials.', 'country' => 'FR'],
            ['username' => 'NeoTokyoStreamer', 'desc' => 'Shinjuku nightlife & high-motion 4K street reels.', 'country' => 'JP'],
            ['username' => 'CinephileClub_VIP', 'desc' => 'Official VIP Member. Reviewing top weekly releases and indie masterpieces.', 'country' => 'KR'],
            ['username' => 'OtakuNexus_HQ', 'desc' => 'The ultimate nexus for anime, 3D CGI, and cosplay entertainment.', 'country' => 'SG'],
            ['username' => 'SilverScreen_Lover', 'desc' => 'Passionate film buff exploring modern adult cinema cinematography.', 'country' => 'US'],
            ['username' => 'KuroNeko_Cosplay', 'desc' => 'Costume designer and convention streamer. Realism & fantasy.', 'country' => 'JP'],
            ['username' => 'NightPulse_Review', 'desc' => 'Daily video reviews and timestamp analysis. Honest rating scorecards.', 'country' => 'NZ'],
            ['username' => 'TokyoNeonDrifter', 'desc' => 'Late night Tokyo drives, neon lights, and cinematic video cuts.', 'country' => 'JP'],
            ['username' => 'VividReels_VIP', 'desc' => 'High bitrate 4K streaming collector. Quality above everything.', 'country' => 'NL'],
            ['username' => 'CinemaScopeKing', 'desc' => '21:9 theatrical widescreen lover. The cinema experience in your browser.', 'country' => 'SE'],
            ['username' => 'PixelWave_Collector', 'desc' => 'Vaporwave, lo-fi beats, and aesthetic video visualizers.', 'country' => 'BR'],
            ['username' => 'GoldenAge_Film', 'desc' => 'Classic cinema aficionado. Golden age productions and rare footage.', 'country' => 'IT'],
            ['username' => 'ApexStream_Master', 'desc' => 'Ultra high fidelity 60FPS streaming setup tester.', 'country' => 'US'],
        ];

        $hashedPassword = Hash::make('password123');
        $seededUserIds = [];
        foreach ($communityUsers as $cu) {
            $slug = Str::slug($cu['username']);
            $user = User::firstOrCreate(
                ['username' => $cu['username']],
                [
                    'slug' => $slug,
                    'profile_uid' => substr(md5($cu['username']), 0, 8),
                    'email' => strtolower($cu['username']) . '@avdbcms.example',
                    'password' => $hashedPassword,
                    'email_verified_at' => now(),
                    'description' => $cu['desc'],
                    'country' => $cu['country'],
                    'website' => 'https://' . $slug . '.avdbcms.example',
                    'show_subscribers' => 1,
                    'last_login_at' => now()->subHours(rand(1, 48)),
                    'last_login_ip' => '127.0.0.1',
                ]
            );
            $seededUserIds[] = $user->id;
        }

        // 4. Playlists
        $curatedPlaylists = [
            ['title' => 'Top 10 Trending Masterpieces This Week', 'desc' => 'The hottest, highest-rated streams across the entire community.'],
            ['title' => 'Staff Picks & Editors Choice (4K)', 'desc' => 'Hand-curated selections featuring incredible production values and cinematography.'],
            ['title' => 'Ultimate Japanese AV (JAV) Showcase', 'desc' => 'Iconic releases from Japan top studios with English subtitles and full 1080p.'],
            ['title' => 'Cosplay & Anime Realism Gems', 'desc' => 'Brilliant roleplay, handcrafted costumes, and fantasy studio productions.'],
            ['title' => 'Cyberpunk Neon Midnight Selections', 'desc' => 'Synthwave mood, futuristic neon aesthetics, and nighttime visual immersion.'],
            ['title' => 'CinemaScope 21:9 Widescreen Collection', 'desc' => 'Anamorphic theatrical widescreen masterpieces optimized for Cinema Zoom.'],
            ['title' => 'Verified English Subtitled Essentials', 'desc' => 'Top Japanese and international releases with crystal clear subtitle translations.'],
            ['title' => 'Late Night Acoustic & Chill Watching', 'desc' => 'Sensual romance, slow cinema pacing, and warm analog aesthetics.'],
            ['title' => 'Vintage Classics: 90s & 2000s Golden Era', 'desc' => 'Timeless legendary releases digitally restored and upscaled.'],
            ['title' => 'High-Octane 60 FPS Streams', 'desc' => 'Silky smooth high framerate video streams for high refresh rate monitors.'],
        ];

        foreach ($curatedPlaylists as $idx => $cp) {
            $ownerId = $seededUserIds[$idx % count($seededUserIds)];
            $playlist = Playlist::firstOrCreate(
                ['title' => $cp['title']],
                [
                    'uuid' => Str::uuid()->toString(),
                    'description' => $cp['desc'],
                    'user_id' => $ownerId,
                    'status' => PlaylistStatus::PUBLIC,
                    'is_deletable' => true,
                ]
            );

            $hasVideos = DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->count();
            if ($hasVideos < 10) {
                $sampleVideos = Video::inRandomOrder()->take(15)->pluck('id');
                $pos = 1;
                $batchPV = [];
                foreach ($sampleVideos as $svId) {
                    $batchPV[] = [
                        'playlist_id' => $playlist->id,
                        'video_id' => $svId,
                        'position' => $pos++,
                        'added_at' => now()->subMinutes(rand(10, 10000))->toDateTimeString(),
                    ];
                }
                DB::table('playlist_has_videos')->insertOrIgnore($batchPV);
            }
        }

        // 5. Comments
        $commentTemplates = [
            "The 1080p bitrate on this stream is unbelievable, absolutely zero buffering! 🚀",
            "Thanks so much for the English subtitles! Finally understand the storyline and context.",
            "Timestamp 14:22 is pure art, the lighting setup and camera angle was legendary 🔥",
            "Using the new Cinema Lights Out mode (L key) on this makes it feel like an IMAX theater.",
            "Does anyone know the track playing in the background at 08:30? Need it for my playlist!",
            "One of the best releases this month hands down. Bookmarked to favorites immediately.",
            "The audio mastering is so crisp. Listening with the Bass Boost mode on headphones is incredible.",
            "Great upload! Hope to see more releases from this studio soon.",
            "The framing and color grading in this scene is genuinely cinematic. 10/10 recommendation.",
            "Picture-in-picture mini player (P key) works flawlessly while browsing other videos!",
            "Top tier cinematography. The director really outdid themselves with this one.",
            "Instant classic! Added directly to my Weekend Binge playlist.",
            "The high refresh rate 60fps presentation looks stunning on OLED displays.",
            "Such a wholesome and passionate scene. Great chemistry between the actors.",
            "Bookmarked at 22:45! What an incredible climax sequence.",
        ];

        $replyTemplates = [
            "Totally agree! That scene caught me completely by surprise.",
            "It's a retro synthwave track from the Neon Nights album, so good!",
            "Same here, the Lights Out mode is a total gamechanger for late night watching.",
            "Press 'C' to bookmark that exact second! The timestamp shelf is super handy.",
            "100%! This studio always delivers the highest production values.",
            "Check out their previous release too, equally stunning quality!",
        ];

        $commentTargetVideos = Video::inRandomOrder()->take(50)->get(['id']);
        foreach ($commentTargetVideos as $video) {
            $existing = DB::table('comments')->where('video_id', $video->id)->count();
            if ($existing < 4) {
                $num = rand(4, 7);
                for ($i = 0; $i < $num; $i++) {
                    $uId = $seededUserIds[array_rand($seededUserIds)];
                    $content = $commentTemplates[array_rand($commentTemplates)];
                    $cDate = now()->subDays(rand(0, 20))->subMinutes(rand(1, 1440))->toDateTimeString();
                    
                    $cId = DB::table('comments')->insertGetId([
                        'video_id' => $video->id,
                        'user_id' => $uId,
                        'parent_id' => null,
                        'content' => $content,
                        'ip' => '127.0.0.1',
                        'created_at' => $cDate,
                        'updated_at' => $cDate,
                    ]);

                    if (rand(1, 10) <= 5) {
                        $rUid = $seededUserIds[array_rand($seededUserIds)];
                        $rContent = $replyTemplates[array_rand($replyTemplates)];
                        $rDate = now()->subDays(rand(0, 15))->toDateTimeString();
                        DB::table('comments')->insert([
                            'video_id' => $video->id,
                            'user_id' => $rUid,
                            'parent_id' => $cId,
                            'content' => $rContent,
                            'ip' => '127.0.0.1',
                            'created_at' => $rDate,
                            'updated_at' => $rDate,
                        ]);
                    }
                }
            }
        }

        // 6. Views & Telemetry
        DB::statement("UPDATE videos SET views = FLOOR(5000 + (RAND() * 450000)) WHERE views IS NULL OR views < 500");
    }
}
