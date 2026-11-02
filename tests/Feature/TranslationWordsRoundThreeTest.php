<?php

namespace Tests\Feature;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Playlist;
use App\Models\User;
use App\Support\ViewOnlyDemoWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3 fix pass, lane TL: words that said something else, or one thing with two words, in the languages of the
 * site (judge round 3 and the items the pre-fix lanes saw).
 *
 *  - R3-012 / R3-047: French 'Playlist' read "Champs de liste de lecture" (playlist fields); German said "Playlists" in
 *    the menu, the title and the search but "Wiedergabeliste" in the button, the editor and the empty state. Each
 *    language now says a playlist with the word most of its texts used: de Playlist, fr playlist, id playlist,
 *    ja プレイリスト, ru плейлист, tr oynatma listesi, pt lista de reprodução.
 *  - R3-052: the view-only demo's "This is a demo" window used the admin's status word "OK" (Latin "OK" in every
 *    language): its button has its own words ("Got it").
 *  - R3-056: the Premium page's buttons and labels: one form of address and one mood per pair of buttons, the language's
 *    own words (pt "Descargas" was Spanish), one word for a plan in each language.
 *  - R3-064: the plain-text part of the member mails glued a colon to each label ("Gérer les e-mails:"): the language
 *    puts its own (French a no-break space and ":", Chinese "：").
 *  - R3-065: Arabic counts in Arabic's six plural forms ("38 فيديوهات" for 38: Arabic says "38 فيديو"), Russian counts in
 *    its three ("21 просмотров").
 *  - R3-075: Arabic says an administrator with one word (مشرف) and the admin itself (the place) لوحة الإدارة.
 *  - R3X-04: "player" (the video player) translated with a word for a person who plays (es jugador, de Spieler, fr
 *    joueur, ar لاعب, ru игрок ...).
 *  - R3X-05: config/menu.php's titles are collected by the translation tools (scripts/i18n/sources.json).
 *  - R3X-06: Community > Reports ("Block it", "Waiting", the report word of the page), the admin's "Clear ..." buttons
 *    ("Clear Guests" read "obvious guests" in Malay), Appearance > Effects & speed ("Heavy on phones" read "I am very
 *    good with phones" in Russian; "light effects" as lighting; "still backgrounds" as "nevertheless"); the site's
 *    header and footer said with one word each (zh 标头 / 页眉 / 标题, ko 헤더 / 머리글, tr Başlık / Üstbilgi; Korean
 *    "Footer" read "pedestrian") and the same page's dark/light switches with the site's own words for dark and light
 *    (es "oscuridad/luz", ar "الظلام/الضوء" = darkness / light, ms "lampu" = a lamp).
 *
 *
 * Second pass (the independent check of the fix pass):
 *  - TL-O1: 'video' with one word (es video, not vídeo; ko 동영상, not 비디오; ja 動画, not ビデオ; ar no "videotapes").
 *  - TL-O2: 'upload' with one word (fr mettre / mise en ligne, not télécharger = download; ar رفع, not تحميل; pt upload,
 *    not carregar; es subir / subida, not cargar; id unggah; th อัปโหลด).
 *  - TL-O3: the language switcher with one name (es selector de idioma, pt seletor de idioma, fil / tl tagapagpalit ng
 *    wika, th ตัวสลับภาษา, id pengalih bahasa, vi nút chuyển ngôn ngữ).
 *  - TL-O4: the member mails say Premium the way the pages do (ar بريميوم, ru Премиум, zh 高级版, hi, ja, ko, th).
 *  - R3-056: the Premium page's plan names and prices with one pair of words (ja 毎月 / 毎年, ko 매월 / 매년), zh 高级版.
 *  - TL-R1: the Preact counts (comments, views, results, replies) in Arabic's six and Russian's three plural forms.
 *  - TL-R2: the admin's Arabic count lines with the dual and the noun Arabic puts after 3-10, 11-99 and 100+.
 *  - TL-R3: theater mode with the watch page's own word (de Kinomodus, es modo cine, ru режим кинотеатра ...).
 *  - TL-R4: Portuguese in Brazilian Portuguese (excluir, senha, celular, inscritos ...), in the PHP and Preact texts.
 *  - TL-R5: the texts printed only inside the hands-off Upload18 / player files keep their translations (OWNER_TEXTS).
 *  - TL-R7: UI words that share their key with the imported category glossary ('Plain', 'Unit', 'Words', 'Multiple',
 *    'Japanese', 'Bronze', 'Travel' ...) read as UI words, the themes' homepage voices say what they mean, imports are
 *    not trade imports, the watch page is not a wristwatch, and Appearance > Themes describes the looks and themes in
 *    design words (no lunch boxes, regulations, paper clips or trousers).
 *  - R3X-06: the rest of Effects & speed (the device rule's choices, one cost word, 'Watched bar on cards', the dark /
 *    light help) and Header, menus & footer (style names, the footer's bottom line, the copyright line, 'Terms', the
 *    "Back to top" look, the link lists' format) and the admin Home's '1 report waiting'.
 *
 * Third pass (the independent check of the second pass):
 *  - R3X-06 (b): the old admin's Header style select quotes 'Solid bar', 'Translucent bar' and 'Icon rail' with those
 *    options' own words (de "durchgezogenen Balken" beside "Einfarbige Leiste"), its help names the options as they are,
 *    and the old Appearance tab's header, side menu and footer switches say what the new admin says for the same
 *    settings (the language switcher, the dark/light switch, ar 'Plan Switcher' read "the executioner's plan").
 *  - R3X-06 (same kinds): a look is not a gaze (es "Mirada de tarjeta", ar "نظرة المصنع", ms "Lihat bar skrol", zh
 *    回到工厂看看) and a complete look has one word per language; a visitor's report is said with the Reports page's word
 *    (not de Bericht, es informe, fr rapport ...); the old tab's homepage words are not railways, houses, animal food,
 *    "Mrs." or TV shows.
 *  - TL-R6: the menu paths quote the menu's words (ja [収益化] > [広告]); the admin (the place) is not an administrator;
 *    the 12 settings texts that quote the watch page's "Up next" list say its heading's words, and the old 'Sticky
 *    Sidebar' of the same setting is not glue.
 *  - R3X-06 (same kinds) on the rest of the old settings page: its ad spaces and the Money > Ads help say where they are
 *    and what they take ('Mobile Sticky Mini Banner' read self-adhesive, 'Watch Sidebar Card' "watch the sidebar card",
 *    "Keep it calm" an order to stay calm, 'Backup for video ads' a data backup, "a small banner phone").
 *
 * Fourth pass (the independent check of the third pass):
 *  - 'Category' (a regression of the third pass): the generic key had been given the words of one setting ("Category
 *    for the main list") in every language, and fifteen other places print it (the public category page's kicker, the
 *    studio's video form ...). It keeps its plain word; the old Appearance tab prints 'Category for the main list'
 *    itself, and an old label takes the new admin's words only where the old settings page is its only use.
 *  - TL-R4: Portuguese is Brazilian Portuguese in every text, the member e-mails, the invoices page and the PHP language
 *    files too (Gerenciar, "Você recebe este e-mail", "Entre em contato conosco", "Baixar o PDF", "permanece até o fim
 *    do período pago", curtida, link, Sair, "Assistir mais tarde", senha, arquivo, "deve" ...).
 *  - TL-O3: the Settings home's Languages card names the switcher as its examples line does (pt, fil, tl).
 *  - Spanish names a visitor's report one way in the report dialogs too (reportar / reporte).
 *  - Every text that names the "Discover channels" page says its exact name; its help says the largest number of
 *    channels the page takes, not "most of the channels".
 *  - The Header style help names the styles as the options do; Japanese says a phone スマホ and writes one katakana
 *    word one way (カテゴリ, サーバー, フォルダー, ブラウザ, フィルター ...).
 *  - The same kinds around them: the helps and confirms that quote a switch, a button or an option say its words (a
 *    label worded as an order to the reader, or a shop counter for 'Counter only', corrected first); the nine
 *    'Words in {braces} ...' helps name braces as braces (pt "colchetes" = square brackets, tr "Parantez").
 *
 * Fifth pass (the third independent check of the fourth pass):
 *  - R3X-04: Chinese said the video player 球员 (a ball player) on Appearance > Video page ('Wide player on top',
 *    'Player with stats', and 'The player opens wide.' = "the athlete opens his mouth wide"); the layout option quotes
 *    the "Up next" list with its heading's words, and its stats panel shows views and likes, not opinions or favourites.
 *  - R3X-06, words read in another sense next to the ones fixed before: chips as microchips, wafers, tokens or potato
 *    chips; a kicker as a football kicker; the top banner ("hero") as a hero, a band as a music band, a title as a
 *    rank, a subtitle as film subtitles, the "small line" above a title as a drawn line, slides as playground slides,
 *    "rotate the banner" as spinning it, the verified creator as God; the homepage rows as a quarrel (ru) and named
 *    several ways in one card; view counts as opinions, a display or the verb "look at" (and "view inflation" as the
 *    economy); seeds as plant seeds and counters as shop counters.
 *  - R3X-06 (b) kind: the English word "Off" / "On" left in a translation (the 25 creator-choice helps, the daily
 *    layout help, the AVDBAPI movie_code help, 'Motion Off').
 *  - TL-R2 / R3-065: the Arabic form for two is the dual ('1 view|:count views' said "2 وجهات النظر" = two points of
 *    view; '1 video in this category.' "2 فيديو").
 *  - TL-R4: the maintenance page's "Estamos a atualizar" and "terminaram a sessão" (the test's patterns missed a
 *    capital letter and that form), and the sentence-start "O seu" / "A sua" of European Portuguese.
 *  - The look-around (p52): the System pages' technical words in their everyday sense (a route as a road, Blade as a
 *    knife, a driver as a chauffeur, "Present" as a gift ...), the queue's jobs and workers as job vacancies and
 *    labourers, a video's duration as a period (ja / ko).
 *  - After the restart (p53): the paths, file names and setting names an owner has to find or type are written as the
 *    English writes them (storage/app/private/avdb-avatar-pack, disable_functions, poster_url, queue-worker.log ...).
 *
 * Sixth pass (the fourth independent check of the fifth pass):
 *  - TL-VERIFY4-1: the accent colour as a speech accent, a dialect or a pronunciation ('Own accent colour' ar / hi / ms; the
 *    old settings page's 'Accent Choices', 'Default Custom Accent', 'Comp Accent', 'Brand Accent') and 'Banner Treatments'
 *    as a medical treatment: every text that names the accent colour says it with the language's term (p60a); the old
 *    page's channel section takes the new admin's words (p60z) and prints its channel-default labels from the settings
 *    catalogue (they were English in every language); Appearance > Creator channels names each thing one way (p60b: the
 *    "Default channel look" selects, the help "channels that have not chosen their own channel", the creators' word).
 *  - TL-VERIFY4-2: Appearance > Homepage names the banner, the slides, the tiles and the rows one way, 'Change slide every'
 *    says "every <n> seconds" (zh "every other time", th / tr "every time", hi "change every slide", fr the gender), and
 *    the page's texts read in their own sense (p60c); Russian says the site's homepage one way (p60e).
 *  - OQ-10, approved by the owner on 30 Oct 2026: 112 corrected translations of 44 texts printed only by the upload
 *    window, the Upload18 flow and the player (OWNER_APPROVED; p69). The one-word checks read them like any other text;
 *    the other translations of those texts, and the other owner's texts, stay as they are.
 *
 * Seventh pass (the fourth independent check's follow-up, verify5):
 *  - TL-VERIFY5-1: the settings catalogue's number fields print their unit ('seconds' / 'minutes' / 'days') as one fixed
 *    word after a number input (field.blade.php: __($f['suffix'])). A fixed word cannot agree with every number typed:
 *    ru секунды/минуты fit only 2-4 and дни no number; ar has six plural forms; tr forbids the plural after a numeral.
 *    A count-invariant form fits any number (ru сек./мин./дн., ar the short singular ثانية/دقيقة/يوم, tr gün). The ru
 *    slide-interval label 'Change slide every' becomes the infinitive 'Менять слайд каждые', like the page's other
 *    labels, not the order 'Меняйте слайд каждые'.
 *  - TL-VERIFY5-2: Appearance > Homepage named the site's homepage two ways - es 'Homepage' «Página principal» while the
 *    rest of the page says «página de inicio»; ms 'Homepage'/'Homepage layout' 'laman utama' while the rest says
 *    'halaman utama'. Each page key now uses the page's one word (es «Página de inicio», ms 'halaman utama'), distinct
 *    from the admin's own Home (es «Inicio», ms 'Laman Utama'). A homepage-name census over all 17 languages on this
 *    page finds one word each in the other fifteen.
 *
 * Eighth pass (the fourth independent check's second follow-up, verify6):
 *  - TL-VERIFY6-1: the unit after a number field fits ANY number in every language: the short unit each language writes
 *    after a number with one form for every plural category (CLDR's short / narrow unit patterns) - ar ث / د / ي (the
 *    singular ثانية misfit 2 and 3-10), de Sek. / Min. / Tg., es s / min / d, fr s / min / j, pt s / min / d (the plurals
 *    misfit 1, and 0 in fr / pt); ms says the second "saat", as its helps do.
 *  - TL-VERIFY6-2: the 22 number fields' labels lead into "[n] <unit>" ('Tips look at the last' read "look at the last
 *    one", 'Next video starts after' "afterwards / after the next / after this date", 'Tell Google to come back after'
 *    "later", es / pt lacked "de", fr "tous les minutes", de "seit 21 Tage" ...) and their helps say what they mean
 *    ("in a few seconds", "second-class articles", "seconds browsers", surveillance, ticked checkboxes, goods).
 *  - TL-VERIFY6-3: the admin pages name the site's homepage one way (de Homepage, hi मुखपृष्ठ, ms halaman utama, zh 主页;
 *    the admin's own Home keeps its own word), the Effects help quotes 'Animate homepage sections' with its words, and
 *    the old settings page's homepage texts and ad slots say it too.
 *  - TL-VERIFY6-4: the section animation's options are animations (not an elevator, a playground slide or discolouring),
 *    their help is a list, not orders; zh says a section 区块; the live preview's device switch says "preview on" and a
 *    phone (its own key 'Mobile phone'; 'Phone' stays the profile's phone number).
 *
 * The checks read the merged translation files (lang/<locale>.json, resources/js/lang/<locale>.ts), as the other
 * language tests do.
 */
class TranslationWordsRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['ar', 'de', 'es', 'fil', 'fr', 'hi', 'id', 'ja', 'ko', 'ms', 'pt', 'ru', 'th', 'tl', 'tr', 'vi', 'zh'];

    /** A playlist said with the word most texts of the language did not use. */
    private const NOT_PLAYLIST = [
        'de' => '/wiedergabeliste/iu', 'fr' => '/listes? de lecture/iu', 'id' => '/daftar putar/iu', 'ja' => '/再生リスト/u',
        'ru' => '/списк\w* воспроизведения/iu', 'tr' => '/çalma liste/iu', 'pt' => '/(?<!\p{L})playlists?(?!\p{L})/iu',
    ];

    /** A person who plays (sportsman, gamer, actor) where the text says the video player. */
    private const PERSON = [
        'es' => '/\bjugador(a|es|as)?\b/iu', 'de' => '/spieler/iu', 'fr' => '/\bjoueu(r|rs|se|ses)\b/iu', 'pt' => '/\bjogador/iu',
        'tr' => '/\boyuncu/iu', 'ru' => '/игрок/iu', 'th' => '/ผู้เล่น/u', 'vi' => '/người chơi/iu', 'zh' => '/玩家|球员|选手|运动员/u', 'ar' => '/لاعب/u',
        'hi' => '/खिलाड़ी/u', 'id' => '/\bpemain\b/iu', 'fil' => '/manlalaro/iu', 'tl' => '/manlalaro/iu', 'ja' => '/選手/u', 'ko' => '/선수/u',
    ];

    /** The plan word a language does not use any more (the one most of its plan texts use stays). */
    private const NOT_PLAN = [
        'de' => '/(?<!\p{L})(Abo|Abos|Tarif)(?!\p{L})/u', 'ru' => '/тариф/iu', 'fr' => '/(?<!\p{L})(formules?|plans?)(?!\p{L})/iu',
        'zh' => '/套餐|方案/u', 'id' => '/rencana/iu', 'tr' => '/paket/iu', 'ja' => '/計画/u', 'ko' => '/계획|요금제/u',
        'th' => '/แพ็กเกจ/u', 'vi' => '/kế hoạch/iu', 'hi' => '/प्लान(?! आईडी)/u', 'fil' => '/(?<!\p{L})plan(?! ID)(?!\p{L})/u',
        'tl' => '/(?<!\p{L})plan(?! ID)(?!\p{L})/u',
    ];

    /**
     * The site's header and footer: [header word, footer word] each language keeps (the words of the page
     * Appearance > Header, menus & footer; where most texts used a word for a title - ru заголовок, tr başlık,
     * vi tiêu đề, zh 标题 - the page's own word).
     */
    private const HEADER_FOOTER = [
        'ar' => ['/رأس/u', '/تذييل/u'], 'de' => ['/Kopfzeile/u', '/Fußzeile/u'], 'es' => ['/encabezado/iu', '/pie de página/iu'],
        'fil' => ['/header/iu', '/footer/iu'], 'fr' => ['/en-tête/iu', '/pied de page/iu'], 'hi' => ['/हेडर/u', '/पाद लेख/u'],
        'id' => ['/header/iu', '/footer/iu'], 'ja' => ['/ヘッダー/u', '/フッター/u'], 'ko' => ['/헤더/u', '/바닥글/u'],
        'ms' => ['/pengepala/iu', '/pengaki/iu'], 'pt' => ['/cabeçalho/iu', '/rodapé/iu'], 'ru' => ['/шапк/iu', '/подвал/iu'],
        'th' => ['/ส่วนหัว/u', '/ส่วนท้าย/u'], 'tl' => ['/header/iu', '/footer/iu'], 'tr' => ['/üstbilgi/iu', '/altbilgi/iu'],
        'vi' => ['/đầu trang/iu', '/chân trang/iu'], 'zh' => ['/页眉/u', '/页脚/u'],
    ];

    /**
     * Texts printed only inside the hands-off Upload18 / player files and regions (the upload window, VideoUpload.tsx,
     * Player.tsx, Ad.tsx, the guarded player block of the watch page, the Upload18 answers). They keep their translations
     * in 1.2.3 (TL-R5: the translation lane's corrections of them were a proposal for the owner), so the one-word checks
     * skip them - except the translations the owner approved (OWNER_APPROVED).
     */
    private const OWNER_TEXTS = [
        'Accepted formats', 'Available space', 'Available uploads', 'Back to the video', 'Bookmarks:',
        'By submitting your videos to :app_name, you acknowledge that you agree to our <a href=":terms_url" class="text-decoration-none">:terms_text</a>',
        'Cancel Upload', 'Close Mini Player', 'Contact us today', 'Continue where you left off?',
        'Could not capture or generate thumbnails for this video.', 'Direct Upload18 uploads are restricted. Please log in to upload.',
        'Direct upload unavailable, uploading through server...', 'Dismiss', 'Do you want to display your ad before each video ?',
        'Dont quit or reload page please', 'Drag and drop video file to upload', 'Expand to Full Player', 'Finalizing metadata...',
        'Finalizing stream files...', 'Generating thumbnails on server...', 'Keep this page open and try again in a minute.', 'Keep waiting',
        'Max file size', 'No embed code found for this video.', 'One or more thumbnails are invalid or exceed the configured max thumbnail size.',
        'Please be sure not to violate others copyright or privacy rights', 'Preparing upload...', 'Processing video on Upload18...',
        'Processing video on Upload18... ({seconds}s)', 'Reload the video', 'Retry finishing', 'Select file',
        'Server busy, waiting... (attempt {n})', 'Server thumbnail fallback is only available for Upload18 videos.',
        'Server-side video upload is disabled. Please upload directly to Upload18.', 'Skip', 'Skip ad', 'Skip in',
        'Some upload chunks are missing. Please retry the missing chunks.',
        'Sorry ! Your available video storage is not enough for this file. Upgrade to Premium plan to increase your storage space.', 'Sound mode',
        'Take a snapshot', 'The filename contains invalid characters.', 'The thumbnail exceeds the configured max thumbnail size.',
        'The time you set is up, so the video was paused.', 'The uploaded chunk sizes do not match the declared file size.',
        'The video Upload18 returned does not match your file, so it was not added. Please upload it again.',
        'The video duration exceeds the allowed limit.', 'The video format is not allowed.',
        'This Upload18 video is already claimed by another upload session.', 'This upload is already being finalized. Please retry shortly.',
        'Upgrade to premium', 'Upload18 API key not configured.',
        'Upload18 Stream Active. Internal playback speed, volume & quality are managed directly inside the video player frame.',
        'Upload18 could not start the upload. Try again in a minute.', 'Upload18 direct upload failed: {message}',
        'Upload18 has not finished processing this video yet. Please wait and retry.',
        'Upload18 is still processing it. It will appear in your studio when it is ready, so you can close this page.',
        'Upload18 is still processing your video.', 'Upload18 returned a different video ID.',
        'Upload18 returned an older video instead of your file, so it was not added. Please upload it again.',
        'Upload18 status is temporarily unavailable. Please retry finalizing in a moment.',
        'Upload18 status must be checked from this account before finalizing the upload.', 'Uploading Upload18 chunk {current} of {total}...',
        'Uploading chunk {current} of {total}...', 'Uploading directly to Upload18...', 'Uploads are currently disabled.',
        'Video processing is complete.', 'Video processing status is not available.', 'Video source is not available', 'Visit advertiser',
        'Waiting for video to be ready...', 'Watch on', 'You can upload a thumbnail manually below.',
        'You will receive a notification once the processing is complete.', 'Your Ad Could Be Here',
        'Your file name does not exceed :max characters', 'Your file name does not exceed {max} characters', 'Your upload limits',
        // third pass (TL-R5): the chunk upload's request (FileRequest.php) prints these only
        'Your file name does not exceed 100 characters', 'Your file is too large (:fileSize) The uploading file should not exceed :maxSize',
        'The chunk number cannot exceed the total chunk count.',
        'Your video is processing', 'Your video is safe.', 'Your video is uploading ...', 'Your video will be private until you publish them',
        'for unlimited uploads', 'thumbnails generated!',
    ];

    /**
     * OQ-10, approved by the owner on 30 Oct 2026 ("đồng ý theo lời khuyên của bạn về sửa bản dịch của 45 câu chữ trong cửa
     * sổ tải video và trình phát ở 12 ngôn ngữ"): the owner's texts whose translations in these languages take the
     * site's words (one word for an upload and for a video, Brazilian Portuguese, Premium by its name). Translations
     * only: English, German, every file and all code unchanged. The 45th text, 'Upload18 Stream Active. ...', is left
     * out: lane WA's OQ-4 replaces it with the player's new words.
     */
    private const OWNER_APPROVED = [
        'Available uploads' => ['fr', 'pt'],
        'Cancel Upload' => ['ar', 'es', 'fr'],
        'Could not capture or generate thumbnails for this video.' => ['ja'],
        'Direct Upload18 uploads are restricted. Please log in to upload.' => ['ar', 'fr'],
        'Direct upload unavailable, uploading through server...' => ['ar', 'es', 'fr', 'pt', 'th'],
        'Do you want to display your ad before each video ?' => ['es'],
        'Drag and drop video file to upload' => ['ar', 'fr', 'ko'],
        'Max file size' => ['pt'],
        'No embed code found for this video.' => ['es', 'ja'],
        'Preparing upload...' => ['ar', 'es', 'fr'],
        'Processing video on Upload18...' => ['es', 'ja'],
        'Processing video on Upload18... ({seconds}s)' => ['es', 'ja'],
        'Server thumbnail fallback is only available for Upload18 videos.' => ['ja', 'ko'],
        'Server-side video upload is disabled. Please upload directly to Upload18.' => ['ar', 'fr', 'ja', 'ko', 'th'],
        'Some upload chunks are missing. Please retry the missing chunks.' => ['ar', 'fr'],
        'Sorry ! Your available video storage is not enough for this file. Upgrade to Premium plan to increase your storage space.' => ['ja', 'ko'],
        'The uploaded chunk sizes do not match the declared file size.' => ['ar', 'es', 'fr', 'pt'],
        'The video Upload18 returned does not match your file, so it was not added. Please upload it again.' => ['fr', 'pt'],
        'The video duration exceeds the allowed limit.' => ['es', 'ja'],
        'The video format is not allowed.' => ['es', 'ja', 'ko'],
        'This Upload18 video is already claimed by another upload session.' => ['ar', 'es', 'id'],
        'This upload is already being finalized. Please retry shortly.' => ['ar', 'fr'],
        'Upgrade to premium' => ['ar', 'es', 'fr', 'id', 'pt', 'ru', 'th', 'tr', 'vi', 'zh'],
        'Upload18 could not start the upload. Try again in a minute.' => ['fr', 'pt'],
        'Upload18 direct upload failed: {message}' => ['es', 'fr'],
        'Upload18 has not finished processing this video yet. Please wait and retry.' => ['ja'],
        'Upload18 returned a different video ID.' => ['ja'],
        'Upload18 returned an older video instead of your file, so it was not added. Please upload it again.' => ['fr', 'pt'],
        'Upload18 status must be checked from this account before finalizing the upload.' => ['ar', 'es', 'fr', 'id'],
        'Uploading Upload18 chunk {current} of {total}...' => ['ar', 'fr', 'pt'],
        'Uploading chunk {current} of {total}...' => ['ar', 'fr', 'pt'],
        'Uploading directly to Upload18...' => ['ar', 'fr'],
        'Uploads are currently disabled.' => ['es', 'fr'],
        'Video processing is complete.' => ['es', 'ja'],
        'Video processing status is not available.' => ['ja'],
        'Video source is not available' => ['es', 'ja', 'ko'],
        'Waiting for video to be ready...' => ['es', 'ja'],
        'You can upload a thumbnail manually below.' => ['ar', 'fr', 'pt'],
        'Your file is too large (:fileSize) The uploading file should not exceed :maxSize' => ['fr', 'pt'],
        'Your file name does not exceed 100 characters' => ['pt'],
        'Your upload limits' => ['fr', 'pt'],
        'Your video is uploading ...' => ['ar', 'es', 'fr', 'ja', 'pt'],
        'Your video will be private until you publish them' => ['es'],
        'for unlimited uploads' => ['ar', 'es', 'fr', 'th'],
    ];

    /**
     * European Portuguese words and forms (fourth pass, TL-R4): the site's Portuguese is Brazilian (the words in
     * brackets). Matched in every Portuguese text the sources use, the owner's texts aside, and in lang/pt/*.php.
     */
    private const PT_EUROPEAN = [
        'gerir (gerenciar)' => '/(?<!\p{L})(gerir|gere|gerem|gerido|gerida|geridos|geridas|gerindo)(?!\p{L})(?! (cores|esquemas))/iu',
        'recebe este without você' => '/(?<![Vv]ocê )(?<!\p{L})recebe (este|esta)(?!\p{L})/u',
        'contacte / contacto (entre em contato / contato)' => '/(?<!\p{L})contact(e|em|ar|a|ou|ando|o|os)(-nos)?(?!\p{L})/iu',
        'transferir for a download (baixar)' => '/(?<!\p{L})(transferir|transfira|transferido|transferidos|transferências)(?!\p{L})|botão de transferência/iu',
        'mantém-se (permanece)' => '/(?<!\p{L})mant(é|ê)m-se/iu',
        'até ao (até o)' => '/(?<!\p{L})até aos?(?!\p{L})/iu',
        'ao fim do (no fim do)' => '/(?<!\p{L})ao fim d/iu',
        'o período que pagou (o período pago)' => '/período que (pagou|pagaste)/iu',
        'mudou de ideias (ideia)' => '/mudou de ideias/iu',
        'definições (configurações)' => '/(?<!\p{L})defini(ç|c)ões(?!\p{L})/iu',
        'registo (registro)' => '/(?<!\p{L})regist(o|os|ar|ado|ada|ados|adas)(?!\p{L})/iu',
        'facto (fato)' => '/(?<!\p{L})factos?(?!\p{L})/iu',
        'connosco (conosco)' => '/(?<!\p{L})connosco(?!\p{L})/iu',
        'de momento (no momento)' => '/(?<!\p{L})de momento(?!\p{L})/iu',
        'estar a + infinitive (gerúndio)' => '/(?<!\p{L})(?i:está|estão|estou|estamos|esteja|estejam|estava|estavam|continua|continuam|continuar|fica|ficam) a \p{L}+r(?!\p{L})|^A \p{L}+ar(?!\p{L})/u',
        'gosto for a like (curtida)' => '/«(Não )?[Gg]osto»|(?<!\p{L})(gostos|desgostos|com gosto|sem gosto)(?!\p{L})|(?<!\p{L})\d+ gosto(?!\p{L})|(?<!\p{L})gosto ou não gosto/u',
        'ligação for a link or a connection (link / conexão)' => '/(?<!\p{L})(hiper)?ligaç(ão|ões)(?!\p{L})/iu',
        'terminar / iniciar sessão (encerrar sessão / entrar)' => '/(?<!\p{L})(terminar|termine|terminou|terminaram|terminam|termina|iniciar|inicie|inicia|iniciou|iniciaram) (a )?sessão|sessão (terminada|iniciada)/iu',
        'o seu / a sua at a sentence start (seu / sua)' => '/(^|[.!?:] )(O seu|A sua|Os seus|As suas) |^(o seu|a sua|os seus|as suas) /u',
        'noutro / nalgum (em outro / em algum)' => '/(?<!\p{L})(noutro|noutra|noutros|noutras|nalgum|nalguma|nalguns|nalgumas)(?!\p{L})/iu',
        'percentagem (porcentagem)' => '/(?<!\p{L})percentage(m|ns)(?!\p{L})/iu',
        'morada (endereço)' => '/(?<!\p{L})moradas?(?!\p{L})/iu',
        '-ámos (-amos)' => '/(?<!\p{L})\p{L}+ámos(?!\p{L})/u',
        'demasiado (demais)' => '/(?<!\p{L})demasiad(o|a|os|as)(?!\p{L})/iu',
        'dentro de + a time (em)' => '/(?<!\p{L})dentro de (:count|:seconds|alguns|algumas|\d|uns minutos|umas horas|instantes|momentos|poucos|um minuto|uma hora|um momento|um instante)/iu',
        'aplicação for an app (aplicativo)' => '/(?<!\p{L})aplicaç(ão|ões) (de e-mail|móve)|(?<!\p{L})(sua|a sua) aplicação(?!\p{L})/iu',
        'reposição for a reset (redefinição)' => '/(?<!\p{L})reposição(?!\p{L})/iu',
        'Ver mais tarde (Assistir mais tarde)' => '/(?<!\p{L})ver mais tarde/iu',
        'guillemets (the site quotes with "...")' => '/[«»]/u',
        'válido durante (vale por)' => '/(?<!\p{L})válid[oa]s? durante(?!\p{L})/iu',
        'vai a caminho (está a caminho)' => '/(?<!\p{L})vai a caminho/iu',
        'aceder (acessar)' => '/(?<!\p{L})aced(er|a|e|em|ido|ida)(?!\p{L})/iu',
        'separador for a tab (aba)' => '/(?<!\p{L})separador(es)?(?!\p{L})/iu',
        'introduza (digite)' => '/(?<!\p{L})introduz(a|ir|ido|ida|am)(?!\p{L})/iu',
        'facultativo (opcional)' => '/(?<!\p{L})facultativ(o|a|os|as)(?!\p{L})/iu',
        'sistema operativo (operacional)' => '/sistema operativo/iu',
        'píxeis (pixels)' => '/(?<!\p{L})píxe(is|l)(?!\p{L})/iu',
        'recolher / base de dados (coletar / banco de dados)' => '/(?<!\p{L})recolh(er|emos|e|em|ido|idos|idas|ida)(?!\p{L})|(?<!\p{L})bases? de dados(?!\p{L})/iu',
        'a :date (em :date)' => '/(?<!\p{L})a :date/u',
        'terminado em for a card (com final)' => '/terminado em :(last4|digits)/u',
        'período experimental (teste gratuito)' => '/período experimental/iu',
        'palavra-passe, ficheiro, utilizador, ecrã, telemóvel, equipa, secção, poupar' => '/telemóve|(?<!\p{L})ecrã|ficheiro|utilizador|palavras?-passe|(?<!\p{L})equipas?(?!\p{L})|(?<!\p{L})secç|(?<!\p{L})poup|contacto/iu',
        'partilhar, eliminar, subscrever, registar, descarregar, sessão iniciada, prima' => '/(?<!\p{L})partilh|(?<!\p{L})elimin|(?<!\p{L})subscr(?!ibers_count)|(?<!\p{L})registar|descarreg|sessão iniciada|(?<!\p{L})prima(?!\p{L})/iu',
    ];

    /** Japanese writes these one way, as most of its texts do (fourth pass): minority spelling => the site's word. */
    private const JA_ONE_WAY = [
        '/携帯電話(?!番号)/u' => 'スマホ', '/カテゴリー/u' => 'カテゴリ', '/サーバ(?!ー)/u' => 'サーバー', '/フォルダ(?!ー)/u' => 'フォルダー',
        '/ブラウザー/u' => 'ブラウザ', '/フィルタ(?![ーリ])/u' => 'フィルター', '/エディタ(?!ー)/u' => 'エディター', '/メモリー/u' => 'メモリ',
        '/パラメータ(?!ー)/u' => 'パラメーター', '/コンテナ(?!ー)/u' => 'コンテナー', '/ドライバ(?!ー)/u' => 'ドライバー',
    ];

    /** @var array<string, array{old_label: ?string, origin: string, source: string}>|null */
    private static ?array $requiredTexts = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function file(string $locale): array
    {
        return (array) json_decode((string) file_get_contents(lang_path($locale.'.json')), true);
    }

    /** @return array<string, array{old_label: ?string, origin: string, source: string}> the texts the sources use */
    private function required(): array
    {
        require_once base_path('scripts/i18n/I18n.php');

        return self::$requiredTexts ??= \Avd\I18n\Requirements::collect(base_path());
    }

    /** @return array<string, string> the Preact texts the code uses, in a locale (resources/js/lang/<locale>.ts) */
    private function js(string $locale): array
    {
        require_once base_path('scripts/i18n/I18n.php');
        $path = \Avd\I18n\JsLangFile::path(base_path(), $locale);
        if (! is_file($path)) {
            return [];
        }
        [$data] = \Avd\I18n\JsLangFile::read($path);

        return array_intersect_key($data, \Avd\I18n\Requirements::collectJs(base_path()));
    }

    public function test_every_language_says_a_playlist_with_one_word(): void
    {
        $this->assertSame('Playlist', $this->file('fr')['Playlist'] ?? null, 'R3-012: "Champs de liste de lecture" = playlist fields');
        $this->assertSame('Playlist', $this->file('de')['Playlist'] ?? null);
        $this->assertSame('Chargement de vos playlists…', $this->js('fr')['Loading your playlists ...'] ?? null, 'it read "Paramètres régionaux"');

        $required = array_keys($this->required());
        $wrong = [];
        foreach (self::NOT_PLAYLIST as $locale => $pattern) {
            $file = $this->file($locale);
            $texts = [];
            foreach ($required as $key) {
                if (preg_match('/playlist/i', $key) && is_string($file[$key] ?? null)) {
                    $texts[$key] = $file[$key];
                }
            }
            foreach ($this->js($locale) as $key => $value) {
                if (preg_match('/playlist/i', $key)) {
                    $texts['js: '.$key] = $value;
                }
            }
            $this->assertGreaterThan(40, count($texts), $locale);
            foreach ($texts as $key => $value) {
                if (preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                }
            }
        }

        $this->assertSame([], $wrong, 'one word for a playlist in each language');
    }

    public function test_the_studio_playlists_and_the_editor_say_one_word(): void
    {
        $member = User::factory()->create(['username' => 'nightdrive_tv']);
        $playlist = Playlist::factory()->for($member)->create(['status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);

        $de = (string) $this->actingAs($member)->withSession(['app_locale' => 'de'])->get(route('user.playlists.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Neue Playlist', $de);
        $this->assertDoesNotMatchRegularExpression('/Wiedergabeliste/u', strip_tags($de));

        $fr = (string) $this->actingAs($member)->withSession(['app_locale' => 'fr'])->get(route('user.playlists.edit', $playlist))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/Champs de liste de lecture|liste de lecture/iu', strip_tags($fr));
    }

    public function test_the_demo_windows_button_has_its_own_words_in_every_language(): void
    {
        foreach (array_merge(['en'], self::LOCALES) as $locale) {
            app()->setLocale($locale);
            $button = ViewOnlyDemoWindow::payload(ViewOnlyDemo::REFUSED_CHANGE)['button'];
            $this->assertSame($locale === 'en' ? 'Got it' : ($this->file($locale)['Got it'] ?? null), $button, $locale);
            $this->assertNotSame('OK', $button, $locale);
            $this->assertSame($button, ViewOnlyDemoWindow::payload(ViewOnlyDemo::REFUSED_CLOSED)['button'], $locale);
        }
        app()->setLocale('ar');
        $this->assertSame('حسنًا', ViewOnlyDemoWindow::payload(ViewOnlyDemo::REFUSED_CHANGE)['button']);

        // the admin's status label keeps its own key
        $this->assertStringContainsString("__('OK')", (string) file_get_contents(resource_path('views/admin/preflight/index.blade.php')));
    }

    public function test_the_mail_text_part_puts_the_colon_the_languages_way(): void
    {
        $member = User::factory()->create(['username' => 'nightdrive_tv']);
        $data = ['notifiable' => $member, 'url' => 'https://site.example/pay?signature=abc', 'amount' => '9,99 €'];

        app()->setLocale('fr');
        $fr = view('mails.text.premium.unpaid', $data)->render();
        $this->assertStringContainsString(__('Update payment details')."\u{00A0}:\n".$data['url'], $fr);
        $this->assertStringContainsString(__('Manage e-mails')."\u{00A0}:\n", $fr);
        $this->assertDoesNotMatchRegularExpression('/\p{L}:\n/u', $fr, 'no colon glued to a French label');

        app()->setLocale('zh');
        $zh = view('mails.text.premium.unpaid', $data)->render();
        $this->assertStringContainsString(__('Update payment details')."：\n".$data['url'], $zh);

        app()->setLocale('en');
        $en = view('mails.text.premium.unpaid', $data)->render();
        $this->assertStringContainsString("Update payment details:\n".$data['url'], $en);
        $this->assertStringContainsString("Manage e-mails:\n", $en);

        $source = (string) file_get_contents(resource_path('views/mails/layouts/text.blade.php'));
        $this->assertStringNotContainsString(".':'", $source, 'the layout leaves the colon to the translation');
    }

    public function test_arabic_counts_use_its_six_plural_forms_and_russian_its_three(): void
    {
        $wrong = [];
        $ar = $this->file('ar');
        foreach ($this->required() as $key => $meta) {
            if (! \Avd\I18n\Plurals::counted((string) $meta['source'])) {
                continue;
            }
            $line = (string) ($ar[$key] ?? '');
            $forms = explode('|', $line);
            // six plain forms (zero|one|two|few|many|other); a "{0}" on the first form is the zero form itself
            if (count($forms) !== 6 || preg_match('/^\s*[\{\[]/m', implode("\n", array_slice($forms, 1))) || preg_match('/^\s*(?!\{0\})[\{\[]/', $forms[0])) {
                $wrong[] = "{$key}: {$line}";
            }
        }
        $this->assertSame([], $wrong, 'Arabic count lines in six plain forms');

        app()->setLocale('ar');
        $videos = fn (int $n): string => trans_choice('videos', $n, ['count' => $n]);
        $this->assertSame('فيديو واحد', $videos(1));
        $this->assertSame('فيديوان', $videos(2));
        $this->assertSame('5 فيديوهات', $videos(5));
        $this->assertSame('38 فيديو', $videos(38), 'judge R3-065');
        $this->assertSame('100 فيديو', $videos(100));
        $this->assertSame('103 فيديوهات', $videos(103));
        $this->assertSame('111 فيديو', $videos(111));
        $this->assertSame('15 مشتركًا', trans_choice('subscribers', 15, ['count' => 15]));
        $this->assertSame('105 مشتركين', trans_choice('subscribers', 105, ['count' => 105]));

        app()->setLocale('ru');
        $views = fn (int $n): string => trans_choice('views', $n, ['count' => $n]);
        $this->assertSame('21 просмотр', $views(21));
        $this->assertSame('22 просмотра', $views(22));
        $this->assertSame('25 просмотров', $views(25));
        $this->assertSame('0 просмотров', $views(0));
        $this->assertSame('31 плейлист', trans_choice('playlists', 31, ['count' => 31]));
    }

    public function test_arabic_says_administrator_with_one_word(): void
    {
        $ar = $this->file('ar');
        $this->assertSame('مشرف', $ar['Administrator'] ?? null);
        $this->assertSame('تعيين كمشرف', $ar['Make administrator'] ?? null);
        $this->assertSame('تعيين كمشرفين', $ar['Make administrators'] ?? null);
        $this->assertSame('لوحة الإدارة', $ar['Admin'] ?? null);

        $wrong = [];
        foreach (array_keys($this->required()) as $key) {
            // an administrator or the admin itself; "manager" (the site manager, a file manager) is another word
            if (preg_match('/\badmin(istrator)?s?\b/i', $key) && is_string($ar[$key] ?? null) && preg_match('/مسؤول|مدير/u', $ar[$key], $m)) {
                $wrong[] = "{$key}: \"{$m[0]}\" in {$ar[$key]}";
            }
        }
        $this->assertSame([], $wrong, 'مشرف for an administrator, لوحة الإدارة for the admin');

        // the old admin's role badges say "Administrator", not the place "Admin" (Administration, لوحة الإدارة, 管理后台)
        foreach (['admin/index', 'admin/users/edit', 'admin/users/show', 'admin/verification/index'] as $view) {
            $this->assertStringNotContainsString("badge bg-danger\">{{__('Admin')}}", (string) file_get_contents(resource_path("views/{$view}.blade.php")), $view);
        }
    }

    public function test_the_video_player_is_never_a_person_who_plays(): void
    {
        $required = array_keys($this->required());
        $wrong = [];
        foreach (self::PERSON as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($required as $key) {
                if (preg_match('/player/i', $key) && is_string($file[$key] ?? null) && preg_match($pattern, $file[$key], $m)) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$file[$key]}";
                }
            }
            foreach ($this->js($locale) as $key => $value) {
                if (preg_match('/player/i', $key) && preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} js: {$key}: \"{$m[0]}\"";
                }
            }
        }

        $this->assertSame([], $wrong, 'the player is the media player');
    }

    public function test_one_word_for_the_player_and_for_the_screens_cards(): void
    {
        // the dock's word for the player; the Upload18 notices are reworded with the watch tools (R3X-03)
        $otherPlayer = ['ru' => '/проигрыват/iu', 'pt' => '/reprodutor/iu', 'th' => '/โปรแกรมเล่น/u', 'ja' => '/プレイヤー/u', 'id' => '/(?<!\p{L})player(?!\p{L})/iu'];
        // a map or a playing card for the screen's card; payment cards and the ambiguous "Card" keep their word
        $otherCard = ['ru' => '/(?<!\p{L})(видео)?карт(?!оч|ин|очк)\p{L}*/iu', 'es' => '/(?<!\p{L})cartas?(?!\p{L})/iu', 'pt' => '/(?<!\p{L})cartas?(?!\p{L})/iu'];
        $payment = '/credit|payment|pay |pay$|Pay card|Start card|PayPal|Pay with card|Start with card|your card|Update card|Update my card|new card|card payments|by card|Stripe|card number|expir|^Card$/i';

        $wrong = [];
        foreach (array_keys($this->required()) as $key) {
            foreach ($otherPlayer as $locale => $pattern) {
                if (preg_match('/player/i', $key) && ! preg_match('/^Upload18 |Upload18 Stream Active/', $key) && ! in_array($key, self::OWNER_TEXTS, true)) {
                    $value = (string) ($this->file($locale)[$key] ?? '');
                    if (preg_match($pattern, $value, $m)) {
                        $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                    }
                }
            }
            foreach ($otherCard as $locale => $pattern) {
                if (preg_match('/\bcards?\b/i', $key) && ! preg_match($payment, $key)) {
                    $value = (string) ($this->file($locale)[$key] ?? '');
                    if (preg_match($pattern, $value, $m)) {
                        $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                    }
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    public function test_the_menu_titles_are_collected_and_translated(): void
    {
        $required = $this->required();
        $menu = (string) file_get_contents(config_path('menu.php'));
        preg_match_all("/'(?:title|label|group)'\\s*=>\\s*'([^']+)'/", $menu, $m);
        $this->assertGreaterThan(60, count($m[1]));
        foreach (array_unique($m[1]) as $title) {
            $this->assertArrayHasKey($title, $required, "config/menu.php: {$title}");
        }
        $this->assertSame('config/menu.php', $required['Your videos']['origin']);

        foreach (self::LOCALES as $locale) {
            $value = (string) ($this->file($locale)['Artisan CLI'] ?? '');
            $this->assertStringContainsString('Artisan', $value, "{$locale}: the tool's name (it read craftsman / artisanal)");
        }
        foreach (['fil', 'tl'] as $locale) {
            foreach (['Your videos', 'Watch later', 'Users & Access', 'Communication'] as $title) {
                $this->assertNotSame($title, $this->file($locale)[$title] ?? $title, "{$locale}: {$title}");
            }
        }
        // the side menu names the page it opens with the page's own words
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $this->assertSame($file['Trending'] ?? null, $file['Trend'] ?? null, $locale);
        }
    }

    public function test_the_reports_page_says_what_it_does(): void
    {
        $wrong = [];
        $theirReport = [
            'de' => 'Meldung', 'es' => 'report', 'fr' => 'signalement', 'ja' => '報告', 'ko' => '신고', 'pt' => 'denúncia',
            'ru' => 'жалоб', 'tr' => 'şikayet', 'zh' => '举报', 'ar' => 'بلاغ',
        ];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $block = (string) ($file['Block it'] ?? '');
            if ($block === '' || $block === 'Block it' || preg_match('/^\p{Ll}/u', $block)
                || preg_match('/ください|มัน|(?<!\p{L})nó(?!\p{L})|(?<!\p{L})itu(?!\p{L})|(?<!\p{L})ia(?!\p{L})/u', $block)) {
                $wrong[] = "{$locale} Block it: {$block}";
            }
            $waiting = (string) ($file['Waiting'] ?? '');
            if ($waiting === '' || preg_match('/^(Espera|Esperando|Ожидающий|Warten)$/u', $waiting)) {
                $wrong[] = "{$locale} Waiting: {$waiting}";
            }
            if (isset($theirReport[$locale])) {
                foreach (['Find reports', 'Search the reports', 'Actions for the report about', 'No reports here'] as $key) {
                    if (mb_stripos((string) ($file[$key] ?? ''), $theirReport[$locale]) === false) {
                        $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-');
                    }
                }
            }
            if (preg_match('/(?<!\p{L})(member visitors|visiteurs? des membres|membres visités|Mitgliederbesucher|زوار الأعضاء|訪問者が報告されました|회원 방문자|посетители участников|üye ziyaretçileri|会员访客)/iu', (string) ($file['Videos, comments and members visitors reported. Review them first.'] ?? ''), $m)) {
                $wrong[] = "{$locale} page description: {$m[0]}";
            }
        }
        $this->assertSame([], $wrong, 'the Reports page in every language');

        $admin = User::factory()->admin()->create();
        foreach (['es' => ['Pendiente', 'Bloquearlo'], 'ru' => ['На рассмотрении']] as $locale => $words) {
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get(route('admin.reports.index'))->assertOk()->getContent();
            foreach ($words as $word) {
                $this->assertStringContainsString(e($word), $html, $locale);
            }
        }
    }

    public function test_the_effects_page_and_the_clear_buttons_say_what_they_mean(): void
    {
        // [key => [locale => what it must not say]]: an English word read in its other sense
        $not = [
            'Strong blur and colour behind cards. Heavy on phones.' => ['ru' => '/разбираюсь/u', 'zh' => '/沉迷/u', 'ja' => '/電話が重い/u', 'ko' => '/휴대폰이 무겁/u', 'de' => '/Schwer auf/u'],
            'Heavy effects' => ['de' => '/Auswirkungen/u', 'zh' => '/严重/u', 'th' => '/ผลกระทบ/u'],
            'Moving, heavy.' => ['fr' => '/Émouvant/u', 'de' => '/Bewegend/u', 'zh' => '/沉重/u'],
            'Light effects' => ['de' => '/Licht/u', 'es' => '/luz/iu', 'fr' => '/lumière/iu', 'pt' => '/luz/iu', 'ru' => '/Светов/u', 'zh' => '/灯光/u', 'ar' => '/ضوئية/u', 'ja' => '/光/u', 'ko' => '/조명/u', 'hi' => '/प्रकाश/u', 'id' => '/cahaya/iu', 'ms' => '/cahaya/iu', 'th' => '/แสง/u', 'tr' => '/Işık/u'],
            'Always light' => ['ru' => '/свет(?!л)/iu', 'hi' => '/प्रकाश/u', 'th' => '/สว่าง/u', 'zh' => '/明亮/u'],
            'Still backgrounds cost nothing. Moving ones are paused on weak phones automatically.' => ['de' => '/Dennoch/u', 'es' => '/Aún así|móviles/u', 'fr' => '/Pourtant|téléphones en mouvement/u', 'ru' => '/Тем не менее/u', 'ja' => '/それでも|電波/u', 'zh' => '/仍然|信号/u', 'pt' => '/Mesmo assim/u', 'tr' => '/Yine de/u', 'ar' => '/لا تزال|الهواتف المتحركة/u'],
            'Still, no cost.' => ['de' => '/Trotzdem/u', 'ja' => '/それでも/u', 'zh' => '/不过/u'],
            'Glow around pointed cards' => ['de' => '/spitze/u', 'es' => '/puntiagud/u', 'fr' => '/pointues/u', 'ru' => '/остроконечн/u', 'zh' => '/尖头/u', 'ja' => '/尖った/u', 'ko' => '/뾰족/u'],
            'Old phones may stutter.' => ['de' => '/stottern/u', 'es' => '/tartamudear/u', 'fr' => '/bégayer/u', 'ru' => '/заикаться/u', 'pt' => '/gaguejar/u', 'tr' => '/kekemelik/u', 'th' => '/ติดอ่าง/u'],
            'Still, simple look on every device. Fastest.' => ['ru' => '/Тем не менее/u', 'ja' => '/それでも/u', 'de' => '/Trotzdem/u', 'fr' => '/Pourtant/u', 'zh' => '/尽管如此/u', 'es' => '/Aún así/u', 'pt' => '/Ainda assim/u', 'tr' => '/Yine de/u', 'ar' => '/ومع ذلك/u', 'ko' => '/그래도/u', 'id' => '/Tetap saja/u', 'hi' => '/फिर भी/u', 'fil' => '/Gayunpaman/u', 'ms' => '/Namun/u'],
            // the effects' names read as commands
            'Glow around the search box' => ['de' => '/Leuchten Sie/u', 'fr' => '/Glow/u', 'es' => '/^Brilla /u', 'hi' => '/चमकें/u', 'tr' => '/parlayın/u'],
            'Shine across headings' => ['de' => '/Leuchten Sie/u', 'fr' => '/Brillez/u', 'pt' => '/Brilhe/u', 'hi' => '/चमकें/u', 'tr' => '/parlayın/u', 'ru' => '/Светить/u'],
            'Shine on main buttons' => ['de' => '/Leuchten Sie/u', 'fr' => '/Brillez/u', 'pt' => '/Brilhe/u', 'tr' => '/parlatın/u', 'zh' => '/照亮/u'],
            'Loading shimmer' => ['fr' => '/Chargement des/u', 'ru' => '/Загрузка мерцания/u', 'zh' => '/正在加载/u', 'es' => '/^Cargando/u'],
            'A light sweep across Register and Watch buttons.' => ['de' => '/Beobachten/u', 'fr' => '/Enregistrer/u', 'ja' => '/監視/u', 'ar' => '/المراقبة/u'],
            'Clear Guests' => ['ms' => '/Jelas/u', 'hi' => '/साफ़ मेहमान/u', 'zh' => '/客人/u'],
            'Clear Direct IDs' => ['ar' => '/واضح/u', 'zh' => '/透明/u', 'de' => '/Direkt löschen/u', 'es' => '/Directo IDs/u'],
            'Clear 1 ended block|Clear :count ended blocks' => ['de' => '/Block(?!ierung)/u', 'es' => '/bloque(?!o)/u', 'fr' => '/bloc(?!age)/u', 'pt' => '/bloco/u', 'ru' => '/блок(?!ир)/u', 'zh' => '/方块|结束块/u'],
        ];
        $wrong = [];
        foreach ($not as $key => $locales) {
            foreach ($locales as $locale => $pattern) {
                $value = (string) ($this->file($locale)[$key] ?? '');
                if ($value === '' || preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} {$key}: ".($m[0] ?? 'missing')." in {$value}";
                }
            }
        }
        $this->assertSame([], $wrong);
        $this->assertStringContainsString('нагружает', (string) ($this->file('ru')['Strong blur and colour behind cards. Heavy on phones.'] ?? ''), 'ru: heavy on phones');
    }

    public function test_the_site_header_and_footer_have_one_word_each(): void
    {
        // the site's header and footer; an HTTP header, the homepage's intro heading and the player's own header are
        // other things, and the channel's "Header band" says "top band" like the help under it
        $other = '/HTTP|Security Headers|security headers|Headers & Firewall|X-XSS|Retry-After|Strict-Transport|Extra header for very old browsers|^Intro Header$|player header|^Header band$/i';
        $keys = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => (bool) preg_match('/header|footer/i', $key) && ! preg_match($other, $key)));
        $this->assertGreaterThan(70, count($keys));

        $wrong = [];
        foreach (self::HEADER_FOOTER as $locale => [$header, $footer]) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = (string) ($file[$key] ?? '');
                if (preg_match('/header/i', $key) && ! preg_match($header, $value)) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
                if (preg_match('/footer/i', $key) && ! preg_match($footer, $value)) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
            }
        }
        $this->assertSame([], $wrong, 'one word for the header and one for the footer in each language');

        $this->assertSame('바닥글', $this->file('ko')['Footer'] ?? null, 'ko: 보행인 = a pedestrian');
        // the old admin's header switches and the looks: a CPU register, glue, a wristwatch
        $not = [
            'Header Register' => '/レジスタ|레지스터|(?<!\p{L})регистр(?!\p{L})|寄存器|Registre|Header-Register|سجل الرأس|tiêu đề|tajuk|başlık/iu',
            'Sticky Header' => '/klebrig|adhesiv|चिपचिपा|lengket|melekit|เหนียว|yapışkan|dính|粘性|malagkit/iu',
            'Daylight liquid glass over a pastel mesh, island header with the navigation, split spotlight, classic watch page.' => '/Uhrenseite|montre|時計|relógio|นาฬิกา|手表/u',
            'Header band' => '/Kopfband/u',
            'Footer' => '/보행인|catatan kaki/iu',
        ];
        $wrong = [];
        foreach ($not as $key => $pattern) {
            foreach (self::LOCALES as $locale) {
                if (preg_match($pattern, (string) ($this->file($locale)[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: {$m[0]}";
                }
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_the_dark_light_switches_say_dark_and_light(): void
    {
        // the switches of Appearance > Header, menus & footer said darkness / a lamp / brightness
        $keys = [
            'Dark/light switch in the side menu', 'Shows dark/light switch in the side menu when on. Turn it off to hide it for every visitor.',
            'Dark/light switch in the footer', 'Shows dark/light switch in the footer when on. Turn it off to hide it for every visitor.',
            'Language and dark/light switches in the footer.', 'Language & dark/light switches', 'Where visitors can change language or dark/light.',
        ];
        $not = [
            'es' => '/oscuridad|(?<!\p{L})luz(?!\p{L})/iu', 'fr' => '/obscurité|lumière|commutateur/iu', 'ar' => '/الظلام|الضوء/u',
            'ru' => '/темно\/светло|темного\/светлого цвета|темноты/iu', 'hi' => '/अंधेरा|अंधेरे|प्रकाश|उजाला|उजाले/u',
            'ms' => '/lampu|cahaya/iu', 'th' => '/ไฟ/u', 'fil' => '/(?<!\p{L})(ilaw|liwanag)(?!\p{L})/iu', 'tl' => '/(?<!\p{L})(ilaw|liwanag)(?!\p{L})/iu',
            'zh' => '/暗\/亮|黑暗|光明/u', 'ko' => '/어둡게|어두움|어둡거나|어두운/u', 'ja' => '/暗\/明|明暗/u', 'tr' => '/karanlık|aydınlık/iu',
        ];
        $wrong = [];
        foreach ($not as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = (string) ($file[$key] ?? '');
                if ($value === '' || preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} {$key}: ".($m[0] ?? 'missing')." in {$value}";
                }
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_the_premium_page_buttons_and_words(): void
    {
        $es = $this->file('es');
        $this->assertSame(['Empieza con tarjeta', 'Empieza con PayPal', 'Paga con tarjeta', 'Paga con PayPal'],
            [$es['Start with card'], $es['Start with PayPal'], $es['Pay with card'], $es['Pay with PayPal']], 'es: one form, capitalised');
        $tr = $this->file('tr');
        $this->assertSame(['Kartla başlayın', 'Kartla ödeyin', 'PayPal ile ödeyin'], [$tr['Start with card'], $tr['Pay with card'], $tr['Pay with PayPal']]);
        $pt = $this->file('pt');
        $this->assertSame(['Downloads', 'Mensal', 'Anual'], [$pt['Downloads'], $pt['Monthly'], $pt['Yearly']], 'pt: Descargas was Spanish');
        $this->assertSame('Hàng tháng', $this->file('vi')['Monthly'] ?? null);
        $ja = $this->file('ja');
        $this->assertSame(['カードで始める', 'PayPalで始める'], [$ja['Start with card'], $ja['Start with PayPal']]);
        $this->assertStringNotContainsString('Prim ', (string) ($tr['Premium plans are being prepared.'] ?? ''), 'tr: Prim = a bonus');
        $this->assertStringNotContainsString('пластин', (string) ($this->file('ru')['No premium records yet'] ?? ''), 'ru: a vinyl record');

        $required = array_keys($this->required());
        $wrong = [];
        foreach (self::NOT_PLAN as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($required as $key) {
                // a Premium plan; a payment service's own field names ("PayPal Plan ID", "plan ID") and the upload
                // window's refusal (the owner's text) aside
                if (! preg_match('/\bplans?\b/i', $key) || preg_match('/plan ID|Plan ID|^Sorry !/', $key) || ! is_string($file[$key] ?? null)) {
                    continue;
                }
                if (preg_match($pattern, (string) preg_replace('/:[a-z_]+/i', '', $file[$key]), $m) && ! ($locale === 'fr' && preg_match('/PayPal/', $key))) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$file[$key]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'one word for a plan in each language');
    }

    /**
     * @return array<string, string> the texts the sources use in a locale (PHP / Blade keys, and the Preact keys prefixed
     *                               "js: "), the owner's texts aside (but their translations the owner approved, OQ-10);
     *                               only the keys that match / do not match a pattern
     */
    private function texts(string $locale, ?string $keys = null, ?string $notKeys = null): array
    {
        $file = $this->file($locale);
        $out = [];
        foreach (array_keys($this->required()) as $key) {
            $key = (string) $key;
            if (is_string($file[$key] ?? null) && $this->wanted($locale, $key, $keys, $notKeys)) {
                $out[$key] = $file[$key];
            }
        }
        foreach ($this->js($locale) as $key => $value) {
            if (is_string($value) && $this->wanted($locale, (string) $key, $keys, $notKeys)) {
                $out['js: '.$key] = $value;
            }
        }

        return $out;
    }

    private function wanted(string $locale, string $key, ?string $keys, ?string $notKeys): bool
    {
        return (! in_array($key, self::OWNER_TEXTS, true) || in_array($locale, self::OWNER_APPROVED[$key] ?? [], true))
            && ($keys === null || preg_match($keys, $key) === 1)
            && ($notKeys === null || preg_match($notKeys, $key) !== 1);
    }

    /**
     * @param  array<string, string>  $patterns  locale => what its texts may not say
     * @return list<string>
     */
    private function saying(array $patterns, ?string $keys = null, ?string $notKeys = null): array
    {
        $wrong = [];
        foreach ($patterns as $locale => $pattern) {
            foreach ($this->texts($locale, $keys, $notKeys) as $key => $value) {
                if (preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                }
            }
        }

        return $wrong;
    }

    /** @return array<string, string> every entry of resources/js/lang/<locale>.ts (also the keys the code builds at run time) */
    private function jsFile(string $locale): array
    {
        require_once base_path('scripts/i18n/I18n.php');
        [$data] = \Avd\I18n\JsLangFile::read(\Avd\I18n\JsLangFile::path(base_path(), $locale));

        return $data;
    }

    public function test_video_is_said_with_one_word_in_each_language(): void
    {
        $this->assertSame([], $this->saying([
            'es' => '/vídeo/iu', 'ko' => '/비디오/u', 'ja' => '/ビデオ/u', 'ar' => '/أشرطة (ال)?فيديو/u', 'th' => '/วีดีโอ/u',
        ]), 'TL-O1: one word for a video');
        // a film (精选影片) and a clip (ролик for the shorts and for an ad video, рекламный ролик) are other words
        $this->assertSame([], $this->saying(['zh' => '/影片(?!编号)/u', 'ru' => '/ролик/iu'], '/video/i', '/shorts?\b|clips?\b|\bads?\b/i'));
        $this->assertSame('페이지당 동영상', $this->file('ko')['Videos per page'] ?? null, 'ko: 페이지당 비디오 next to 동영상');
        $this->assertSame('ページごとの動画', $this->file('ja')['Videos per page'] ?? null);
    }

    public function test_upload_is_said_with_one_word_in_each_language(): void
    {
        // a member's upload; the admin's import, a download and Upload18's own names ("Upload + Read") are other words
        $this->assertSame([], $this->saying([
            'fr' => '/télécharg|(?<!\p{L})import/iu', 'ar' => '/تحميل/u', 'pt' => '/carreg/iu',
            'es' => '/(?<!\p{L})(cargar|carga|cargas|cargad\p{L}*|cargue\p{L}*)(?!\p{L})/iu',
            'id' => '/(?<!\p{L})(di|meng)?upload(?!18)/u', 'th' => '/อัพโหลด/u',
        ], '/upload/i', '/import|download|Upload18 key|Upload \+ Read|^Upload18$/i'), 'TL-O2: one word for an upload');
        $this->assertSame(['Mettre en ligne', 'رفع', 'Fazer upload', 'Subir', 'Unggah'],
            [$this->file('fr')['Upload'], $this->file('ar')['Upload'], $this->file('pt')['Upload'], $this->file('es')['Upload'], $this->file('id')['Upload']]);
    }

    public function test_the_language_switcher_has_one_name(): void
    {
        $name = [
            'es' => 'selector de idioma', 'pt' => 'seletor de idioma', 'fil' => 'tagapagpalit ng wika', 'tl' => 'tagapagpalit ng wika',
            'th' => 'ตัวสลับภาษา', 'id' => 'pengalih bahasa', 'vi' => 'nút chuyển ngôn ngữ',
        ];
        $wrong = [];
        foreach ($name as $locale => $word) {
            $texts = $this->texts($locale, '/language switcher|^Language (&|and) dark\/light switches/i');
            $this->assertGreaterThan(4, count($texts), $locale);
            foreach ($texts as $key => $value) {
                if (mb_stripos($value, $word) === false) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-O3: one name for the language switcher');

        // fourth pass: the Settings home's Languages card (its examples line says the switcher's name; the description
        // kept the English word "switcher" in pt, fil and tl)
        $card = 'Add languages, translations and the switcher.';
        foreach (self::LOCALES as $locale) {
            $this->assertStringNotContainsStringIgnoringCase('switcher', (string) ($this->file($locale)[$card] ?? 'switcher'), $locale);
        }
        foreach (['pt' => 'seletor de idioma', 'fil' => 'tagapagpalit ng wika', 'tl' => 'tagapagpalit ng wika'] as $locale => $word) {
            $this->assertStringContainsString($word, (string) ($this->file($locale)[$card] ?? ''), $locale);
            $this->assertStringContainsStringIgnoringCase($word, (string) ($this->file($locale)['Language switcher, Right-to-left'] ?? ''), $locale);
        }
        app()->setLocale('pt');
        $hub = collect((array) config('admin_settings.hub'))->firstWhere('page', 'settings.languages');
        $this->assertIsArray($hub, 'the Languages topic of the Settings home');
        $this->assertSame('Adicione idiomas, traduções e o seletor de idioma.', __($hub['description']));
    }

    public function test_the_member_mails_say_premium_like_the_pages(): void
    {
        $word = ['ar' => 'بريميوم', 'hi' => 'प्रीमियम', 'ja' => 'プレミアム', 'ko' => '프리미엄', 'ru' => 'Премиум', 'th' => 'พรีเมียม', 'zh' => '高级版'];
        $mail = [];
        foreach ($this->required() as $key => $meta) {
            if (str_contains((string) $meta['origin'], 'Notifications/Support/MemberMail.php') && stripos((string) $key, 'premium') !== false) {
                $mail[] = (string) $key;
            }
        }
        $this->assertGreaterThan(15, count($mail));
        $wrong = [];
        foreach ($word as $locale => $premium) {
            $file = $this->file($locale);
            foreach ($mail as $key) {
                $value = (string) ($file[$key] ?? '');
                if (str_contains($value, 'Premium') || mb_strpos($value, $premium) === false) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-O4: the mails say Premium with the pages\' word');
    }

    public function test_the_premium_page_says_each_thing_with_one_word(): void
    {
        $ja = $this->file('ja');
        $this->assertSame(['毎月', '毎年', '毎月', '毎年'], [$ja['Monthly'], $ja['Yearly'], $ja['a month'], $ja['a year']], 'ja: 月額 / 年額 beside 毎月 / 毎年');
        $ko = $this->file('ko');
        $this->assertSame(['매월', '매년', '매월', '매년'], [$ko['Monthly'], $ko['Yearly'], $ko['a month'], $ko['a year']], 'ko: 월간 / 연간 beside 매월 / 매년');
        $this->assertStringContainsString('高级版', (string) ($this->file('zh')['Premium plan'] ?? ''), 'zh: 高级 alone is "advanced"');
        $this->assertSame('Nombre maximal de vidéos mises en ligne', $this->file('fr')['Max Video Uploads'] ?? null);
        foreach (['fil', 'tl'] as $locale) {
            $this->assertNotSame('Video Storage Space', $this->file($locale)['Video Storage Space'] ?? 'Video Storage Space', $locale);
        }
        $billing = $this->texts('pt', '/billing/i');
        $this->assertNotSame([], $billing);
        $this->assertSame([], array_filter($billing, fn (string $value): bool => (bool) preg_match('/fatura(ção|mento)/iu', $value)), 'pt: billing is cobrança');
    }

    public function test_the_preact_counts_use_arabic_and_russian_plural_forms(): void
    {
        $this->assertTrue(class_exists(\MessageFormatter::class), 'ext-intl');
        $say = function (string $locale, string $key, int $n): string {
            $value = (string) ($this->jsFile($locale)[$key] ?? '');
            // Latin digits, like the site's number formatting; the plural rules are the language's
            $out = \MessageFormatter::formatMessage($locale.'@numbers=latn', $value, ['count' => $n, 'formatted' => (string) $n]);
            $this->assertIsString($out, "{$locale} {$key}");

            return (string) $out;
        };
        $this->assertSame(['0 комментариев', '1 комментарий', '2 комментария', '5 комментариев', '21 комментарий'],
            array_map(fn (int $n): string => $say('ru', 'Comments', $n), [0, 1, 2, 5, 21]), 'TL-R1: "2 комментариев"');
        $this->assertSame(['22 просмотра', '25 просмотров', '3 результата', 'Показать 3 ответа', 'Показать 11 ответов', 'Скрыть 2 ответа'],
            [$say('ru', 'Views', 22), $say('ru', 'Views', 25), $say('ru', 'Results', 3), $say('ru', 'Show replies', 3), $say('ru', 'Show replies', 11), $say('ru', 'Hide replies', 2)]);
        $this->assertSame(['لا تعليقات', 'تعليق واحد', 'تعليقان', '3 تعليقات', '15 تعليقًا', '100 تعليق'],
            array_map(fn (int $n): string => $say('ar', 'Comments', $n), [0, 1, 2, 3, 15, 100]), 'TL-R1: "15 تعليقات"');
        $this->assertSame(['5 مشاهدات', '15 مشاهدة', 'إخفاء الردّين', 'عرض 4 ردود', 'نتيجتان'],
            [$say('ar', 'Views', 5), $say('ar', 'Views', 15), $say('ar', 'Hide replies', 2), $say('ar', 'Show replies', 4), $say('ar', 'Results', 2)]);
        $this->assertSame('5 बार देखा गया', $say('hi', 'Views', 5), 'hi: a broken character');
    }

    public function test_the_arabic_admin_counts_take_the_dual_and_the_right_noun(): void
    {
        app()->setLocale('ar');
        $waiting = fn (int $n): string => trans_choice('1 report waiting|:count reports waiting', $n, ['count' => $n]);
        $this->assertSame(['بلاغ واحد في الانتظار', 'بلاغان في الانتظار', '3 بلاغات في الانتظار', '15 بلاغًا في الانتظار', '100 بلاغ في الانتظار'],
            [$waiting(1), $waiting(2), $waiting(3), $waiting(15), $waiting(100)], 'TL-R2: "2 تقارير في انتظار"');
        $things = fn (int $n): string => trans_choice('1 thing needs you.|:count things need you.', $n, ['count' => $n]);
        $this->assertSame(['شيئان يحتاجان إليك.', '15 شيئًا يحتاج إليك.'], [$things(2), $things(15)], '"15 أشياء"');
        $searched = fn (int $n): string => trans_choice('Searched once|Searched :count times', $n, ['count' => $n]);
        $this->assertSame(['بُحث عنه مرتين', 'بُحث عنه 5 مرات', 'بُحث عنه 20 مرة'], [$searched(2), $searched(5), $searched(20)], '"بحثت 2 مرات"');
        $this->assertSame('فيديوان', trans_choice('1 video|:count videos', 2, ['count' => 2]));
    }

    public function test_theater_mode_has_the_watch_pages_word(): void
    {
        // [what each text says, what it may not say]; 'Deep Space Theater' is a look's name
        $word = [
            'de' => ['/Kino/u', '/Theater/u'], 'es' => ['/cine/iu', '/teatro/iu'], 'ru' => ['/кинотеатр/iu', '/(?<!кино)театр/iu'],
            'tr' => ['/sinema/iu', '/tiyatro/iu'], 'zh' => ['/影院/u', '/剧场|剧院/u'], 'vi' => ['/rạp chiếu phim/iu', '/rạp hát/iu'],
            'th' => ['/โรงภาพยนตร์/u', '/โรงละคร/u'], 'id' => ['/bioskop/iu', '/teater/iu'],
        ];
        $wrong = [];
        foreach ($word as $locale => [$must, $not]) {
            $texts = $this->texts($locale, '/theat(er|re)/i', '/^Deep Space Theater$/');
            $this->assertGreaterThan(6, count($texts), $locale);
            foreach ($texts as $key => $value) {
                if (! preg_match($must, $value) || preg_match($not, $value)) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
            }
        }
        $this->assertSame([], array_merge($wrong, $this->saying(['fil' => '/theater mode/i', 'tl' => '/theater mode/i'], '/theat(er|re)/i')), 'TL-R3');
    }

    public function test_portuguese_is_brazilian_portuguese(): void
    {
        $wrong = [];
        foreach (self::PT_EUROPEAN as $what => $pattern) {
            // the upload texts the owner keeps as they are (FormOfAddressAllLanguagesTest::OWNER_KEEPS) aside
            foreach ($this->saying(['pt' => $pattern], null, '/^Your video is almost ready \.\.\.$/') as $line) {
                $wrong[] = "[{$what}] {$line}";
            }
        }
        $this->assertSame([], $wrong, 'TL-R4: one Portuguese, Brazilian');
        // fifth pass: the maintenance page every visitor sees during an update, the password change's message
        $this->assertSame(['Estamos atualizando o site', 'Estamos atualizando o site. Tente novamente em breve.', 'A sessão foi encerrada em seus outros dispositivos.', 'Seu canal'],
            [$this->file('pt')['We are updating the site'] ?? null, $this->file('pt')['We are updating the site. Try again soon.'] ?? null,
                $this->file('pt')['Your other devices are signed out.'] ?? null, $this->file('pt')['Your channel'] ?? null]);
        $pt = $this->file('pt');
        $this->assertSame('Excluir', $pt['Delete'] ?? null);
        $this->assertSame('Excluir', $this->js('pt')['Delete'] ?? null, 'the Preact buttons said Eliminar');
        $this->assertStringStartsWith('Excluir', (string) ($pt['Delete playlist'] ?? ''), '"Excluir esta lista?" over "Eliminar lista"');
        $this->assertStringStartsWith('Excluir', (string) ($pt['Delete this playlist?'] ?? ''));
        $this->assertSame('Senha', $pt['Password'] ?? null);

        // fourth pass: the words the independent check found (gerir, Transferir, the Library's lists, a like, signing out)
        $this->assertSame(['Gerenciar', 'Gerenciar e-mails', 'Gerenciar Premium', 'Baixar o PDF de :title', 'Assistir mais tarde', 'Vídeos curtidos', 'Encerrar sessão', 'Copiar link'],
            [$pt['Manage'] ?? null, $pt['Manage e-mails'] ?? null, $pt['Manage Premium'] ?? null, $pt['Download the PDF of :title'] ?? null,
                $pt['Watch later'] ?? null, $pt['Liked videos'] ?? null, $pt['Sign out'] ?? null, $pt['Copy link'] ?? null]);

        // the PHP language files (sign-in, password reset and form errors) say it the same way
        $lines = [];
        $walk = function (array $data, string $prefix) use (&$walk, &$lines): void {
            foreach ($data as $key => $line) {
                is_array($line) ? $walk($line, $prefix.$key.'.') : $lines[$prefix.$key] = (string) $line;
            }
        };
        foreach (glob(lang_path('pt/*.php')) ?: [] as $file) {
            $walk((array) include $file, basename($file, '.php').'.');
        }
        $this->assertGreaterThan(150, count($lines));
        foreach ($lines as $key => $line) {
            foreach (self::PT_EUROPEAN as $what => $pattern) {
                if (preg_match($pattern, $line, $m)) {
                    $wrong[] = "[{$what}] lang/pt {$key}: \"{$m[0]}\" in {$line}";
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-R4: lang/pt/*.php');
        app()->setLocale('pt');
        $this->assertSame('O campo "Nome de usuário" é obrigatório.', __('validation.required', ['attribute' => __('validation.attributes.username')]));
        $this->assertSame('Este link de redefinição não funciona mais. Peça um novo.', __('passwords.token'));
    }

    public function test_the_member_mails_and_the_invoices_page_speak_one_brazilian_portuguese(): void
    {
        // every member e-mail's plain-text part, rendered in Portuguese: no European word or form, and the mails' own
        // sentences as Brazilian Portuguese says them (TL-R4: the welcome mail said "Gerir Premium", "Contacte-nos",
        // "mantém-se até ao fim do período que pagou", "Recebe este e-mail ...")
        $member = User::factory()->create(['username' => 'nightdrive_tv']);
        $data = ['notifiable' => $member, 'url' => 'https://site.example/x', 'amount' => '9,99'];
        app()->setLocale('pt');
        $views = ['premium.welcome', 'premium.cancel', 'premium.unpaid', 'premium.trial_end', 'premium.card_expire', 'premium.invoice',
            'account.verify', 'account.forgot', 'account.password', 'account.email-update', 'account.ban', 'account.delete', 'video.ban'];
        $wrong = [];
        $all = '';
        foreach ($views as $view) {
            $text = view('mails.text.'.$view, $data)->render();
            $all .= $text."\n";
            foreach (self::PT_EUROPEAN as $what => $pattern) {
                foreach (explode("\n", $text) as $line) {
                    if (preg_match($pattern, $line, $m)) {
                        $wrong[] = "{$view} [{$what}] \"{$m[0]}\" in {$line}";
                    }
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-R4: the member mails');
        foreach (['Você recebe este e-mail por causa da sua conta em', 'Gerenciar e-mails:', 'Gerenciar Premium:', 'Entre em contato conosco',
            'permanece até o fim do período pago.', 'Mudou de ideia?'] as $words) {
            $this->assertStringContainsString($words, $all);
        }

        // the texts of the mails, the invoice and the invoices page (and the Premium section of Account settings, which
        // links to it): "Seu" / "Sua" without the article, "em :date", "com final" for a card
        require_once base_path('scripts/i18n/I18n.php');
        $keys = [];
        foreach (['app/Notifications/Support/MemberMail.php', 'resources/views/users/invoice.blade.php', 'resources/views/users/invoices/index.blade.php',
            'resources/views/users/profile/sections/premium.blade.php', 'resources/views/mails/layouts/text.blade.php', 'resources/views/mails/layouts/html.blade.php'] as $file) {
            $keys = array_merge($keys, \Avd\I18n\Extractor::fromSource((string) file_get_contents(base_path($file))));
        }
        $pt = $this->file('pt');
        $european = [];
        foreach (array_unique($keys) as $key) {
            $value = (string) ($pt[$key] ?? '');
            if (preg_match('/(^|[.!?:|] *|\} *)(O seu|A sua|Os seus|As suas|O meu|A minha) /u', $value, $m) || preg_match('/período experimental|terminado em/u', $value, $m)) {
                $european[] = "{$key}: \"{$m[0]}\" in {$value}";
            }
        }
        $this->assertGreaterThan(100, count(array_unique($keys)));
        $this->assertSame([], $european, 'TL-R4: one style in the mails and on the invoices page');
        $this->assertSame('Baixar o PDF de :invoice', $pt['Download the PDF of :invoice'] ?? null);
    }

    public function test_ui_words_do_not_take_the_category_glossarys_words(): void
    {
        // the imported categories' glossary (__($category->title)) shares these keys with UI words: one neutral word
        $glossary = '/เย็ด|โป๊|淫|肉棒|乱交|骚|女优|里番|炮|素人|(?<!\p{L})sex(?!\p{L})|dâm|seks|mesum|cewek|erotik|흔녀|일본녀|여러명|원정|태닝|ngoại tình|gái |หลายรู|สาวหน้า|ผิวแทน| - |\//iu';
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['Plain', 'Unit', 'Words', 'Multiple', 'Japanese', 'Travel', 'Documentary', 'Bronze', 'Anime', 'Animation', 'Other'] as $key) {
                if (preg_match($glossary, (string) ($file[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: {$m[0]} in {$file[$key]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-R7: the Effects page\'s "Plain" read "plain girl"');
        $this->assertStringNotContainsString('प्रॉक्सी', (string) ($this->file('hi')['Revenue'] ?? ''), 'hi: "reverse proxy / load balancer"');

        // the themes' homepage voices, printed for every visitor
        $not = [
            'Fresh drops' => '/Tropfen|gotas|gouttes|капли|قطرات|बूँद|tetes|titisan|damla|หยด|滴|방울|patak/iu',
            'Dreamy shorts' => '/pantalones|шорты|شورت|celana|seluar|şort|กางเกง|短裤|ショートパンツ|Short de rêve/iu',
            'Top plays' => '/Stücke|pièces|пьесы|المسرحيات|नाटक|drama|ละคร|剧目|연극|dula/iu',
            'Viral Velocity' => '/Virus|ウイルス|바이러스|病毒|ไวรัส(?!ล)|الفيروسية/iu',
            'Now playing' => '/jugando|je joue|jogando|играю|العب|खेल रहे|bermain|naglalaro|spielen/iu',
            'Feature presentation' => '/funciones|fonctionnalités|recursos|функций|Feature-Präsentation|ميزة|fitur|ciri|özellik|คุณสมบัติ|特色介绍|기능|機能/iu',
            'Hot this week' => '/caliente|chaud|quente|حار|गर्मी|panas|暑い|sıcak|mainit/iu',
            'Index' => '/指数|ดัชนี|Indice/u',
            // the default theme's ticker pills and the hero's screen-reader words
            'Breakout' => '/Fugarse|Saia|Kaçmak|Éclater|起こる|Разразиться|انطلق|Ausbruch/u',
            'Top Reach' => '/उपदेश|Верхний охват|Portée supérieure/u',
            'slide' => '/^(gleiten|deslizar|glisser|滑动)$/u',
            'carousel' => '/회전목마|旋转木马|ม้าหมุน|हिंडोला|atlıkarınca/u',
        ];
        $wrong = [];
        foreach ($not as $key => $pattern) {
            foreach (self::LOCALES as $locale) {
                if (preg_match($pattern, (string) ($this->file($locale)[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: {$m[0]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-R7: the homepage voices');

        // the admin's imports are not trade imports, the watch page is not a wristwatch
        $this->assertSame([], $this->saying(['ja' => '/輸入/u', 'ko' => '/수입품/u', 'zh' => '/进口/u'], '/import/i'));
        $watch = '/時計|시계|นาฬิกา|đồng hồ|jam tangan|relógio|(?<!\p{L})montres?(?!\p{L})|reloj|часов|घड़ी|saat oynat/iu';
        foreach (['Days before old watch progress is deleted.', 'Dynamic video ambient light diffusion around watch player', 'Watch Related', 'Watch History Tracker',
            'Obsidian and 24k gold, centred crest, bento hero, gilded caption cards, classic watch page.'] as $key) {
            foreach (self::LOCALES as $locale) {
                if (preg_match($watch, (string) ($this->file($locale)[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: {$m[0]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-R7: watch');
    }

    public function test_the_theme_gallery_describes_designs(): void
    {
        // Appearance > Themes: each look's badge, line and "best for" (inventory:master:*) and each theme's tagline
        // (inventory:theme:*:tagline) in design words, not lunch boxes, regulations, paper clips, trousers or a master
        $keys = [];
        foreach ($this->required() as $key => $meta) {
            if (preg_match('/^inventory:(master:|theme:[^:]+:tagline$)/', (string) $meta['origin'])) {
                $keys[] = (string) $key;
            }
        }
        $this->assertGreaterThan(60, count($keys));
        $not = [
            'zh' => '/便当盒|药片|夹子|规则|短裤|饲料|信箱|发际线|电影社会|大师|移动的|大气|社论/u',
            'ja' => '/弁当|大気|社説|贅沢|ルール/u',
            'ko' => '/도시락|규칙|반바지|심사실|잔혹함|이동하는|대기|사설|사치|마스터/u',
            'ru' => '/правил|клипс|(?<!\p{L})шорты(?!\p{L})|в шортах|почтового ящика|капители|волосян|мастер/iu',
            'de' => '/Regeln|Pillenchips|Filmgesellschaft|Reiseplakathauptstädte|Impressum|Ultrasauber|Meister/u',
            'fr' => '/règles|boîte aux lettres|alimentation courte|chapiteaux|lignes de cheveux|Société|Maître/u',
            'es' => '/reglas|buzón|pantalones cortos|capiteles|Sociedad|Maestro|pastillas/u',
            'pt' => '/regras|caixa de correio|alimentação|Sociedade|Mestre|Móvel/u',
            'ar' => '/قواعد|رقائق حبوب|وجبة|المشابك|تيجان|وحشية|أسرع سيد|^متحرك$|الغلاف الجوي/u',
            'hi' => '/नियम|गतिमान|मास्टर|वायुमंडलीय/u',
            'th' => '/กฎ|อาจารย์/u',
            'id' => '/aturan|(?<!\p{L})Tuan(?!\p{L})|Tajuk rencana/iu',
            'ms' => '/peraturan|(?<!\p{L})Tuan(?!\p{L})/u',
            'tr' => '/kurallar|(?<!\p{L})usta(?!\p{L})/iu',
        ];
        $wrong = [];
        foreach ($not as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                if (preg_match($pattern, (string) ($file[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$file[$key]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'TL-R7: the theme gallery');
    }

    public function test_the_effects_and_menus_pages_second_look(): void
    {
        $not = [
            // (a) the device rule's choices: a phone that stutters, not one that stammers (zh "the same facial expression")
            'Same look everywhere. Old phones may stutter.' => '/stottern|tartamud|bégay|gaguej|заика|kekeme|تتلعثم|يتلعثم|ติดอ่าง|gagap|utal|表情|mirada/iu',
            'Old phones may stutter.' => '/stottern|tartamud|bégay|gaguej|заика|kekeme|تتلعثم|يتلعثم|ติดอ่าง|gagap|utal/iu',
            // (e) darkness and brightness for the dark / light mode
            "Each visitor's dark or light choice is remembered on their device." => '/oscuridad|темнот|الظلام|karanlık|어둠|暗さ|明るさ/iu',
            // the footer's bottom line, the copyright line, the footer link 'Terms', the "Back to top" look
            'Bottom line links' => '/Unterm Strich|resultado final|결론|底线|intinya|garis bawah|Итоговые|重要|finales|de base/iu',
            'Copyright line' => '/talian|สาย|专线|Linie|라인/u',
            'Terms' => '/용어|المصطلحات/u',
            '"Back to top" look' => '/Взгляд|نظرة|(?<!\p{L})(ดู|lihat|tingnan)$/u',
        ];
        $wrong = [];
        foreach ($not as $key => $pattern) {
            foreach (self::LOCALES as $locale) {
                $value = (string) ($this->file($locale)[$key] ?? '');
                if ($value === '' || preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} {$key}: ".($m[0] ?? 'missing')." in {$value}";
                }
            }
        }

        // (c) the cards' watched bar: a pub (de Bar, fr bar, ru бар, zh 酒吧) or a surveillance bar (ja 監視, ko 감시)
        $pubBar = ['de' => '/(?<!\p{L})Bar(?!\p{L})/u', 'fr' => '/(?<!\p{L})bar(?!\p{L})/iu', 'ru' => '/(?<!\p{L})бар(?!\p{L})/iu', 'zh' => '/酒吧|监视/u', 'ja' => '/監視/u', 'ko' => '/감시/u'];
        foreach ($pubBar as $locale => $pattern) {
            $value = (string) ($this->file($locale)['Watched bar on cards'] ?? '');
            if ($value === '' || preg_match($pattern, $value)) {
                $wrong[] = "{$locale} Watched bar on cards: {$value}";
            }
        }

        // (b) Header style and side menu style: a bar is a strip, not a pub; the names start with a capital
        $styles = ['Solid bar', 'Slim bar', 'Translucent bar', 'Floating island', 'Two-row bar', 'Over the banner', 'Full side menu', 'Icon rail', 'Floating panel', 'No side menu'];
        $help = ['A plain bar in the theme colour.', 'A thin bar that leaves room for videos.', 'A rounded bar that floats above the page.', 'Sits on the top banner; a solid bar on other pages.'];
        $pub = ['de' => '/(?<!\p{L})Bar(?!\p{L})/u', 'ru' => '/(?<!\p{L})бар(?!\p{L})/iu', 'zh' => '/酒吧/u', 'ja' => '/レール/u', 'ko' => '/레일/u', 'ms' => '/(?<!\p{L})Rel(?!\p{L})|sampingan/u', 'id' => '/sampingan/u'];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (array_merge($styles, $help) as $key) {
                $value = (string) ($file[$key] ?? '');
                if ($value === '' || (in_array($key, $styles, true) && ! preg_match('/^[\p{Lu}\p{Lo}\d]/u', $value))
                    || (isset($pub[$locale]) && preg_match($pub[$locale], $value))) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
            }
        }

        // (d) one word for what an effect costs a phone: the badges and the speed preview's estimate
        $cost = ['de' => '/Last/u', 'tr' => '/yük/iu', 'ms' => '/beban/iu', 'id' => '/beban/iu', 'ru' => '/нагрузк/iu', 'ar' => '/عب[ءئ]/u', 'fr' => '/impact/iu',
            'th' => '/กินทรัพยากร/u', 'zh' => '/消耗/u', 'ja' => '/負荷/u', 'ko' => '/부담/u', 'hi' => '/भार/u', 'vi' => '/tốn/iu'];
        $estimate = 'An estimate from the effects you chose: each effect adds its speed cost for weak phones. The real site still adapts to every device (Auto).';
        foreach ($cost as $locale => $word) {
            $file = $this->file($locale);
            foreach (['No cost', 'Low cost', 'Medium', 'Heavy', $estimate] as $key) {
                if (! preg_match($word, (string) ($file[$key] ?? ''))) {
                    $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-');
                }
            }
        }

        // (f) the admin Home's line takes the Reports page's word for a report
        $report = ['de' => 'Meldung', 'es' => 'reporte', 'fr' => 'signalement', 'ja' => '報告', 'ko' => '신고', 'pt' => 'denúncia', 'ru' => 'жалоб', 'tr' => 'şikayet', 'zh' => '举报', 'ar' => 'بلاغ'];
        foreach ($report as $locale => $word) {
            $line = (string) ($this->file($locale)['1 report waiting|:count reports waiting'] ?? '');
            if (mb_stripos($line, $word) === false) {
                $wrong[] = "{$locale} 1 report waiting: {$line}";
            }
        }

        // the link lists' help keeps the format (it read "icon Name | address |", "Name the | address")
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['One link per line: Name | address | icon. Shown to everyone.' => 2, 'One link per line: Name | address.' => 1] as $key => $pipes) {
                $value = (string) ($file[$key] ?? '');
                if (substr_count($value, '|') !== $pipes || ! preg_match('/[\p{L}\p{M}]+ \| [\p{L}\p{M}]+/u', $value) || preg_match('/\|\s*[.。।]|\|\s*$/u', $value)) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
            }
            if (! str_contains((string) ($file['One link per line, for example Terms | /terms.'] ?? ''), '| /terms')) {
                $wrong[] = "{$locale} the example link: ".($file['One link per line, for example Terms | /terms.'] ?? '-');
            }
        }
        $this->assertSame([], $wrong, 'R3X-06');
        $this->assertSame('Einfarbige Leiste', $this->file('de')['Solid bar'] ?? null, 'de: "Solide Bar" = a pub');
        $this->assertSame('Fortschrittsbalken auf Karten', $this->file('de')['Watched bar on cards'] ?? null);
        $this->assertSame('이용약관', $this->file('ko')['Terms'] ?? null, 'ko: 용어 = terminology');
    }

    /** @return list<string> the options of every <select name="header_style"> of a page, as the browser shows them */
    private function headerStyleOptions(string $html): array
    {
        $options = [];
        preg_match_all('/<select[^>]*name="header_style"[^>]*>(.*?)<\/select>/s', $html, $selects);
        foreach ($selects[1] as $body) {
            preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $body, $m);
            foreach ($m[1] as $text) {
                $options[] = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return $options;
    }

    public function test_the_old_admins_header_style_select_names_each_option_one_way(): void
    {
        $over = 'Over the banner (falls back to Solid bar on pages without a banner)';
        $rail = 'App rail (old option: now Translucent bar + side menu "Icon rail")';
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $named = [$over => ['Solid bar'], $rail => ['Translucent bar', 'Icon rail']];
            foreach ($named as $key => $options) {
                foreach ($options as $option) {
                    if (mb_stripos((string) ($file[$key] ?? ''), (string) ($file[$option] ?? "\0")) === false) {
                        $wrong[] = "{$locale} {$key}: \"{$file[$option]}\" not in {$file[$key]}";
                    }
                }
            }
            // the old tab's switches say what the new admin says for the same settings
            foreach (['Locale Switcher' => 'Language switcher in the side menu', 'Theme Switcher' => 'Dark/light switch in the side menu',
                'Footer Language Switcher' => 'Language switcher in the footer', 'Footer Theme Switcher' => 'Dark/light switch in the footer',
                'Plan Switcher' => 'Monthly / yearly switch', 'Discover Link' => '"Discover channels" link', 'Explore Column' => '"Explore" column',
                'Support Column' => '"Support" column', 'Category Sub-Rail' => 'Category row under the header', 'Search Focus Glow' => 'Glow around the search box',
                'Sidebar Subscriptions' => 'Subscriptions in the side menu'] as $old => $new) {
                if (($file[$old] ?? null) !== ($file[$new] ?? '-')) {
                    $wrong[] = "{$locale} {$old}: ".($file[$old] ?? '-').' (the new admin: '.($file[$new] ?? '-').')';
                }
            }
        }
        $this->assertSame([], $wrong, 'R3X-06 (b)');
        $this->assertStringNotContainsString('الجلاد', (string) ($this->file('ar')['Plan Switcher'] ?? ''), 'ar: "the executioner\'s plan"');
        $view = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));
        $this->assertStringNotContainsString('Liquid glass blur, floating glass island', $view, 'the select\'s help named the options other words');
        $this->assertStringContainsString("__('Solid, slim, translucent, a floating island, two rows, or over the banner on pages that have one.')", $view);

        // the page the reviewer rendered: each option of the two selects quotes the others with their own words
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        foreach (['de' => ['Einfarbige Leiste', 'Durchscheinende Leiste', 'Symbolspalte'], 'ms' => ['Bar warna padu', 'Bar lut sinar', 'Lajur ikon'],
            'ja' => ['単色バー', '半透明のバー', 'アイコン列'], 'ar' => ['شريط بلون واحد', 'شريط شبه شفاف', 'عمود الأيقونات']] as $locale => [$solid, $translucent, $iconRail]) {
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get(route('admin.settings.index'))->assertOk()->getContent();
            $options = $this->headerStyleOptions($html);
            $this->assertCount(14, $options, $locale);
            $long = array_values(array_filter($options, fn (string $o): bool => str_contains($o, '(') || str_contains($o, '（')));
            $this->assertCount(4, $long, $locale);
            foreach ($long as $option) {
                $this->assertTrue(str_contains($option, $solid) || (str_contains($option, $translucent) && str_contains($option, $iconRail)), "{$locale}: {$option}");
            }
            $this->assertStringContainsString(e($this->file($locale)['Language switcher in the side menu']), $html, $locale);
        }
    }

    public function test_a_look_is_not_a_gaze_and_a_complete_look_has_one_word(): void
    {
        // a complete look (Appearance > Themes, the update's "Restore your previous look", the messages) says the
        // language's design word; the member's channel page keeps its own one (ru оформление, th ดีไซน์)
        $design = ['ar' => 'تصميم', 'de' => 'Design', 'es' => 'diseño', 'fil' => 'disenyo', 'tl' => 'disenyo', 'fr' => 'design', 'hi' => 'डिज़ाइन',
            'id' => 'desain', 'ja' => 'デザイン', 'ko' => '디자인', 'ms' => 'reka bentuk', 'pt' => 'design', 'ru' => 'дизайн', 'th' => 'การออกแบบ',
            'tr' => 'tasarım', 'vi' => 'giao diện', 'zh' => '设计'];
        $keys = ['Complete look', 'Complete looks', 'Set automatically when you publish a complete look.', 'Pick a complete look or a theme. Preview it on your real site, then publish.',
            'Set when you publish a complete look.', 'A complete look sets the theme, the homepage and the video page in one click.', 'Complete look applied', 'Look applied',
            'Look ":look" applied.', 'This look does not exist.', 'This look is not available.', 'Use this look now', 'Publish this look', 'Preview this look', 'Choose a look',
            'Restore your previous look?', 'Your previous look is back.', 'The new look could not be applied. Try again.', 'Back to the factory look'];
        $wrong = [];
        foreach ($design as $locale => $word) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                if (mb_stripos((string) ($file[$key] ?? ''), $word) === false) {
                    $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-');
                }
            }
        }
        $this->assertSame([], $wrong, 'one word for a complete look');

        // a part's look is its appearance, not a gaze or a verb; a tile is not a floor tile; bento is not a lunch box
        $not = [
            'Card look' => '/mirada|pandangan|pagtingin/iu', 'Complete look' => '/mirada|نظرة|pandangan/iu', 'Scrollbar look' => '/نظرة|(?<!\p{L})lihat|tumingin/iu',
            'Back to the factory look' => '/نظرة|看看|공장 모습/u', 'Category tile look' => '/Fliese|بلاط|瓷砖|döşeme|carreaux/u', 'Soft tile with rounded corners.' => '/Fliese|بلاط|瓷砖|baldosa|azulejo|กระเบื้อง/u',
            'Bento Card (Rounded Tile with Gradient Rim Glow)' => '/便当|도시락|弁当|بلاط|döşeme/u', 'Rail' => '/kereta api|Demiryolu|Schiene|Carril|Trilho|レール|레일|(?<!\p{L})Rel(?!\p{L})|Riles|रेल|轨/u',
        ];
        $wrong = [];
        foreach ($not as $key => $pattern) {
            foreach (self::LOCALES as $locale) {
                if (preg_match($pattern, (string) ($this->file($locale)[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: {$m[0]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'R3X-06: look, tile, bento, rail');
    }

    public function test_a_visitors_report_has_the_reports_pages_word(): void
    {
        // the texts about a visitor's report (the Reports page, the report settings, the admin Home's table, the member's
        // report dialog); "Error Reporting" (PHP) and an import that "did not report running" are other things
        $other = '/Error Reporting|did not report running|no progress was reported/';
        $keys = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => (bool) preg_match('/report/i', $key) && ! preg_match($other, $key)));
        $this->assertGreaterThan(60, count($keys));
        $document = ['de' => '/Bericht/u', 'es' => '/(?<!\p{L})informes?(?!\p{L})/iu', 'fr' => '/(?<!\p{L})rapports?(?!\p{L})/iu', 'pt' => '/relatório/iu',
            'ru' => '/отч[её]т/iu', 'tr' => '/(?<!\p{L})rapor|bildirim|şikâyet/iu', 'zh' => '/报告/u', 'ko' => '/보고서|(?<![가-힣])보고(?![가-힣])/u', 'ja' => '/レポート/u',
            'ar' => '/تقرير|تقارير/u', 'fil' => '/(?<!\p{L})(i-)?report/iu', 'tl' => '/(?<!\p{L})(i-)?report/iu'];
        $wrong = [];
        foreach ($document as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                if (in_array($key, ['Report', 'Reports'], true) && in_array($locale, ['fil', 'tl'], true) && preg_match('/ulat/iu', (string) ($file[$key] ?? ''))) {
                    continue;
                }
                if (preg_match($pattern, (string) ($file[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$file[$key]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'R3X-06: the report word');

        // the member's report dialog (Preact)
        $title = fn (string $locale): string => (string) ($this->jsFile($locale)['Report {type}'] ?? '');
        $this->assertSame(['{type} melden', 'Signaler {type}', 'Denunciar {type}', 'Пожаловаться: {type}', '举报{type}', '{type} की रिपोर्ट करें', '{type}を報告'],
            [$title('de'), $title('fr'), $title('pt'), $title('ru'), $title('zh'), $title('hi'), $title('ja')], 'hi read "आख्या की प्रकार} {type}"');
        $this->assertSame('Meine Meldungen ansehen', $this->jsFile('de')['See my reports'] ?? null);
        $this->assertSame('Salamat sa pag-uulat', $this->jsFile('tl')['Thanks for reporting'] ?? null);

        // fourth pass: Spanish says reportar / reporte in the report dialogs too ('Gracias por informar', 'canales
        // denunciados ... tus denuncias', '¿Denunciar este comentario?' next to the 'Reportar' button)
        $spanish = [];
        foreach ($this->texts('es', '/report/i', $other) as $key => $value) {
            if (preg_match('/denunci|(?<!\p{L})informa(r|do|da)(?!\p{L})|(?<!\p{L})informes?(?!\p{L})/iu', $value, $m)) {
                $spanish[] = "{$key}: \"{$m[0]}\" in {$value}";
            }
        }
        $this->assertSame([], $spanish, 'one word for a report in Spanish');
        $this->assertSame(['Gracias por reportar', '¿Reportar este comentario?', 'Reportar', 'Ver mis reportes'],
            [$this->jsFile('es')['Thanks for reporting'] ?? null, $this->jsFile('es')['Report this comment?'] ?? null, $this->jsFile('es')['Report'] ?? null, $this->jsFile('es')['See my reports'] ?? null]);
        $this->assertStringContainsString('reportados', (string) ($this->jsFile('es')['Our team reviews reported videos, comments and channels every day. You can follow your reports in your account.'] ?? ''));
    }

    public function test_menu_paths_quote_the_menus_words_and_the_admin_is_a_place(): void
    {
        // TL-R6: Japanese says Money with the menu's word (lane AD gives the same two values)
        foreach (['Shows the "Box next to the video" ad (set in Money > Ads).', 'The ads you set up in Money > Ads play with this video.'] as $key) {
            $this->assertStringContainsString('[収益化] > [広告]', (string) ($this->file('ja')[$key] ?? ''), $key);
        }
        // the test button is quoted with its own words
        $ask = 'Ask new members to confirm their email? Make sure emails work first (Settings > Email > Send a test email), or new members get stuck.';
        foreach (['ar', 'de', 'ja', 'pt', 'hi', 'zh', 'ko', 'es', 'ru', 'tr'] as $locale) {
            $file = $this->file($locale);
            $this->assertStringContainsString((string) $file['Send a test email'], (string) ($file[$ask] ?? ''), $locale);
        }
        // the admin of this site (the place) opens; an administrator (a person) does not
        $person = [
            'de' => '/Der Administrator|den Administrator/u', 'fr' => '/L\'administrateur de ce site|l\'administrateur l\'ouvre|Ouvrez l\'administrateur/u',
            'pt' => '/O administrador deste site|o administrador abre|Abra o administrador/u', 'ru' => '/Администратор этого сайта|администратор снова/u',
            'zh' => '/的管理员只能|管理员可以再次|打开管理员/u', 'ko' => '/사이트의 관리자는|관리자가 어디에서나|관리자를 엽니다/u', 'hi' => '/का व्यवस्थापक|व्यवस्थापक कहीं|व्यवस्थापक खोलें/u',
            'ja' => '/サイトの管理者は|管理者はどこからでも|管理者を開きます/u', 'es' => '/El administrador de este sitio/u', 'tr' => '/sitenin yöneticisi|yönetici tekrar|Yöneticiyi listedeki/u',
            'th' => '/^ผู้ดูแลระบบของไซต์นี้|ดังนั้นผู้ดูแลระบบจึง|^เปิดผู้ดูแลระบบ/u', 'ms' => '/^Pentadbir tapak ini|jadi pentadbir membuka|^Buka pentadbir/u',
        ];
        $keys = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => (bool) preg_match('/^(The admin of this site opens only|It empties the list, so the admin opens|Open the admin from an address on the list)/', $key)));
        $this->assertCount(3, $keys);
        $wrong = [];
        foreach ($person as $locale => $pattern) {
            foreach ($keys as $key) {
                if (preg_match($pattern, (string) ($this->file($locale)[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: {$m[0]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'the admin as a person');
        foreach (['ko' => '모든 회원', 'th' => 'สมาชิกทั้งหมด'] as $locale => $members) {
            $this->assertSame($members, $this->file($locale)['All members'] ?? null, $locale);
        }
        // 'Back to Home' (the developer-only notice) goes back to the admin's Home, not to one's house
        $house = '/Rumah|Bahay|บ้าน|घर|nach Hause|para casa/u';
        foreach (self::LOCALES as $locale) {
            $this->assertDoesNotMatchRegularExpression($house, (string) ($this->file($locale)['Back to Home'] ?? ''), $locale);
        }
        // French says e-mail in its texts (16 said "email" beside 142 "e-mail"); the settings page keeps its menu name
        $fr = $this->file('fr');
        $this->assertSame(['Les e-mails sont envoyés', 'Répondre par e-mail', 'Nom ou e-mail'], [$fr['Emails are sent'] ?? null, $fr['Reply by email'] ?? null, $fr['Name or email'] ?? null]);
    }

    public function test_the_old_appearance_tab_and_its_neighbours_say_what_they_mean(): void
    {
        // words read in their other sense: railways, a house, animal food or a power supply, "Mrs.", a football player,
        // a TV show, potato chips, a cross-section, a hexagon, a graphics card, an order to remember
        $not = [
            'Rail Links' => '/Schienen|铁路|السكك|रेल|Riles|ray/u', 'Fallback Rails' => '/Schienen|导轨|القضبان|rieles|rails|Trilhos|рельсы|ray/iu',
            'Hide Rail Scrollbars' => '/Schienen|导轨|السكك|рельсов|rieles|ray/iu', 'Cleaner category, discovery, and action rails.' => '/清洁剂|Temizleyici|क्लीनर|Schienen|rieles|rails|рельсы|ray|قضبان/iu',
            'Feed Source' => '/Futter|饲料|alimentación|alimentação|Yem/iu', 'Home Feed Architecture' => '/أعلاف|反馈|Yem|Umpan Rumah|Suapan Rumah/u',
            'Home Layout' => '/المنزل|Rumah|Ev Düzeni|Bahay|Планировка|maison/iu', 'High-Speed Feed' => '/Alimenta|Vorschub|送り|이송|进给|подача/iu',
            'Duration Ms' => '/Frau|女士|السيدة/u', 'Stagger Ms' => '/女士|السيدة|踉踉跄跄/u', 'Intro Kicker' => '/踢球者|كيكر/u',
            'Main list shows' => '/ショー|(?<![가-힣])쇼|gösterileri|palabas|पता चलता/u', 'Featured section shows' => '/ショー|(?<![가-힣])쇼|gösterileri|palabas|shows/iu',
            'Category chips' => '/чипсы|芯片|رقائق|Puces|หมวดหมู่ชิป/u', 'Section animation' => '/단면|剖面|قسم الرسوم/u', 'Cards appear one by one' => '/卡牌/u',
            'Keep watch progress for' => '/Surveillez|im Auge|Vigila|Acompanhe|Следите|关注|監視|مراقبة|izlemeye|नजर|Pantau|ติดตาม/u',
            'Remember where viewers stopped' => '/Denken Sie|Помните|unutmayın|hatırlayın|Rappelez-vous|Lembre-se|Recuerda/u',
            'Click to copy Primary Hex' => '/hexagone|六角|шестигран|السداسي الثانوي/u', 'Click to copy Secondary Hex' => '/hexagone|六角|шестигран|الملف السداسي/u',
            'Channel home tab video cards' => '/Grafikkarten|Placas de vídeo|rumah/u', 'Back to Home' => '/Rumah|Bahay|บ้าน|घर वापिस|nach Hause|para casa/u',
        ];
        $wrong = [];
        foreach ($not as $key => $pattern) {
            foreach (self::LOCALES as $locale) {
                if (preg_match($pattern, (string) ($this->file($locale)[$key] ?? ''), $m)) {
                    $wrong[] = "{$locale} {$key}: {$m[0]} in {$this->file($locale)[$key]}";
                }
            }
        }
        $this->assertSame([], $wrong, 'R3X-06: the same kinds in the neighbouring admin texts');

        // the old tab's homepage and phone switches say what the new admin says for the same settings
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['Rail Links' => '"See all" links on rows', 'Fallback Rails' => 'Fill empty rows', 'Feed Source' => 'Main list shows',
                'Duration Ms' => 'Animation length', 'Stagger Ms' => 'Delay between cards', 'Intro Kicker' => 'Small line above the title',
                'Hide Rail Scrollbars' => 'Hide scrollbars on sideways rows', 'Home Feed Architecture' => 'Homepage layout'] as $old => $new) {
                $this->assertSame($file[$new] ?? '-', $file[$old] ?? null, "{$locale} {$old}");
            }
        }
    }

    public function test_the_ad_spaces_say_where_they_are_and_what_they_take(): void
    {
        // the old admin's Monetization and Appearance tabs name these settings with the new admin's words for them
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['Watch Sidebar Card' => 'Box next to the video', 'Watch Related' => 'Up next', 'Mobile Sticky Mini Banner' => 'Small banner on phones',
                'Video Pre-roll HTML Fallback' => 'Backup for video ads', 'Watch Page Presentation' => 'Video page layout'] as $old => $new) {
                $this->assertSame($file[$new] ?? '-', $file[$old] ?? null, "{$locale} {$old}");
            }
        }

        // words read in their other sense: glue, an order to stay calm, physical pressure, a liquid, the homepage,
        // "can be ignored", a data backup, a "banner telephone", "watch" as a verb
        $not = [
            'Small banner on phones' => '/selbstklebend|collante|pegajos|липк|لزجة|粘性|Yapışkan|Lengket|Melekit|dính|스티커|cartel/iu',
            'Right column slot on the watch page. Keep it calm and contained.' => '/Bleiben Sie ruhig|Сохраняйте спокойствие|落ち着いて|침착|격리|保持冷静|รักษาความสงบ|bình tĩnh|اجعل الأمر/u',
            'Bottom page banner for broad but low-pressure inventory.' => '/Druck|presión|pression|давлени|低压|圧力|압력|basınç|tekanan|presyon|दबाव|ضغط|áp lực|แรงกดดัน|pressão|inventar|inventory/iu',
            'Top page banner. Best for 728x90 or fluid responsive tags.' => '/flüssig|fluid|السوائل|द्रव|cecair|流動|유동|lancar|主页|トップページ|톱 페이지|première page|верхней странице|Üst sayfa|tuluy-tuloy/iu',
            'Small dismissible mobile slot. Use compact responsive tags only.' => '/無視|무시|игнорир|récupérable|للرفض|reddedilebilir|tháo rời|diabaikan|diketepikan|descartável|na-dismiss|खारिज/u',
            'Backup for video ads' => '/Copia de seguridad|Sauvegarde|النسخ الاحتياطي|สำรองข้อมูล|yedekleme|备份|백업|バックアップ|बैकअप|Backup/u',
            'A small banner phone visitors can close. Use compact ad sizes.' => '/Banner-Telefon|バナー電話|배너 전화|bannière téléphonique|通过电话|لافتة هاتفية|banner phone|telefon sepanduk|โทรศัพท์แบนเนอร์|banner telefonu|बैनर फोन|посетители телефона|visitantes do telefone/iu',
            'Signed-in subscriptions latest video feed.' => '/Đăng ký đăng ký|購読します|هي أحدث|iniciadas con/u',
            'Video page layout' => '/(?<!\p{L})(ansehen|Regarder|Посмотреть|Assistir|شاهد|Panoorin|İzle|देखें)(?!\p{L})|を見る|surveillance|演示/u',
        ];
        $wrong = [];
        foreach ($not as $key => $pattern) {
            foreach (self::LOCALES as $locale) {
                $value = (string) ($this->file($locale)[$key] ?? '');
                if ($value === '' || preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} {$key}: ".($m[0] ?? 'missing')." in {$value}";
                }
            }
        }
        $this->assertSame([], $wrong, 'R3X-06: the ad spaces of both admins');

        // the old Monetization tab prints them (admin.ui v1)
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        $html = (string) $this->actingAs($admin)->withSession(['app_locale' => 'de'])->get(route('admin.settings.index', ['tab' => 'monetization']))->assertOk()->getContent();
        foreach (['Box neben dem Video', 'Kleines Banner auf Telefonen', 'Ersatz für Videoanzeigen'] as $words) {
            $this->assertStringContainsString(e($words), $html);
        }
        $this->assertStringNotContainsString('selbstklebend', $html);
    }

    public function test_the_up_next_list_is_quoted_with_its_headings_words(): void
    {
        // Appearance > Video page and Money > Ads quote the watch page's "Up next" list (ja 「次へ」, pt "Próximo", ru
        // «Следующие», hi "अगली बार" = next time, ko '다음 단계' = the next step beside the heading's own words)
        $keys = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => str_contains($key, '"Up next"')));
        $this->assertCount(12, $keys);
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $heading = (string) ($file['Up next'] ?? '');
            foreach ($keys as $key) {
                if ($heading === '' || ! str_contains((string) ($file[$key] ?? ''), $heading)) {
                    $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-')." (the heading: {$heading})";
                }
            }
            // the old Appearance tab's switch of the same setting, and sticky read as glue (the 'Sticky Header' kind)
            if (($file['Sticky Sidebar'] ?? null) !== ($file['"Up next" list stays in view'] ?? '-')) {
                $wrong[] = "{$locale} Sticky Sidebar: ".($file['Sticky Sidebar'] ?? '-');
            }
        }
        $this->assertSame([], $wrong, 'TL-R6: the "Up next" list in its own words');
        $this->assertSame('Als Nächstes', $this->file('de')['Up next'] ?? null, 'de: "Als nächstes" is the old spelling');
    }

    public function test_category_keeps_its_plain_word_where_fifteen_places_print_it(): void
    {
        // the third pass gave 'Category' the words of one setting ("Category for the main list") in every language: the
        // public category page's kicker, the hero HUD, the studio's video form and filter, the admin's tables and forms
        // print the same key
        $plain = ['ar' => 'التصنيف', 'de' => 'Kategorie', 'es' => 'Categoría', 'fil' => 'Kategorya', 'fr' => 'Catégorie', 'hi' => 'श्रेणी',
            'id' => 'Kategori', 'ja' => 'カテゴリ', 'ko' => '카테고리', 'ms' => 'Kategori', 'pt' => 'Categoria', 'ru' => 'Категория', 'th' => 'หมวดหมู่',
            'tl' => 'Kategorya', 'tr' => 'Kategori', 'vi' => 'Danh mục', 'zh' => '分类'];
        foreach ($plain as $locale => $word) {
            $this->assertSame($word, $this->file($locale)['Category'] ?? null, $locale);
            $this->assertNotSame($this->file($locale)['Category'] ?? null, $this->file($locale)['Category for the main list'] ?? null, $locale);
        }

        // the old Appearance tab prints the new admin's key for its one setting
        $view = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));
        $this->assertStringContainsString('for="home_feed_category_id">{{__(\'Category for the main list\')}}</label>', $view);
        $this->assertStringNotContainsString('for="home_feed_category_id">{{__(\'Category\')}}</label>', $view);

        // the public category page's kicker says the plain word
        \App\Models\Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        foreach (['de' => 'Kategorie', 'ja' => 'カテゴリ', 'ar' => 'التصنيف'] as $locale => $word) {
            $html = (string) $this->withSession(['app_locale' => $locale])->get(route('pages.category', 'music'))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<div[^>]*avd-page-kicker[^>]*>\s*<i[^>]*><\/i>\s*<span>'.preg_quote(e($word), '/').'<\/span>/u', $html, $locale);
            $this->assertStringNotContainsString(e($this->file($locale)['Category for the main list']), $html, $locale);
        }
    }

    public function test_an_old_label_takes_the_new_admins_words_only_where_the_old_settings_page_alone_prints_it(): void
    {
        // the kind of the 'Category' regression: an old admin label (the settings catalogue's 'old' => [group, label],
        // the setting's former name) that says the new admin's label of its setting in every language must not be
        // printed anywhere but the old settings page - else another place says one setting's words
        $file = [];
        foreach (self::LOCALES as $locale) {
            $file[$locale] = $this->file($locale);
        }
        $followers = [];
        foreach ((array) config('admin_settings.settings') as $key => $setting) {
            $old = (string) ($setting['old'][1] ?? '');
            $new = (string) ($setting['label'] ?? '');
            if ($old === '' || $old === $new || ! isset($file['de'][$old])) {
                continue;
            }
            $same = true;
            foreach (self::LOCALES as $locale) {
                $same = $same && ($file[$locale][$old] ?? null) === ($file[$locale][$new] ?? false);
            }
            if ($same) {
                $followers[$old] = $new;
            }
        }
        $this->assertGreaterThan(60, count($followers), 'the old labels that take the new admin\'s words');
        $this->assertArrayNotHasKey('Category', $followers);

        // every print of each of them in the code (comments aside): translation calls, Preact keys, config entries, labels
        $quoted = [];
        foreach (array_keys($followers) as $old) {
            $quoted[] = "'".preg_quote(str_replace(['\\', "'"], ['\\\\', "\\'"], $old), '/')."'";
            $quoted[] = '"'.preg_quote(str_replace(['\\', '"'], ['\\\\', '\\"'], $old), '/').'"';
        }
        $pattern = '/'.implode('|', $quoted).'/u';
        $files = [];
        foreach (['app', 'resources/views', 'resources/js', 'config', 'routes'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                $path = str_replace('\\', '/', $entry->getPathname());
                if (preg_match('/\.(php|ts|tsx)$/', $path) && ! str_contains($path, '/resources/js/lang/')) {
                    $files[] = $path;
                }
            }
        }
        $elsewhere = [];
        foreach ($files as $path) {
            $relative = substr($path, strlen(str_replace('\\', '/', base_path())) + 1);
            if (in_array($relative, ['resources/views/admin/settings/index.blade.php', 'config/admin_settings.php'], true)) {
                continue;
            }
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~\{\{--.*?--\}\}~s', '~<!--.*?-->~s', '~^\s*(//|#(?!\[)).*$~m'], '', (string) file_get_contents($path));
            if (preg_match_all($pattern, $code, $m)) {
                foreach (array_unique($m[0]) as $literal) {
                    $elsewhere[] = "{$relative}: {$literal}";
                }
            }
        }
        $this->assertSame([], $elsewhere, 'an old label with one setting\'s words is printed elsewhere');

        // config/admin_settings.php names them only as former names ('old' => [group, label])
        $catalogue = (string) file_get_contents(config_path('admin_settings.php'));
        foreach (array_keys($followers) as $old) {
            $literal = "'".str_replace("'", "\\'", $old)."'";
            $this->assertSame(substr_count($catalogue, $literal), preg_match_all("/'old' => \\['[a-z_]+', ".preg_quote($literal, '/').'\\]/u', $catalogue), $old);
        }
    }

    public function test_texts_that_name_discover_channels_say_its_name(): void
    {
        // the page and the side menu item are 'Discover channels'; the texts that name it said other words (ru
        // «Открытие каналов» for «Найти каналы», tr "Kanalları keşfet" for "Kanal keşfet", ja 「チャンネルを探す」 ...)
        $keys = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => str_contains($key, 'Discover channels') && $key !== 'Discover channels'));
        $this->assertGreaterThan(5, count($keys));
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $name = (string) ($file['Discover channels'] ?? '');
            foreach (array_merge($keys, ['Discover Users', 'Discover Link', 'Discover Channels Label']) as $key) {
                if ($name === '' || ! str_contains((string) ($file[$key] ?? ''), $name)) {
                    $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-')." (the page: {$name})";
                }
            }
        }
        $this->assertSame([], $wrong, 'the page\'s own name');

        // its help says the largest number of channels the page takes, not "most of the channels"
        $most = ['ar' => 'معظم', 'de' => 'Die meisten', 'es' => 'La mayoría', 'fil' => 'Karamihan', 'fr' => 'La plupart', 'hi' => 'अधिकांश', 'id' => 'Sebagian besar',
            'ja' => 'ほとんど', 'ko' => '대부분', 'ms' => 'Kebanyakan', 'pt' => 'A maioria', 'ru' => 'Большинство', 'th' => 'ส่วนใหญ่', 'tl' => 'Karamihan',
            'tr' => 'çoğu', 'vi' => 'phần lớn', 'zh' => '大多数'];
        foreach ($most as $locale => $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, (string) ($this->file($locale)['Most channels the Discover channels page draws from.'] ?? $word), $locale);
        }
    }

    public function test_the_header_style_help_and_japanese_name_each_thing_one_way(): void
    {
        // the Header style help (both admins print it) names the styles as the options say them: fil / tl "Isang kulay"
        // for 'Solid na bar', vi "nằm trên banner" for 'Đè lên biểu ngữ', ko '떠 있는 섬' for '떠다니는 섬' ...
        $help = 'Solid, slim, translucent, a floating island, two rows, or over the banner on pages that have one.';
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $text = mb_strtolower((string) ($file[$help] ?? ''));
            foreach (['Floating island', 'Over the banner'] as $option) {
                $word = mb_strtolower((string) ($file[$option] ?? '-'));
                $word = $locale === 'ja' ? rtrim($word, 'に') : $word;
                if (! str_contains($text, $word)) {
                    $wrong[] = "{$locale} {$option}: \"{$word}\" not in {$text}";
                }
            }
        }
        $this->assertSame([], $wrong, 'R3X-06 (b): the help names the options as they are');
        foreach (['fil', 'tl'] as $locale) {
            $this->assertStringStartsWith('Solid,', (string) ($this->file($locale)[$help] ?? ''), $locale.': "Isang kulay" = one colour');
        }

        // Japanese: one word for a phone (スマホ; a mobile phone number stays 携帯電話番号) and one spelling of a word
        $ja = $this->file('ja');
        $this->assertSame('スマホ用の小さなバナー', $ja['Small banner on phones'] ?? null, '携帯電話上の小さなバナー under helps that say スマホ');
        $this->assertSame($ja['Small banner on phones'] ?? null, $ja['Mobile Sticky Mini Banner'] ?? null);
        $this->assertSame(['カテゴリ', '「カテゴリ」の見出し', '「カテゴリ」欄', 'カテゴリリンク'],
            [$ja['Categories'] ?? null, $ja['"Categories" heading'] ?? null, $ja['"Categories" column'] ?? null, $ja['Category links'] ?? null]);
        $wrong = [];
        foreach (self::JA_ONE_WAY as $pattern => $word) {
            foreach ($this->saying(['ja' => $pattern]) as $line) {
                $wrong[] = "{$word}: {$line}";
            }
        }
        $this->assertSame([], $wrong, 'Japanese writes one word one way');
    }

    public function test_the_placeholder_help_names_braces_as_braces(): void
    {
        // 'Words in {braces} are filled in for you: ...' (nine Appearance / SEO helps): pt "colchetes" (square brackets),
        // tr "Parantez" (parentheses), ar "قوسين" (parentheses) in four of the nine, the English word in fil / tl / ms / th
        $keys = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => str_starts_with($key, 'Words in {braces} are filled in for you:')));
        $this->assertCount(9, $keys);
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = (string) ($file[$key] ?? '');
                if ($value === '' || str_contains($value, '{braces}') || preg_match('/colchete|^Parantez|بين قوسين/u', $value)) {
                    $wrong[] = "{$locale} {$key}: {$value}";
                }
            }
        }
        $this->assertSame([], $wrong, 'braces are braces');
        $this->assertStringStartsWith('As palavras entre chaves', (string) ($this->file('pt')[$keys[0]] ?? ''));
    }

    public function test_texts_that_quote_a_switch_or_button_say_its_words(): void
    {
        // the admin's helps and confirms quote a switch, a button or an option of the same feature (TL-R6's kind past
        // 'Discover channels'); a label worded as an order to the reader ("Verwenden Sie ...", "...してください") or in another
        // sense (a shop counter for 'Counter only') was corrected first
        $pairs = [
            'Run this JavaScript on every page? A mistake can break your site. You can pause it with "Use my custom code".' => 'Use my custom code',
            'Add this code to every page? A mistake can break your site. You can pause it with "Use my custom code".' => 'Use my custom code',
            '"Back up now" saves a copy of your database here.' => 'Back up now',
            '"Counter only" keeps totals and saves the most space.' => 'Counter only',
            'Number of characters before a long comment shows "Read more".' => 'Read more',
            'Character cutoff before showing "Read more" toggle.' => 'Read more',
            'Click "Gmail" under "Fill in for".' => 'Fill in for',
            '"Fresh picks" moves' => 'Fresh picks',
            'A few words, for example "Fresh picks".' => 'Fresh picks',
            'A lead video and a "Most watched" column.' => 'Most watched',
            'Lead story + "Most watched" column' => 'Most watched',
            'Automatically while scrolling, with a "Load more" button, or with page numbers.' => 'Load more',
            'Show "Today\'s design" label' => "Today's design",
            'No featured content. Click "Add Featured" to get started.' => 'Add Featured',
            '"NEW" Badge Days Threshold' => 'NEW',
            '"Copied" message' => 'Copied',
            '"Back to top" look' => 'Back to top',
            '"Back to top" button' => 'Back to top',
            'No custom pages yet. Click "Create Page" to add one.' => 'Create Page',
            '"Strong" makes text darker or brighter so it is easier to read.' => 'Strong',
            '"Show more" text' => 'Show more',
            'One choice sets everything below. You can still fine-tune each effect; the level then shows "Custom".' => 'Custom',
            'For example "Related videos" or "Up next".' => 'Related videos',
            '"Everyone" sets language=NULL. "Auto-detect" maps country to locale. "Fixed locale" pins visibility to one selected locale.' => 'Fixed locale',
        ];
        $starts = [
            'Turn on "Always use https"?' => 'Always use https', 'Click "Preview in Studio" on any day' => 'Preview in Studio',
            'With "Theme too", the homepage layout changes' => 'Theme too', 'How "Back to top" look looks.' => 'Back to top',
            'The words shown for "Show more" text.' => 'Show more', 'The quickest way to know emails work. This used to be the "Email Test"' => 'Email Test',
        ];
        foreach ($starts as $start => $label) {
            $found = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => str_starts_with($key, $start)));
            $this->assertCount(1, $found, $start);
            $pairs[$found[0]] = $label;
        }
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach ($pairs as $key => $label) {
                $words = rtrim(mb_strtolower((string) ($file[$label] ?? "\0")), '.');
                if (! str_contains(mb_strtolower((string) ($file[$key] ?? '')), $words)) {
                    $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-')." (the label: {$file[$label]})";
                }
            }
        }
        $this->assertSame([], $wrong, 'a quoted label says its own words');
        // labels worded as an order to the reader, and a shop counter
        $this->assertSame(['Meinen benutzerdefinierten Code verwenden', 'カスタムコードを使用', '常にhttpsを使用', '今すぐバックアップ', 'Solo contador', '仅计数器', 'Lire la suite'],
            [$this->file('de')['Use my custom code'] ?? null, $this->file('ja')['Use my custom code'] ?? null, $this->file('ja')['Always use https'] ?? null,
                $this->file('ja')['Back up now'] ?? null, $this->file('es')['Counter only'] ?? null, $this->file('zh')['Counter only'] ?? null, $this->file('fr')['Read more'] ?? null]);
        $this->assertSame('Lire la suite', $this->jsFile('fr')['Read more'] ?? null, 'the comments\' Preact toggle');
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $patterns  locale => what the texts may not say
     * @return list<string>
     */
    private function keysSaying(array $keys, array $patterns): array
    {
        $wrong = [];
        foreach ($patterns as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = (string) ($file[$key] ?? '');
                if ($value === '') {
                    $wrong[] = "{$locale} {$key}: missing";
                } elseif (preg_match($pattern, $value, $m)) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                }
            }
        }

        return $wrong;
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, string>  $patterns  locale => what each of the texts must say
     * @return list<string>
     */
    private function keysMissing(array $keys, array $patterns): array
    {
        $wrong = [];
        foreach ($patterns as $locale => $pattern) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = (string) ($file[$key] ?? '');
                if ($value === '' || preg_match($pattern, $value) !== 1) {
                    $wrong[] = "{$locale} {$key}: no {$pattern} in {$value}";
                }
            }
        }

        return $wrong;
    }

    public function test_the_video_page_options_name_the_player_up_next_and_views(): void
    {
        // R3X-04 (fifth pass): Chinese said the player 球员 (a ball player) in three Appearance > Video page texts
        $zh = $this->file('zh');
        foreach (['Wide player on top', 'Player with stats', 'The player opens wide.'] as $key) {
            $this->assertStringContainsString('播放器', (string) ($zh[$key] ?? ''), $key);
        }
        // the help read as a mouth or a door opening wide (zh 张开大嘴, ru широко, ko 활짝, th เปิดกว้าง; ms "opens something")
        $this->assertSame([], $this->keysSaying(['The player opens wide.'],
            ['zh' => '/张开|大嘴/u', 'ru' => '/широко открыва/u', 'ko' => '/활짝/u', 'th' => '/เปิดกว้าง/u', 'ms' => '/membuka/u']));
        // the layout option names the "Up next" list with its heading's words (ko 위쪽 다음 = "above, next", ms "Atas seterusnya")
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            if (! str_contains((string) ($file['Player left, Up next right'] ?? ''), (string) ($file['Up next'] ?? "\0"))) {
                $wrong[] = "{$locale}: ".($file['Player left, Up next right'] ?? '-');
            }
        }
        $this->assertSame([], $wrong, 'the "Up next" heading\'s words');
        // the stats panel shows views (watch counts) and likes, not opinions, thoughts, a UI view or favourites
        $this->assertSame([], $this->keysSaying(['A side panel with views and likes.'], [
            'ar' => '/وجهات النظر/u', 'hi' => '/विचार/u', 'th' => '/มุมมอง/u', 'zh' => '/视图|喜好/u', 'ms' => '/pandangan/iu', 'id' => '/tampilan/iu',
            'tr' => '/görünüm/iu', 'de' => '/Ansicht/u', 'ja' => '/ビュー|お気に入り/u', 'fil' => '/(?<!\p{L})view(?!\p{L})/iu', 'tl' => '/(?<!\p{L})view(?!\p{L})/iu',
        ]));
    }

    public function test_names_of_things_are_not_read_in_another_sense(): void
    {
        $wrong = [];
        // chips: the small category / tag buttons, not microchips, wafers, slices, tokens, gambling chips or potato chips
        $chips = ['Category chips', 'Category Tabs', 'Category and tag chips', 'Metadata Chips', 'Title and chips', 'Two ranked lists, chips, then a grid.',
            'Cleaner rows of chips and videos; scrolling still works.', 'Player Speed Chips'];
        $wrong = array_merge($wrong, $this->keysSaying($chips, [
            'zh' => '/芯片|筹码|薯片/u', 'ar' => '/رقائق|شرائح/u', 'fr' => '/(?<!\p{L})(puces?|jetons?)(?!\p{L})/iu', 'es' => '/(?<!\p{L})fichas?(?!\p{L})/iu',
            'pt' => '/(?<!\p{L})fichas?(?!\p{L})/iu', 'ru' => '/чип|фишк/iu', 'tr' => '/çip/iu', 'hi' => '/चिप्स/u', 'ms' => '/(?<!\p{L})cip(?!\p{L})/iu',
        ]));
        // a kicker is a small line of text above a title: not a football kicker, not a drawn line
        $kickers = ['Kicker', 'Fallback Kicker', 'Intro Kicker', 'Hero Kicker', 'Small line above the title', 'Premium page: Small line above the title'];
        foreach (['popular tags row', 'creators row', 'trending actors row'] as $row) {
            $kickers[] = ucfirst($row).': small line above the title';
            $kickers[] = ucfirst($row).': small line when empty';
            $kickers[] = "The words shown for {$row}: small line above the title. Leave empty to use the default text (translated automatically).";
        }
        $football = '/踢球|キッカー|키커|كيكر|pateador|Kicker|Kickeur|Chutador|[Кк]икер|Vurucu|นักเตะ|คิกเกอร์|किकर|[Pp]enendang|[Pp]enyepak/u';
        $wrong = array_merge($wrong, $this->keysSaying($kickers, array_fill_keys(self::LOCALES, $football)));
        $wrong = array_merge($wrong, $this->keysSaying($kickers, [
            'th' => '/เส้น/u', 'tr' => '/çizgi/iu', 'id' => '/garis/iu', 'ms' => '/garis/iu', 'ja' => '/線/u', 'ko' => '/작은 선/u', 'zh' => '/小线/u',
            'hi' => '/रेखा/u', 'ar' => '/خط صغير/u', 'ru' => '/лини/iu',
        ]));
        // the top banner ("hero") is a banner: not a hero (a person, a champion), a music band, a rank or film subtitles
        $heroes = ['Homepage Hero', 'Hero', 'Hero Title', 'Hero Subtitle', 'Hero Kicker', 'Hero Showcase Layout', 'Hero Slider Autoplay', 'Page Hero Band',
            'Revert all site colors, hero sliders, layouts, and physics to original factory defaults with 1 click.',
            'Configure structural variations across the application: interactive hero sliders, multi-style video cards, cinema watch modes, and header architectures.',
            'Bento Grid Spotlight (1 Featured Hero + 3 Dynamic Tiles)', 'Netflix Cinematic Streaming Hub (Billboard Hero + Shelf Rows)',
            'YouTube Spotlight Feed (Large Hero + Quick Info)'];
        $person = '/英雄|ヒーロー|영웅|히어로|البطل|(?<!\p{L})بطل|héroe|(?<!\p{L})Held|héros|herói|[Гг]еро[йи]|[Kk]ahraman|ฮีโร่|anh hùng|हीरो|[Pp]ahlawan|(?<!\p{L})[Ww]ira(?!\p{L})|[Bb]ayani|(?<!\p{L})[Hh]ero(?!\p{L})/u';
        $wrong = array_merge($wrong, $this->keysSaying($heroes, array_fill_keys(self::LOCALES, $person)));
        $bands = ['Page Hero Band', 'Title band on info pages', 'A coloured band with the page title.', 'Header band', ':band band'];
        $wrong = array_merge($wrong, $this->keysSaying($bands, [
            'zh' => '/乐队/u', 'vi' => '/ban nhạc/iu', 'ja' => '/バンド/u', 'ko' => '/밴드/u', 'hi' => '/बैंड/u', 'ar' => '/فرقة/u', 'ru' => '/[Гг]рупп/u',
            'ms' => '/(?<![:\p{L}])Band(?!\p{L})/iu', 'id' => '/(?<![:\p{L}])Band(?!\p{L})/u', 'de' => '/Titelband/u', 'fil' => '/(?<!\p{L})banda(?!\p{L})/iu',
            'tl' => '/(?<!\p{L})banda(?!\p{L})/iu',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Hero Title', 'Premium page: Title'], [
            'zh' => '/称号|头衔/u', 'ja' => '/称号/u', 'ko' => '/칭호/u', 'vi' => '/danh hiệu/iu', 'ru' => '/[Зз]вани/u', 'tr' => '/[Uu]nvan/u', 'ar' => '/لقب/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Hero Subtitle', 'Premium page: Subtitle'], [
            'zh' => '/字幕/u', 'ja' => '/字幕/u', 'ko' => '/자막/u', 'ms' => '/[Ss]arikata/u', 'tr' => '/[Aa]ltyaz/u', 'vi' => '/phụ đề/iu', 'th' => '/คำบรรยาย/u',
        ]));
        // the banner's slides change by themselves: not playground slides, not a banner that spins
        $wrong = array_merge($wrong, $this->keysSaying(['Large slides that change by themselves.'],
            ['es' => '/tobog/iu', 'fr' => '/tobogg/iu', 'ja' => '/滑り台/u']));
        $wrong = array_merge($wrong, $this->keysSaying(['Rotate the top banner', 'Hero Slider Autoplay'], [
            'de' => '/[Dd]reh/u', 'es' => '/[Gg]irar/u', 'fr' => '/pivoter/iu', 'pt' => '/[Gg]ire/u', 'ja' => '/回転/u', 'ko' => '/회전/u', 'zh' => '/旋转/u',
            'ru' => '/[Пп]оворот/u', 'th' => '/หมุน/u', 'tr' => '/döndür/u', 'ar' => '/تدوير/u', 'hi' => '/घुमा/u', 'id' => '/[Pp]utar/u', 'ms' => '/[Pp]utar/u',
            'fil' => '/rotate/iu', 'tl' => '/rotate/iu',
        ]));
        // the public channel page's verified badge: a creator, not God, not a woman
        $wrong = array_merge($wrong, $this->keysSaying(['Creator'], [
            'ar' => '/الخالق/u', 'ko' => '/창조자/u', 'de' => '/Schöpfer/u', 'es' => '/Creadora/u', 'fr' => '/Créatrice/u', 'pt' => '/Criadora/u',
        ]));
        // to seed is to fill in starting values: not plant seeds; the import's counters are counters, not shop counters
        $seeds = ['Only seed empty counters', 'Seed daily analytics', 'Seed Brand Color:'];
        $wrong = array_merge($wrong, $this->keysSaying($seeds, [
            'zh' => '/种子|播种/u', 'vi' => '/hạt giống/iu', 'th' => '/เมล็ด/u', 'ar' => '/بذ/u', 'ru' => '/сем[яе]н/iu', 'de' => '/Samen/u', 'es' => '/semilla|sembrar/iu',
            'fr' => '/graine|semence/iu', 'pt' => '/semente|semear/iu', 'id' => '/benih/iu', 'ms' => '/benih/iu', 'tr' => '/tohum/iu', 'hi' => '/बीज/u',
            'fil' => '/binhi|buto/iu', 'tl' => '/binhi|buto/iu', 'ja' => '/シード/u', 'ko' => '/시드|종자/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Only seed empty counters', 'Counter only', '"Counter only" keeps totals and saves the most space.',
            'Public totals come from the video counter. Recent rows below are raw history only and may be empty in counter-only mode.'], [
            'zh' => '/柜台/u', 'vi' => '/quầy|truy cập/iu', 'es' => '/mostrador/iu', 'th' => '/เคาน์เตอร์/u', 'ms' => '/kaunter|balas/iu', 'id' => '/kontra/iu',
        ]));
        $this->assertSame([], $wrong, 'R3X-06: the same kinds (fifth pass)');
        $this->assertSame($this->file('ja')['Small line above the title'] ?? null, $this->file('ja')['Kicker'] ?? '-', 'the old page\'s rows say the new admin\'s kicker words');
    }

    public function test_the_homepage_rows_have_one_name_each(): void
    {
        // Appearance > Homepage: every label of the rows, row titles and row videos cards starts with its row's name, and the
        // rows are not a quarrel, a scandal or a dispute (ru), not English (fil / tl), not a column (ja 列); "counts the
        // last" is a span of days, not "the last one"
        $labels = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => (bool) preg_match('/^(Popular tags row|Creators row|Trending actors row): /', $key)));
        $this->assertCount(36, $labels);
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach ($labels as $key) {
                $name = (string) ($file[substr($key, 0, (int) strpos($key, ':'))] ?? '');
                if ($name === '' || ! str_starts_with((string) ($file[$key] ?? ''), $name)) {
                    $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-')." (the row: {$name})";
                }
            }
        }
        $this->assertSame([], $wrong, 'one name per row');
        $family = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => (bool) preg_match('/popular tags row|creators row|trending actors row/i', $key)));
        $this->assertGreaterThan(60, count($family));
        $this->assertSame([], $this->keysSaying($family, [
            'ru' => '/[Сс]сор|[Сс]кандал|[Сс]пор(?!т)/u', 'fil' => '/(?<!\p{L})row(?!\p{L})/iu', 'tl' => '/(?<!\p{L})row(?!\p{L})/iu', 'ja' => '/ロウ|行の列|演出|最後の(タグ|行|俳優)/u',
            'ko' => '/Creators|크리에이터스/u', 'zh' => '/演员排|最后一个/u', 'th' => '/นับคนสุดท้าย|นับสุดท้าย|นับครั้งสุดท้าย/u', 'tr' => '/sonuncuyu/u', 'ar' => '/يحسب الأخير/u',
        ]));
    }

    public function test_view_counts_are_views_not_opinions_or_looking(): void
    {
        // Settings > Video & views, the Views pages, the imports' starter views and the old Video & views tab: "view" as an
        // opinion, a perspective, a display, a field of vision or the verb "look at"; "view inflation" as the economy
        $keys = ['Count a repeat view after', 'Minutes before the same visitor watching again adds another view.', 'View records to keep', 'Daily view totals',
            'Keep detailed view records for', 'Views and date on related videos', 'Shows views and upload date under each related video.', 'Views counted per minute',
            'How many video views from one visitor count per minute. Stops view inflation.', 'Views & searches',
            'View counting, data clean-up, creator studio helpers and length limits.', 'View counting', 'How views are counted and how long the detailed records are kept.',
            'Metadata & Starter Views', 'Control imported titles, creator assignment, and optional starter view counters.', 'Starter Views',
            'Adds starter public view counts during import without creating raw view-history rows.', 'View range', 'Recommended to avoid adding views again on duplicate imports.',
            'Apply to existing zero-view AVDB videos', 'Views', 'Total Views', 'Actors with the most recent views are shown first.', 'Counts actor views inside this recent window.',
            'Video View Tracking & Analytics Retention', 'Fine-tune how video views are registered, cooldown timers against view botting, and retention cleanup jobs.',
            'View Cooldown (Minutes)', 'Minutes before repeat views from the same IP/user increment the count.', 'Raw View History Mode',
            'Counter only keeps totals without saving every single raw view row.', 'Raw View Retention Days', 'Scheduled cleanup deletes older raw view rows while video totals stay intact.',
            'Daily View Aggregates', 'Save Comment & View Settings', 'Video View Rate Limit', '1 view|:count views', 'No views', 'Clear ALL view history?', 'Recent Views', 'No view data',
            'Clear the view history?', 'Latest views', 'No views in the history', 'Starter Views Mode', 'A side panel with views and likes.', 'No recent views', 'No recent views: :title',
            'Analytics > Views Analytics', 'Views Analytics'];
        $this->assertSame([], $this->keysSaying($keys, [
            'de' => '/Ansicht|Sehen Sie|Inflation/u', 'hi' => '/विचार|मुद्रास्फीति|गिनती देखें/u', 'id' => '/[Tt]ampilan|^Lihat|inflasi/u', 'ms' => '/[Pp]aparan|[Pp]andangan|^Lihat|inflasi/u',
            'ja' => '/ビュー|インフレ|表示範囲/u', 'ko' => '/보기 (기록|인플|횟수|분석)|시야|시작 보기|^보기/u', 'tr' => '/[Gg]örünüm|[Ee]nflasyon|görüntüle(yin)?$|^Sayımı/u',
            'zh' => '/视图|视野|浏览|查看/u', 'ar' => '/وجهات|طرق العرض|التضخم|عرض النطاق/u', 'ru' => '/представлени|инфляц|^Посмотреть/iu', 'th' => '/มุมมอง|เงินเฟ้อ|ประวัติศาสตร์|^ดู/u',
            'vi' => '/người mới|chế độ xem|giữ chân/iu', 'fr' => '/inflation|^Voir|^Afficher|d\'affichage/u', 'pt' => '/infla(ç|c)ão|^Veja|^Ver |^Conte /u',
            'es' => '/^Ver |Sin ver/u', 'fil' => '/[Pp]ananaw|inflation|^Tingnan/u', 'tl' => '/[Pp]ananaw|inflation|^Tingnan/u',
        ]));
        // the cron page's button shows the failed jobs (a verb): it read "the view failed"
        $this->assertSame(['Fehlgeschlagene anzeigen', 'Ver fallidos', 'Voir les échecs', '失敗したジョブを表示', '실패한 작업 보기', '查看失败的任务'],
            [$this->file('de')['View Failed'] ?? null, $this->file('es')['View Failed'] ?? null, $this->file('fr')['View Failed'] ?? null,
                $this->file('ja')['View Failed'] ?? null, $this->file('ko')['View Failed'] ?? null, $this->file('zh')['View Failed'] ?? null]);
    }

    public function test_english_off_and_on_are_not_left_in_translations(): void
    {
        // R3X-06 (b) kind: the switches show no On / Off word; each language says "when it is off" with its own words
        $keys = [];
        foreach (array_keys($this->required()) as $key) {
            if (preg_match('/(?<![A-Za-z])(Off|On)(?![A-Za-z])/', (string) $key)) {
                $keys[] = (string) $key;
            }
        }
        $this->assertGreaterThan(25, count($keys));
        $this->assertSame([], $this->keysSaying($keys, array_fill_keys(self::LOCALES, '/(?<![A-Za-z])(Off|On)(?![A-Za-z])/u')), 'English Off / On');
        // ... and says it the right way round: hi "your own layout / your site's design stays off", a distribution channel
        $daily = 'With "Theme too", the homepage layout changes with the daily theme. Off keeps your own layout every day.';
        $this->assertStringNotContainsString('लेआउट बंद रहता', (string) ($this->file('hi')[$daily] ?? ''));
        $channel = array_values(array_filter($keys, fn (string $key): bool => str_ends_with($key, 'Off keeps your site design on every channel.')));
        $this->assertCount(25, $channel);
        $this->assertSame([], $this->keysSaying($channel, ['hi' => '/डिज़ाइन बंद/u', 'th' => '/ช่องทาง/u', 'zh' => '/渠道/u', 'ja' => '/チャネル/u',
            'ar' => '/لهجة|اللهجة/u', 'pt' => '/sotaque/u', 'ms' => '/loghat/u', 'tr' => '/aksan/u', 'es' => '/el acento/u']));
    }

    public function test_the_arabic_form_for_two_is_the_dual(): void
    {
        app()->setLocale('ar');
        $this->assertSame(['لا مشاهدات', 'مشاهدة واحدة', 'مشاهدتان', '5 مشاهدات', '15 مشاهدة'],
            array_map(fn (int $n): string => trans_choice('1 view|:count views', $n, ['count' => $n]), [0, 1, 2, 5, 15]), 'TL-R2: "2 وجهات النظر"');
        $this->assertSame('فيديوان في هذه الفئة.', trans_choice('1 video in this category.|:count videos in this category.', 2, ['count' => 2]), '"2 فيديو"');
        // the studio's comments line: the 7-day filter read "7 يومًا"
        $this->assertSame('5 تعليقات خلال الأيام الـ7 الأخيرة',
            trans_choice(':count comment in the last :days days|:count comments in the last :days days', 5, ['count' => 5, 'days' => 7]));
        // every Arabic count line says two with the dual (or the number in words); the digit stays only where no noun is counted
        $alone = ['1 today.|:count today.', '1 was asked for in the last hour.|:count were asked for in the last hour.',
            '{0} Your subscriptions|{1} Your :count subscriber and your subscriptions|[2,*] Your :count subscribers and your subscriptions'];
        $wrong = [];
        foreach ($this->required() as $key => $meta) {
            if (in_array($key, $alone, true) || ! \Avd\I18n\Plurals::counted((string) $meta['source'])) {
                continue;
            }
            $two = trans_choice((string) $key, 2, ['count' => 2]);
            if (preg_match('/(?<![\d.,(])2(?![\d.,)])/u', $two)) {
                $wrong[] = "{$key}: {$two}";
            }
        }
        $this->assertSame([], $wrong, 'Arabic says two with the dual');
    }

    public function test_technical_words_keep_their_technical_sense(): void
    {
        // R3X-06's kind past the verifier's rows (fifth pass look-around): the old settings page's System tab and the admin's
        // system texts said a route as a road (zh 卡什路 "Kashi Road"), Blade as a knife, a driver as a chauffeur, "Present"
        // (the storage link exists) as a gift or the present time, fields as farmland, the server environment as nature and
        // Laravel's event broadcasting as radio
        $wrong = $this->keysSaying(['Route Cache', 'Routes Cached', 'Routes'], [
            'ar' => '/طريق|الطرق/u', 'de' => '/Road/u', 'es' => '/[Cc]amino/u', 'fr' => '/[Ii]tinéraire/u', 'hi' => '/रोड|आरएसएस/u', 'id' => '/[Jj]alan/u',
            'ms' => '/[Jj]alan/u', 'ja' => '/キャッシュロード/u', 'ko' => '/캐시 로드|노선/u', 'pt' => '/[Ee]strada/u', 'ru' => '/роуд|[Дд]орог/u', 'th' => '/ถนน/u',
            'vi' => '/Đường Cache/u', 'zh' => '/卡什|路线/u', 'fil' => '/Road/u', 'tl' => '/Road/u', 'tr' => '/Yolu/u',
        ]);
        foreach (self::LOCALES as $locale) {
            foreach (['Blade View Cache', 'Compiled Blade views cache successfully cleared.'] as $key) {
                if (! str_contains((string) ($this->file($locale)[$key] ?? ''), 'Blade')) {
                    $wrong[] = "{$locale} {$key}: ".($this->file($locale)[$key] ?? '-');
                }
            }
        }
        $wrong = array_merge($wrong, $this->keysSaying(['Compiled Views', 'Blade View Cache'], [
            'ar' => '/وجهات النظر|شفرة/u', 'vi' => '/[Ll]ượt xem/u', 'ms' => '/[Pp]andangan/u', 'zh' => '/刀片/u', 'fr' => '/lame/u', 'pt' => '/lâmina/u', 'ru' => '/клинк/u',
            'id' => '/[Pp]isau/u', 'es' => '/hoja/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Driver', 'Queue driver', 'Search Driver', 'Session driver is'], [
            'ar' => '/سائق/u', 'es' => '/[Cc]onductor/u', 'fr' => '/[Cc]onductri/u', 'hi' => '/चालक/u', 'id' => '/[Pp]engemudi|[Pp]enggerak/u', 'ko' => '/운전/u',
            'ms' => '/[Pp]emandu/u', 'pt' => '/[Mm]otorista/u', 'ru' => '/[Вв]одител|^Поиск/u', 'th' => '/คนขับ|พนักงานขับรถ/u', 'vi' => '/[Tt]ài xế|^Tìm kiếm/u', 'zh' => '/司机/u',
            'fil' => '/[Tt]super|^Maghanap/u', 'tl' => '/[Tt]super|^Maghanap/u', 'de' => '/suchen$/u', 'ja' => '/を検索/u', 'tr' => '/Ara$/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Present'], [
            'ja' => '/プレゼント/u', 'zh' => '/展示|礼物/u', 'ar' => '/الحاضر/u', 'ru' => '/[Нн]астоящ/u', 'hi' => '/वर्तमान/u', 'ko' => '/현재/u', 'th' => '/ปัจจุบัน/u',
            'vi' => '/hiện tại/iu', 'de' => '/[Aa]nwesend/u', 'id' => '/[Hh]adir/u', 'ms' => '/[Hh]adir/u', 'fil' => '/^Present$/u', 'tl' => '/^Present$/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['fields', 'groups'], ['hi' => '/खेत/u', 'ms' => '/padang/iu', 'th' => '/สาขา/u', 'vi' => '/lĩnh vực/iu', 'zh' => '/领域|团体/u']));
        $wrong = array_merge($wrong, $this->keysSaying(['Broadcasting'], [
            'de' => '/Rundfunk/u', 'es' => '/Radiodifus/u', 'fr' => '/Radiodiffusion/u', 'pt' => '/Radiodifus/u', 'th' => '/แพร่ภาพ|กระจายเสียง/u', 'tr' => '/Yayıncılık/u',
            'ja' => '/^放送$/u', 'ko' => '/^방송$/u', 'zh' => '/^广播$/u', 'ru' => '/^Вещание$/u', 'fil' => '/^Broadcasting$/u', 'tl' => '/^Broadcasting$/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Environment & Services Overview', 'Environment detection refreshed.',
            'Only settings that are wired to the application are editable here. Environment secrets stay in Env Editor.'], [
            'de' => '/Umwelt/u', 'es' => '/medio ambiente/iu', 'pt' => '/[Mm]eio [Aa]mbiente/u', 'ms' => '/[Aa]lam [Ss]ekitar/u', 'ru' => '/окружающ/u', 'hi' => '/पर्यावरण/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Production Environment'], ['ja' => '/生産/u', 'ko' => '/생산/u']));
        $wrong = array_merge($wrong, $this->keysSaying(['Config Cache', 'Config Cached'], [
            'fil' => '/^I-configure/u', 'tl' => '/^I-configure/u', 'th' => '/^กำหนดค่า/u', 'tr' => '/Önbelleği Yapılandırma/u', 'ar' => '/مخبأة|ذاكرة التخزين المؤقت التكوين/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Backfill mode', 'AVDB Duration Backfill'], [
            'ar' => '/الردم|إعادة ملء/u', 'ru' => '/засыпк/u', 'vi' => '/chèn lấp/iu', 'th' => '/โฆษณา|ทดแทน/u', 'es' => '/[Rr]eabastecimiento/u',
        ]));
        // an API / reset / template token is not a gambling chip, a coin or a game token
        $tokens = ['Insert Token:', 'Tokens:', 'Total Tokens', 'Token Name', 'Token Preview', 'Revoke ALL tokens? This will log out all API users.', 'Clear ALL pending reset tokens?'];
        $wrong = array_merge($wrong, $this->keysSaying($tokens, ['zh' => '/代币/u', 'es' => '/[Ff]icha/u', 'pt' => '/[Ff]icha/u', 'ru' => '/[Жж]етон/u']));
        $this->assertSame([], $wrong, 'technical words in their technical sense (fifth pass look-around)');
        // Blade is protected in its two keys like Laravel's other names (scripts/i18n/brands.json "keys"; i18n_check.php reads it)
        $brands = json_decode((string) file_get_contents(base_path('scripts/i18n/brands.json')), true);
        $this->assertSame(['Blade'], $brands['keys']['Blade View Cache'] ?? null);
        $this->assertSame(['Blade'], $brands['keys']['Compiled Blade views cache successfully cleared.'] ?? null);
    }

    public function test_queue_jobs_and_workers_are_not_employment_or_labourers(): void
    {
        // a queue job is not a job vacancy, an occupation or a workplace; a queue worker is not a labourer or an employee
        // (each language keeps the words its reviewed queue texts use: ar عامل / مهام, id / ms pekerja, tr işçi, fil / tl trabaho)
        $keys = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => (bool) preg_match('/\b(jobs?|workers?)\b/i', $key)));
        $this->assertGreaterThan(100, count($keys));
        $this->assertSame([], $this->keysSaying($keys, [
            'ar' => '/وظائف|وظيفة|الوظائف|الوظيفة|نقطة تفتيش|للعاملين/u', 'de' => '/Arbeiter|Arbeitnehmer|Arbeitsplätze|^Arbeit$/u', 'es' => '/trabajador|empleo/iu',
            'fr' => '/travailleur|(?<!\p{L})emplois?(?!\p{L})|^Travail|^Travaux|(?<!\p{L})travaux(?!\p{L})/iu', 'pt' => '/trabalhador|(?<!\p{L})empregos?(?!\p{L})/iu',
            'hi' => '/नौकर|कार्यकर्ता|श्रमिक/u', 'ja' => '/仕事/u', 'ko' => '/직업|채용/u', 'ru' => '/[Вв]аканси|работник|рабочих|[Рр]абочий|рабочие места|провал|Джобс/u',
            'th' => '/คนงาน|พนักงาน|ผู้ปฏิบัติงาน|แม็กซ์จ็อบส์/u', 'vi' => '/nhân viên|công nhân|việc làm/iu', 'zh' => '/工人|工作人员|职位|岗位/u',
            'tr' => '/(?<!\p{L})ilan/u', 'id' => '/pos pemeriksaan/iu', 'ms' => '/pusat pemeriksaan|(?<!\p{L})[Pp]ekerjaan(?!\p{L})/u',
            'fil' => '/[Mm]anggagawa|trabahador/u', 'tl' => '/[Mm]anggagawa|trabahador/u',
        ]), 'a queue job is not a job vacancy and a worker is not a labourer');
        // keeping an eye on a sync is not watching it like a show; a video's duration is not a period (ja 期間, ko 기간)
        $this->assertSame([], $this->keysSaying(['Stopped watching duration sync. The background worker may still be running.',
            'Background duration sync is running. This page is only watching progress.'], [
            'es' => '/(?<!\p{L})ver(?!\p{L})|viendo/iu', 'fr' => '/regarder/iu', 'pt' => '/assistir|observando/iu', 'ru' => '/смотреть|наблюдает/iu', 'id' => '/menonton/iu',
            'ms' => '/menonton|melihat/iu', 'zh' => '/观看|查看/u', 'ja' => '/視聴/u', 'ko' => '/시청|보고 있습니다/u', 'th' => '/หยุดดู|ดูความคืบหน้า/u', 'hi' => '/देखना|देख रहा/u',
            'vi' => '/đang xem|thời lượng xem/iu', 'fil' => '/panonood|nanonood/iu', 'tl' => '/panonood|nanonood/iu',
        ]));
        $durations = array_values(array_filter(array_keys($this->required()), fn (string $key): bool => (bool) preg_match('/duration/i', $key)));
        $this->assertGreaterThan(20, count($durations));
        $this->assertSame([], $this->keysSaying($durations, ['ja' => '/期間/u', 'ko' => '/기간/u']), 'a video\'s duration');
        // the Horizon page's button says the menu's 'Cron / Jobs'
        foreach (self::LOCALES as $locale) {
            $this->assertStringContainsString((string) ($this->file($locale)['Cron / Jobs'] ?? "\0"), (string) ($this->file($locale)['Open Cron / Jobs'] ?? ''), $locale);
        }
    }

    public function test_paths_files_and_setting_names_are_written_as_they_are(): void
    {
        // R3X-06's kind past the fifth pass (p53): a folder, a file, a php.ini setting or an API field the owner has to find
        // or type was said word by word (es "almacenamiento/aplicación/privado", tr "depolama/uygulama/özel"), re-cased (ko /
        // ru "Storage/app": another folder on Linux; ar / ru / th "Disable_functions"; ko "Poster_url"), turned into its
        // opposite (es "enable_functions") or transliterated (hi "पोस्टर_यूआरएल", "क्यू-वर्कर.लॉग"). In every text the sources
        // print, a path under Laravel's top folders, a snake_case or UPPER_SNAKE name and a file name stay as the English
        // writes them.
        $token = '~(?<![\w/.-])(?:(?:storage|public|bootstrap|resources|app|config|database|vendor|routes|lang)/(?:[\w.*-]+/)*[\w.*-]*'
            .'|[a-z][a-z0-9]*(?:_[a-z0-9]+)+|[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+'
            .'|[\w-]+\.(?:log|txt|json|php|env|ini|m3u8|sql|zip|htaccess|conf|xml|yml|yaml|sh|lock))(?![\w/])~u';
        $wrong = [];
        $checked = 0;
        foreach (self::LOCALES as $locale) {
            foreach ($this->texts($locale) as $key => $value) {
                $english = str_starts_with((string) $key, 'js: ') ? substr((string) $key, 4) : (string) $key;
                if ($value === $english || ! preg_match_all($token, $english, $found)) {
                    continue;
                }
                foreach (array_unique($found[0]) as $name) {
                    $name = rtrim($name, '.');
                    if (strlen($name) < 4) {
                        continue;
                    }
                    $checked++;
                    if (! str_contains($value, $name)) {
                        $wrong[] = "{$locale} {$key}: \"{$name}\" not in {$value}";
                    }
                }
            }
        }
        $this->assertGreaterThan(500, $checked, 'the sweep reads the technical names of the texts');
        $prefix = function (string $start): string {
            $hits = array_values(array_filter(array_keys($this->required()), fn ($key): bool => str_starts_with((string) $key, $start)));
            $this->assertCount(1, $hits, $start);

            return (string) $hits[0];
        };
        $avatars = $prefix('On = assign imported videos to director, first actor, or a stable tag creator.');
        $thumbs = ['Remote = uses poster_url from upload18 CDN. Download = saves JPGs locally but uses disk space.',
            $prefix('Remote = uses poster_url from upload18 CDN, zero disk usage.')];
        // the folder backups/ too, and the words around these names in their own sense: the option "Remote" is not a remote
        // control, a poster image is not a placard, the owner's own assignments are never reassigned (not "stolen"), spawning
        // a process is not laying eggs, breeding or an appearance, the option that downloads is not said with an upload word
        $wrong = array_merge($wrong, $this->keysSaying(['Could not create the backups/ directory under storage/app.'], array_fill_keys(self::LOCALES, '~^(?!.*backups/)~su')));
        $wrong = array_merge($wrong, $this->keysSaying($thumbs, ['ar' => '/التحكم عن بعد/u', 'fr' => '/^Remote =/u']));
        $wrong = array_merge($wrong, $this->keysSaying([$thumbs[1]], ['id' => '/^Remote =/u', 'th' => '/^Remote =/u', 'vi' => '/^Remote =/u']));
        $wrong = array_merge($wrong, $this->keysSaying(['Use remote poster from API CDN (fast, no storage)', '🌐 Use remote poster from API CDN (fast, no storage)'], ['ru' => '/плакат/u']));
        $wrong = array_merge($wrong, $this->keysSaying(['Download to local server storage'], ['ar' => '/^تحميل/u']));
        $wrong = array_merge($wrong, $this->keysSaying([$avatars], [
            'ar' => '/سرقة/u', 'de' => '/gestohlen/u', 'es' => '/roban/u', 'fil' => '/nanakaw/u', 'tl' => '/nanakaw/u', 'fr' => '/volées/u', 'hi' => '/चोरी/u',
            'id' => '/dicuri/u', 'ms' => '/dicuri/u', 'ja' => '/盗/u', 'ko' => '/도난/u', 'pt' => '/roubad/u', 'ru' => '/украд/u', 'th' => '/ขโมย/u', 'tr' => '/çalın/u',
            'vi' => '/đánh cắp/u', 'zh' => '/被盗/u',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Enabled — backups, ffmpeg, and AVDB inline spawn are all usable.'], [
            'th' => '/วางไข่/u', 'ms' => '/pembiakan/iu', 'fr' => '/apparition/iu',
        ]));
        $wrong = array_merge($wrong, $this->keysSaying(['Disabled — disable_functions blocks proc_open. AVDB imports will run through the queue; backups + ffmpeg will need an alternative.'], [
            'id' => '/^Disabled/u', 'es' => '/backups/u', 'th' => '/backups/u', 'tr' => '/backups/u',
        ]));
        $this->assertSame([], $wrong, 'technical names written as they are (fifth pass, p53)');
    }

    public function test_the_accent_colour_is_a_colour_not_a_way_of_speaking(): void
    {
        // TL-VERIFY4-1 (sixth pass): the accent colour said as a speech accent, a dialect or a pronunciation (ar لهجة / لكنة, hi
        // उच्चारण, ms loghat, pt sotaque, th สำเนียง, vi giọng, zh 口音), "Comp" or "Accent Hex" left in English or transliterated, a
        // finger ring for the ring around a picture. Every text whose English names the accent colour (the decorative "bronze
        // accents" of a theme's tagline aside) is read in every language.
        $keys = array_values(array_filter(array_keys($this->required()), fn ($key): bool => (bool) preg_match('/accent/i', (string) $key)
            && ! str_contains((string) $key, 'bronze accents')));
        $this->assertGreaterThan(15, count($keys));
        $this->assertSame([], $this->keysSaying($keys, [
            'ar' => '/لهجة|لكنة|شركات/u', 'hi' => '/उच्चारण|लहजा|लहजे|कॉम्प/u', 'ms' => '/[Ll]oghat|Comp|Cincin|pepejal/u', 'pt' => '/sotaque|Comp/u',
            'th' => '/สำเนียง|คอมพ์|Accent/u', 'vi' => '/giọng|Comp|Accent/u', 'zh' => '/口音|比较/u', 'id' => '/logat|Komp|Aksen Hex|Cincin/u',
            'ko' => '/억양|사투리|악센트|액센트|콤프/u', 'ja' => '/訛|コンプ/u', 'ru' => '/Комп|[Аа]кцент(?!н)/u', 'de' => '/Dialekt|Comp|Accent/u',
            'es' => '/(?<!de )acento|compensación/u', 'fr' => '/accent(?!uation)|Bague/iu', 'tr' => '/aksan|Comp|Accent|Yüzük|Katı/u',
            'fil' => '/[Kk]ulay-diin|Singsing|Accent (Glow|Color|Hex)/u', 'tl' => '/[Kk]ulay-diin|Singsing|Accent (Glow|Color|Hex)/u',
        ]), 'the accent colour, in every language');

        // one term per language: the one Appearance > Creator channels uses since the fifth pass, also on the member's daily
        // design sheet ('Accent colour' with 'Your accent colour') and the old settings page's palette
        $term = ['ar' => 'تمييز', 'de' => 'Akzentfarbe', 'es' => 'color de acento', 'fil' => 'accent', 'tl' => 'accent', 'fr' => "couleur d'accentuation",
            'hi' => 'एक्सेंट रंग', 'id' => 'warna aksen', 'ja' => 'アクセントカラー', 'ko' => '강조 색상', 'ms' => 'warna aksen', 'pt' => 'cor de destaque',
            'ru' => 'акцентный цвет', 'th' => 'สีเน้น', 'tr' => 'vurgu rengi', 'vi' => 'màu nhấn', 'zh' => '强调色'];
        // the own-accent switch by its catalogue label: lane AD renames it ('Creators can change their own accent colour')
        // and this lane gives the new key the same words (part p60y), so the check reads whichever label the tree prints
        $ownSwitch = (string) config('admin_settings.settings.channel_customization_custom_accent_enabled.label');
        $named = ['Accent colour', 'Own accent colour', 'Your accent colour', 'Creators can change the accent colour', $ownSwitch,
            'Lets creators change the accent of their own channel page. Off keeps your site design on every channel.',
            'Lets creators change the custom accent of their own channel page. Off keeps your site design on every channel.', 'Accent Color', 'Comp Accent',
            'Brand Accent'];
        $wrong = [];
        foreach ($term as $locale => $word) {
            $file = $this->file($locale);
            foreach ($named as $key) {
                if (mb_stripos((string) ($file[$key] ?? ''), $word) === false) {
                    $wrong[] = "{$locale} {$key}: \"{$word}\" not in ".($file[$key] ?? '-');
                }
            }
            // the old settings page's labels of the same settings take the new admin's words (printed only there)
            foreach (['Accent Choices' => 'Creators can change the accent colour', 'Custom Accent Color' => $ownSwitch,
                'Banner Treatments' => 'Creators can change the banner style', 'Default Custom Accent' => 'Own accent colour'] as $old => $new) {
                if (($file[$old] ?? null) !== ($file[$new] ?? '-')) {
                    $wrong[] = "{$locale} {$old}: ".($file[$old] ?? '-')." (the new admin: {$file[$new]})";
                }
            }
        }
        $this->assertSame([], $wrong, 'one accent colour term per language');
        $this->assertStringNotContainsString('लहजे', (string) ($this->file('hi')['Gallery opening in daylight: ivory walls, espresso ink, bronze accents and framed prints.'] ?? ''));
        $this->assertSame([], $this->keysSaying(['Solid Accent Ring', 'Luminescent Glow Ring', 'Accent ring'], [
            'fr' => '/Bague/u', 'id' => '/Cincin/u', 'ms' => '/Cincin/u', 'fil' => '/Singsing/u', 'tl' => '/Singsing/u', 'tr' => '/Yüzük/u',
        ]), 'a ring around the picture, not a finger ring');
    }

    public function test_the_creator_channels_page_and_the_old_channel_section_name_each_thing_one_way(): void
    {
        // TL-VERIFY4-1's page (sixth pass): Appearance > Creator channels. The help under every "Default channel look" select
        // said "channels that have not chosen their own channel", and ja / th a distribution channel
        $this->assertSame([], $this->keysSaying(['Used on channels that have not chosen their own.'], [
            'ar' => '/قنواتها|لم تختار/u', 'fil' => '/sarili nilang channel/u', 'tl' => '/sarili nilang channel/u', 'hi' => '/अपना चैनल नहीं/u',
            'id' => '/salurannya sendiri/u', 'ko' => '/자신의 채널을/u', 'ms' => '/saluran mereka sendiri/u', 'zh' => '/自己的频道/u', 'ja' => '/チャネル/u',
        ]), '"their own", not "their own channel"');
        $this->assertSame([], $this->keysSaying(['Used by channels that have not designed their own page.', 'Channel customization'],
            ['ja' => '/チャネル/u', 'th' => '/ช่องทาง/u', 'zh' => '/渠道/u']));

        // one word for the creators on the page (and the old page's section), "may change" as "is allowed to"
        $page = ['Creator channels', 'How channel pages look and what creators may change.', 'What creators may change', 'Creator badge',
            'Let creators design their own channel page, within limits you set.', 'Creators can design their channel page',
            'Lets creators change the look of their own channel. Choose below what they may change.', 'Creators can show "Last upload"',
            'Lets creators show "Last upload: today" on their channel page. Off hides it on every channel.',
            'Control which channel design choices creators can use and the default look for new or uncustomized channels.'];
        $this->assertSame([], $this->keysSaying($page, [
            'ar' => '/المبدع|منشئ/u', 'fil' => '/tagalikha/iu', 'tl' => '/tagalikha/iu', 'hi' => '/निर्माता|क्रिएटर/u', 'id' => '/kreator|(?<!\p{L})mungkin(?!\p{L})/iu',
            'ms' => '/(?<!\p{L})mungkin(?!\p{L})/iu', 'ko' => '/제작자/u', 'ru' => '/создател/iu', 'th' => '/ครีเอเตอร์|อาจ/u', 'tr' => '/oluşturucu|yaratıcı/iu',
            'zh' => '/创建者|创造者|可能会/u', 'vi' => '/người tạo/iu', 'ja' => '/変更される/u',
        ]), 'one creator word');

        // the "Default channel look" selects name each thing with the creator switches' words, in its own sense: the avatar's
        // frame is not a framed picture, the featured section's layout is not a selected / recommended layout and its items
        // are not shop articles
        $this->assertSame([], $this->keysSaying(['Picture frame'], ['ja' => '/額縁/u', 'ko' => '/액자/u', 'zh' => '/镜框/u', 'pt' => '/Porta-retratos/u', 'de' => '/Bilderrahmen/u']));
        $this->assertSame([], $this->keysSaying(['Featured layout', 'Featured items per row', 'Featured rows'], [
            'de' => '/Ausgewählt|Artikel/u', 'ru' => '/Рекомендуем|Избранн/u', 'es' => '/Artículos|^Diseño destacado$|^Filas destacadas$/u', 'fr' => '/Articles|vedette|Disposition/u',
            'hi' => '/विशेष रुप/u', 'zh' => '/特色/u', 'th' => '/เด่น/u', 'ms' => '/ditampilkan/u', 'ko' => '/주요/u',
        ]));
        $this->assertSame([], $this->keysSaying(['Layout'], ['ko' => '/공들여/u', 'es' => '/Disposición/u', 'pt' => '/Disposição/u', 'ms' => '/Susun atur/u']));
        $this->assertSame([], $this->keysSaying(['Video list layout', 'Featured layout', 'Playlists layout'], ['ms' => '/Susun atur/u', 'fr' => '/Disposition/u']));
        $this->assertSame([], $this->keysSaying(['Latest uploads rows'], ['ru' => '/загрузки строк/u']));
        $this->assertSame([], $this->keysSaying(['Latest uploads layout'], ['fil' => '/^Pinakabagong layout/u', 'tl' => '/^Pinakabagong layout/u']));
        $this->assertSame([], $this->keysSaying(['Statistics style'], ['es' => '/estadístico/u']));
        $this->assertSame([], $this->keysSaying(['Spacing'], ['fil' => '/^Spacing$/u', 'tl' => '/^Spacing$/u']));
        $this->assertSame([], $this->keysSaying(['A channel'], ['zh' => '/通道/u']));
        $this->assertSame([], $this->keysSaying(['Banner style'], ['ar' => '/بانر/u', 'hi' => '/शैली/u', 'fil' => '/Estilo/u', 'tl' => '/Estilo/u']));

        // the old settings page's channel section: its switches and its 'Default ...' labels say what the new admin says for
        // the same settings (each printed only by that page; test_an_old_label_... checks where they are printed)
        $view = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));
        $pairs = [];
        foreach ((array) config('admin_settings.settings') as $key => $setting) {
            $old = (string) ($setting['old'][1] ?? '');
            if ((str_starts_with((string) $key, 'channel_customization_') || (str_starts_with((string) $key, 'channel_default_') && str_starts_with($old, 'Default ')))
                && str_contains($view, "__('".$old."')")) {
                $pairs[$old] = (string) $setting['label'];
            }
        }
        $this->assertCount(33, $pairs, 'the 26 switches, Default Custom Accent and the six Default ... labels the old page prints');
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach ($pairs as $old => $new) {
                if (($file[$old] ?? null) !== ($file[$new] ?? '-')) {
                    $wrong[] = "{$locale} {$old}: ".($file[$old] ?? '-')." (the new admin: ".($file[$new] ?? '-').')';
                }
            }
        }
        $this->assertSame([], $wrong, 'the old channel section takes the new admin\'s words');

        // ... and its channel-default selects printed labels built from the setting keys, in English in every language
        // ("Channel Accent", "Channel Banner Treatment"): each prints its setting's own label from the catalogue
        $this->assertStringContainsString("config('admin_settings.settings.'.\$settingKey.'.label'", $view);
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        foreach (['de', 'zh', 'ar'] as $locale) {
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get(route('admin.settings.index'))->assertOk()->getContent();
            foreach (['Channel Layout', 'Channel Accent', 'Channel Banner Treatment', 'Channel Avatar Frame'] as $english) {
                $this->assertStringNotContainsString('>'.$english.'<', $html, "{$locale}: {$english}");
            }
            foreach (['channel_default_layout' => 'Layout', 'channel_default_accent' => 'Accent colour', 'channel_default_banner_treatment' => 'Banner style',
                'channel_default_avatar_frame' => 'Picture frame'] as $setting => $label) {
                $this->assertMatchesRegularExpression('#<label[^>]*for="'.$setting.'"[^>]*>'.preg_quote(e((string) $this->file($locale)[$label]), '#').'</label>#u', $html, "{$locale} {$setting}");
            }
        }
    }

    public function test_the_homepage_names_the_banner_slides_tiles_and_rows_one_way(): void
    {
        // TL-VERIFY4-2 (sixth pass): Appearance > Homepage named the top banner two or three ways (ar لافتة / راية / البانر, vi
        // banner / biểu ngữ, tr banner / afiş; the page description de Top-Banner, fil / tl tuktok, ms teratas)
        $banner = ['Homepage layout, top banner, welcome text and the rows of videos.', 'Banner, then a simple grid.', 'Big banner and rows you scroll sideways.',
            'Top banner', 'Sliding banner', 'No banner', 'Rotate the top banner', 'Extra rows between the banner and the main list.'];
        $this->assertSame([], $this->keysSaying($banner, ['ar' => '/راية|بانر/u', 'vi' => '/biểu ngữ/iu', 'tr' => '/afiş/iu', 'de' => '/Top-Banner/u',
            'fil' => '/tuktok na banner/u', 'tl' => '/tuktok na banner/u', 'ms' => '/teratas/u']), 'one word for the banner');
        // the slide interval, followed by a number of seconds: not "every other time", "every time", "every slide", the wrong
        // gender, an order, a page; a slide said one way (de Slides, vi slide)
        $this->assertSame([], $this->keysSaying(['Change slide every'], ['zh' => '/每隔一次/u', 'th' => '/ทุกครั้ง/u', 'tr' => '/her defasında/u', 'hi' => '/हर स्लाइड/u',
            'fr' => '/tous les$/u', 'ar' => '/^قم ب/u', 'de' => '/Folie|Sie/u', 'vi' => '/trang/u', 'ru' => '/Меняйте/u']));
        $top = 'What sits at the very top of the homepage: slides, a big video with tiles, ranked lists, a lead story or nothing.';
        $this->assertSame([], $this->keysSaying(['Slides change by themselves.', 'Large slides that change by themselves.', $top],
            ['de' => '/Folie/u', 'vi' => '/trình chiếu/u']));

        // the layout options' tiles one way per language, and the top banner's help names the lead story as its option does
        $tile = ['ar' => 'مربعات', 'de' => 'Boxen', 'es' => 'recuadros', 'fil' => 'kahon', 'tl' => 'kahon', 'fr' => 'vignettes', 'hi' => 'टाइल', 'id' => 'kotak',
            'ja' => 'ボックス', 'ko' => '상자', 'ms' => 'kotak', 'pt' => 'caixas', 'ru' => 'плитк', 'th' => 'กล่อง', 'tr' => 'kutu', 'vi' => 'ô', 'zh' => '方块'];
        $wrong = [];
        foreach ($tile as $locale => $word) {
            $file = $this->file($locale);
            foreach (['Video + 3 tiles', 'Mixed-size tiles, lots of space.', $top] as $key) {
                if (mb_stripos((string) ($file[$key] ?? ''), $word) === false) {
                    $wrong[] = "{$locale} {$key}: \"{$word}\" not in ".($file[$key] ?? '-');
                }
            }
            if (mb_stripos((string) ($file[$top] ?? ''), mb_strtolower((string) ($file['Lead story'] ?? "\0"))) === false) {
                $wrong[] = "{$locale} lead story: \"{$file['Lead story']}\" not in {$file[$top]}";
            }
        }
        $this->assertSame([], $wrong, 'one word for the tiles and the lead story');

        // the rows with the rows family's word (p50b: de Reihe, fr rangée, pt fileira, ru ряд, fil / tl hanay, ja 行, tr satır)
        $rows = ['Homepage layout, top banner, welcome text and the rows of videos.', 'Big banner and rows you scroll sideways.', 'Cinema rows', 'Homepage rows',
            'Extra rows between the banner and the main list.', 'A row of category buttons above the videos.', '"See all" links on rows',
            'Each homepage row gets a link to its full page.', 'Fill empty rows', 'When a row has nothing to show yet, show categories instead of an empty space.',
            'Row titles', 'The words above each row. Shown in the preview as you type.', 'Shown while the row has nothing to show yet.', 'Videos under rows',
            'Add a grid of videos under a row.', 'Adds a grid of videos below the row.', 'Videos per row in the main list', 'Row animation',
            'Rows scroll by themselves', 'Horizontal rows move slowly on their own.', 'Stop moving rows under the mouse', 'Self-scrolling rows pause when pointed at.',
            'How fast rows move on their own.', '"Tags" row moves', '"Creators" row moves', '"Actors" row moves', '"Fresh picks" moves',
            'Direction of the Fresh picks row animation.', 'Direction of the tags row animation.', 'Direction of the creators row animation.',
            'Direction of the trending actors row animation.'];
        $this->assertSame([], $this->keysSaying($rows, ['de' => '/Zeile/u', 'fr' => '/ligne/iu', 'pt' => '/linha/iu', 'ru' => '/[Сс]трок/u',
            'fil' => '/hilera|(?<!\p{L})row(?!\p{L})/iu', 'tl' => '/hilera|(?<!\p{L})row(?!\p{L})/iu', 'ja' => '/列/u', 'tr' => '/sıralar/u']), 'one word for the rows');

        // read in another sense: "most commented" as "most people commented", the actors' TV shows, creators who uploaded ON the
        // homepage, the welcome text's outline, a framework, the default list, a sea bass and a dead calm, a loading message,
        // creators as women or God, a row moved to another place, a talent show draft
        $this->assertSame([], $this->keysSaying(['Most commented'], ['fil' => '/Karamihan/u', 'tl' => '/Karamihan/u', 'hi' => '/अधिकांश/u', 'id' => '/Kebanyakan/u',
            'ms' => '/Kebanyakan/u', 'ja' => '/かった/u', 'th' => '/^แสดงความคิดเห็น/u']));
        $this->assertSame([], $this->keysSaying(['Most viewed', 'Most viewed and fastest rising.'], ['fil' => '/tinitingnan/iu', 'tl' => '/tinitingnan/iu']));
        $this->assertSame([], $this->keysSaying(['Newest, most viewed, trending, most commented, or one category.', 'Newest, most viewed, trending, most commented or one category.'],
            ['zh' => '/查看/u', 'ms' => '/mengulas/u']));
        $this->assertSame([], $this->keysSaying(['Shows actors viewers watch most on the homepage.'], ['fil' => '/palabas/iu', 'tl' => '/palabas/iu', 'hi' => '/शो/u',
            'ja' => '/番組/u', 'ms' => '/Rancangan/u', 'ru' => '/Шоу/u', 'ar' => '/يعرض المشاهدين/u']), 'the actors, not their TV shows');
        $this->assertSame([], $this->keysSaying(['Shows creators who uploaded recently on the homepage.'], ['fil' => '/tagalikha/u', 'tl' => '/tagalikha/u', 'tr' => '/yaratıcı/iu',
            'de' => '/auf der Startseite hochgeladen/u', 'es' => '/recientemente en la página de inicio/u', 'ja' => '/ホームページにアップロード/u',
            'ko' => '/홈페이지에 업로드/u', 'pt' => '/recentemente na página inicial/u', 'zh' => '/在主页上上传/u']), 'shown on the homepage, not uploaded to it');
        $this->assertSame([], $this->keysSaying(['The big line of the welcome text.'], ['id' => '/[Gg]aris besar/u', 'ms' => '/[Gg]aris besar/u', 'fil' => '/welcome text/u',
            'tl' => '/welcome text/u']));
        $this->assertSame([], $this->keysSaying(['Framed video with a stats panel.'], ['zh' => '/框架/u']));
        $this->assertSame([], $this->keysSaying(['Videos per row in the main list', 'Extra rows between the banner and the main list.'], ['ko' => '/기본 목록/u']));
        $this->assertSame([], $this->keysSaying(['Milliseconds. 700 is calm; lower is snappier.'], ['th' => '/ปลากะพง/u', 'ru' => '/штиль/u', 'fr' => '/le bas/u',
            'es' => '/más abajo/u', 'ja' => '/下のほう/u', 'ko' => '/아래쪽/u', 'tr' => '/alt kısım/u', 'hi' => '/निचला/u', 'fil' => '/snappier/u', 'tl' => '/snappier/u']));
        $this->assertSame([], $this->keysSaying(['More videos load'], ['de' => '/werden geladen$/u', 'fr' => '/chargées$/u', 'id' => '/^Lebih banyak video dimuat$/u',
            'ms' => '/^Lebih banyak video dimuatkan$/u', 'pt' => '/^Mais vídeos são carregados$/u', 'ru' => '/^Больше видео загружается$/u', 'tr' => '/yükleniyor$/u',
            'ko' => '/^더 많은 동영상 로드$/u', 'zh' => '/^更多视频加载$/u']), 'how more videos load, not a loading message');
        $this->assertSame([], $this->keysSaying(['"Tags" row moves', '"Creators" row moves', '"Actors" row moves', '"Fresh picks" moves'], [
            'es' => '/Creadoras/u', 'zh' => '/创造者/u', 'tr' => '/Yaratıcı|taşınır/u', 'de' => '/verschoben/u', 'ru' => '/Перемещени/u', 'th' => '/การย้าย/u',
            'ja' => '/移動します/u', 'ms' => '/"Tag" bergerak/u',
        ]));
        $this->assertSame([], $this->keysSaying(['Direction of the Fresh picks row animation.'], ['zh' => '/选秀/u']));
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['"Fresh picks" moves', 'Direction of the Fresh picks row animation.'] as $key) {
                if (! str_contains((string) ($file[$key] ?? ''), (string) ($file['Fresh picks'] ?? "\0"))) {
                    $wrong[] = "{$locale} {$key}: ".($file[$key] ?? '-')." (the row: {$file['Fresh picks']})";
                }
            }
        }
        $this->assertSame([], $wrong, 'the Fresh picks row named by its title');

        // German names the site's homepage "Homepage" on this page (its title; "Startseite" is the admin's own Home), Russian
        // "главная страница" everywhere (the admin's own Home keeps «Главная»; "домашние интернет-адреса" is another meaning)
        $this->assertSame([], $this->keysSaying(['Shows tags with the most videos on the homepage.', 'Shows creators who uploaded recently on the homepage.',
            'Shows actors viewers watch most on the homepage.', 'Animate homepage sections'], ['de' => '/Startseite/u']));
        $wrong = [];
        foreach ($this->texts('ru') as $key => $value) {
            if (preg_match('/[Дд]омашн/u', $value) && ! str_starts_with((string) $key, 'Open the admin from an address on the list.')) {
                $wrong[] = "{$key}: {$value}";
            }
        }
        $this->assertSame([], $wrong, 'ru: главная страница');
        $this->assertStringContainsString('«Главная»', (string) ($this->file('ru')['Your site goes back to the theme, colours and layouts it had before the update. You can use the new look again from Home.'] ?? ''));
        $this->assertSame('Главная', $this->file('ru')['Home'] ?? null);
    }

    public function test_the_number_field_unit_suffixes_fit_any_number(): void
    {
        // TL-VERIFY5-1 / TL-VERIFY6-1: the settings catalogue's number fields print their unit ('seconds' / 'minutes' /
        // 'days') as one fixed word after a number input (field.blade.php: __($f['suffix'])). A fixed word cannot agree
        // with every number the admin types (0, 1, 2, 3-10, 11-99, 100+): ru секунды/минуты fit only 2-4 and дни no number,
        // ar has six plural forms (the singular ثانية misfits 2 and 3-10), tr forbids the plural after a numeral, and the
        // de / es / fr / pt plural misfits 1 (and 0 in fr / pt). The count-invariant unit each language writes after a
        // number (CLDR's short / narrow unit patterns: one form for every plural category; pt days the SI symbol) fits
        // any number; the languages without number agreement keep their one word (ms the second "saat", as its helps say).
        $invariant = [
            'ar' => ['ث', 'د', 'ي'], 'de' => ['Sek.', 'Min.', 'Tg.'], 'es' => ['s', 'min', 'd'], 'fr' => ['s', 'min', 'j'],
            'pt' => ['s', 'min', 'd'], 'ru' => ['сек.', 'мин.', 'дн.'], 'tr' => ['saniye', 'dakika', 'gün'], 'hi' => ['सेकंड', 'मिनट', 'दिन'],
            'id' => ['detik', 'menit', 'hari'], 'ms' => ['saat', 'minit', 'hari'], 'ja' => ['秒', '分', '日'], 'ko' => ['초', '분', '일'],
            'zh' => ['秒', '分钟', '天'], 'th' => ['วินาที', 'นาที', 'วัน'], 'vi' => ['giây', 'phút', 'ngày'], 'tl' => ['segundo', 'minuto', 'araw'],
            'fil' => ['segundo', 'minuto', 'araw'],
        ];
        $this->assertSame(self::LOCALES, array_values(array_intersect(self::LOCALES, array_keys($invariant))), 'every locale');
        foreach ($invariant as $locale => $units) {
            $file = $this->file($locale);
            $this->assertSame($units, [$file['seconds'] ?? null, $file['minutes'] ?? null, $file['days'] ?? null], "{$locale}: one form for any number");
        }
        // the forms that agree with only some numbers are gone
        $gone = ['ru' => ['секунды', 'минуты', 'дни'], 'ar' => ['ثانية', 'دقيقة', 'يوم', 'ثواني', 'دقائق', 'أيام'], 'tr' => ['günler'],
            'de' => ['Sekunden', 'Minuten', 'Tage'], 'es' => ['segundos', 'minutos', 'días'], 'fr' => ['secondes', 'minutes', 'jours'],
            'pt' => ['segundos', 'minutos', 'dias'], 'ms' => ['detik']];
        foreach ($gone as $locale => $bad) {
            foreach (['seconds', 'minutes', 'days'] as $key) {
                $this->assertNotContains((string) ($this->file($locale)[$key] ?? ''), $bad, "{$locale} {$key}");
            }
        }
        // the three keys are the unit of these 22 fields (8 seconds, 2 minutes, 12 days) and printed nowhere else
        $units = array_count_values(array_filter(array_map(fn (array $s) => $s['suffix'] ?? null, (array) config('admin_settings.settings')),
            fn ($s) => in_array($s, ['seconds', 'minutes', 'days'], true)));
        ksort($units);
        $this->assertSame(['days' => 12, 'minutes' => 2, 'seconds' => 8], $units);
        // the ru slide-interval label is an infinitive like the page's other labels, not an order
        $this->assertSame('Менять слайд каждые', $this->file('ru')['Change slide every'] ?? null);
    }

    public function test_spanish_and_malay_name_the_homepage_one_way(): void
    {
        // TL-VERIFY5-2: Appearance > Homepage named the site's homepage two ways - es 'Homepage' said «Página principal»
        // while every description said «página de inicio»; ms 'Homepage' / 'Homepage layout' said 'laman utama' while the
        // rest said 'halaman utama'. Each takes the page's one word, distinct from the admin's own Home.
        $this->assertSame('Página de inicio', $this->file('es')['Homepage'] ?? null);
        $this->assertSame('Inicio', $this->file('es')['Home'] ?? null, "the admin's own Home stays «Inicio»");
        $this->assertSame('Halaman utama', $this->file('ms')['Homepage'] ?? null);
        $this->assertSame('Reka letak halaman utama', $this->file('ms')['Homepage layout'] ?? null);
        $this->assertSame('Laman Utama', $this->file('ms')['Home'] ?? null, "the admin's own Home stays 'Laman Utama'");
        // no homepage text on the page uses the minority word for the site's homepage ('halaman utama' contains 'laman
        // utama', so the ms pattern excludes it with a look-behind)
        $pageKeys = ['Homepage', 'Homepage picks', 'Homepage layout', 'Homepage rows',
            'The overall arrangement of your homepage.', 'The overall arrangement of the homepage, and what sits at the top.',
            'Homepage layout, top banner, welcome text and the rows of videos.',
            'What sits at the very top of the homepage: slides, a big video with tiles, ranked lists, a lead story or nothing.',
            'The long list of videos on the homepage.', 'Each homepage row gets a link to its full page.'];
        $this->assertSame([], $this->keysSaying($pageKeys, [
            'es' => '/página principal/iu',
            'ms' => '/(?<!ha)laman utama/iu',
        ]), 'one name for the site homepage on the page');
    }

    public function test_the_upload_window_and_player_texts_take_the_translations_the_owner_approved(): void
    {
        // OQ-10 (approved 30 Oct 2026): owner's texts only, in the twelve languages of the question; the 45th text of the
        // question is left out where lane WA's player words replace it
        $twelve = ['ar', 'es', 'fr', 'id', 'ja', 'ko', 'pt', 'ru', 'th', 'tr', 'vi', 'zh'];
        $this->assertCount(44, self::OWNER_APPROVED);
        $this->assertSame(112, array_sum(array_map('count', self::OWNER_APPROVED)));
        $this->assertArrayNotHasKey('Upload18 Stream Active. Internal playback speed, volume & quality are managed directly inside the video player frame.', self::OWNER_APPROVED);
        $outside = [];
        foreach (self::OWNER_APPROVED as $key => $locales) {
            if (! in_array($key, self::OWNER_TEXTS, true) || array_diff($locales, $twelve) !== []) {
                $outside[] = $key;
            }
        }
        $this->assertSame([], $outside, "OQ-10: the owner's texts, in the twelve languages");

        // one word for an upload and for a video, as the rest of the site says them (TL-O1, TL-O2); Brazilian Portuguese
        $old = [
            'fr' => '/télécharg|téléverse|(?<!\p{L})envoi|importation/iu', 'ar' => '/تحميل/u', 'ja' => '/ビデオ|ごめん/u', 'ko' => '/비디오/u',
            'es' => '/vídeo|(?<!\p{L})(carga|cargas|cargando)(?!\p{L})/iu', 'th' => '/อัพ/u', 'id' => '/(?<!\p{L})upload(?!18)/u',
            'pt' => '/carreg|ficheiro|demasiad|(?<!\p{L})(enviar|enviando|envie|bloco|pedaço)(?!\p{L})/iu',
        ];
        $wrong = [];
        $seen = [];
        foreach ($twelve as $locale) {
            foreach ($this->texts($locale) as $label => $value) {
                $key = str_starts_with((string) $label, 'js: ') ? substr((string) $label, 4) : (string) $label;
                if (! in_array($locale, self::OWNER_APPROVED[$key] ?? [], true)) {
                    continue;
                }
                $seen[$locale.' '.$key] = true;
                if (isset($old[$locale]) && preg_match($old[$locale], $value, $m)) {
                    $wrong[] = "{$locale} {$label}: \"{$m[0]}\" in {$value}";
                }
            }
        }
        $this->assertCount(112, $seen, 'every approved translation is a text the sources use');
        $this->assertSame([], $wrong, 'OQ-10: the upload and video words of the rest of the site');

        // Premium by its name
        $premium = ['ar' => 'الترقية إلى بريميوم', 'es' => 'Mejora a Premium', 'fr' => 'Passer à Premium', 'id' => 'Tingkatkan ke Premium',
            'pt' => 'Assine o Premium', 'ru' => 'Перейти на Премиум', 'th' => 'อัปเกรดเป็นพรีเมียม', 'tr' => "Premium'a yükselt",
            'vi' => 'Nâng cấp lên Premium', 'zh' => '升级到高级版'];
        foreach ($premium as $locale => $words) {
            $this->assertSame($words, $this->file($locale)['Upgrade to premium'] ?? null, $locale);
        }
    }

    public function test_a_number_fields_label_reads_with_its_number_and_unit(): void
    {
        // TL-VERIFY6-2: the admin reads "<label> [n] <unit>". The labels that read as an order, said "afterwards / later /
        // after this date / starts at", lost "longer than" or "de", or put the wrong gender, case or preposition before the
        // number are rewritten so they lead into the number.
        $labels = array_values(array_unique(array_map(fn (array $s) => (string) $s['label'], array_filter((array) config('admin_settings.settings'),
            fn (array $s) => in_array($s['suffix'] ?? null, ['seconds', 'minutes', 'days'], true)))));
        $this->assertCount(22, $labels);
        $wrong = [
            'Tips look at the last' => ['ar' => '/ننظر|الأخير/u', 'de' => '/schauen|Sie/u', 'es' => '/mira el último/u', 'fr' => '/regardez|le dernier/u',
                'hi' => '/आखिरी में देखें/u', 'id' => '/lihat yang terakhir/u', 'ja' => '/ご覧/u', 'ko' => '/보세요/u', 'ms' => '/tengok|last/u',
                'pt' => '/veja o último/u', 'th' => '/ดูที่สุดท้าย/u', 'tl' => '/Tingnan/u', 'tr' => '/sonuncuya/u', 'zh' => '/看最后/u'],
            'Alerts look at the last' => ['ar' => '/ننظر|الأخير/u', 'de' => '/sehen sich/u', 'es' => '/mira el último/u', 'fr' => '/regardent|le dernier/u',
                'hi' => '/आखिरी में देखें/u', 'id' => '/melihat yang terakhir/u', 'ja' => '/最後まで見る/u', 'ko' => '/마지막을 확인/u', 'ms' => '/melihat yang terakhir/u',
                'pt' => '/olham para o último/u', 'th' => '/ดูที่สุดท้าย/u', 'tl' => '/tumitingin/u', 'tr' => '/sonuncuya/u', 'zh' => '/查看最后/u'],
            'Next video starts after' => ['de' => '/danach/u', 'es' => '/después$/u', 'pt' => '/depois$/u', 'hi' => '/इसके बाद/u', 'id' => '/setelahnya/u',
                'ja' => '/次の後/u', 'ko' => '/날짜/u', 'th' => '/หลังจากนั้น/u', 'tr' => '/tarihten/u', 'zh' => '/开始于/u', 'tl' => '/pagkatapos$/u'],
            'Tell Google to come back after' => ['ar' => '/بعد ذلك/u', 'de' => '/danach/u', 'es' => '/después$/u', 'pt' => '/depois$/u', 'hi' => '/बाद में/u',
                'id' => '/setelahnya/u', 'ja' => '/後で/u', 'ms' => '/selepas itu/u', 'th' => '/ทีหลัง/u', 'tr' => '/daha sonra/u', 'zh' => '/稍后/u',
                'ko' => '/다음 시간/u', 'tl' => '/pagkatapos$/u'],
            'Longest video members can upload' => ['es' => '/Las miembros/u', 'ms' => '/^Ahli video/u', 'th' => '/^สมาชิกวิดีโอ/u', 'tl' => '/pinakamahabang miyembro/u'],
            'Clean search history every' => ['fr' => '/tous les$/u', 'th' => '/ทุกครั้ง/u', 'tr' => '/her defasında/u', 'hi' => '/प्रत्येक खोज/u', 'de' => '/Sie/u'],
            'Keep search history for' => ['hi' => '/^के लिए/u', 'ko' => '/다음에 대한/u', 'th' => '/เพื่อ$/u', 'ru' => '/для$/u', 'tr' => '/Şunun için/u'],
            'Show it after no uploads for' => ['de' => '/seit$/u', 'ru' => '/для$/u'],
            'Only for videos longer than' => ['ja' => '/^より/u', 'tr' => '/uzunluktaki/u'],
            'Free trial length' => ['ru' => '/длина/u', 'ms' => '/Panjang/u', 'ar' => '/النسخة/u'],
            'Keep watch progress for' => ['ms' => '/untuk$/u', 'tl' => '/para sa$/u'],
        ];
        $found = [];
        foreach ($wrong as $key => $patterns) {
            $this->assertContains($key, $labels, "{$key}: a number field's label");
            foreach ($this->keysSaying([$key], $patterns) as $line) {
                $found[] = $line;
            }
        }
        $this->assertSame([], $found, 'the label leads into its number and unit');
        // their helps say what they mean
        $this->assertSame([], array_merge(
            $this->keysSaying(['In seconds. 3600 = 1 hour.'], ['fr' => '/quelques/iu', 'ja' => '/数秒で/u', 'ko' => '/몇 초 만에/u', 'id' => '/hitungan/u',
                'ms' => '/beberapa saat/iu', 'th' => '/ไม่กี่วินาที/u', 'tr' => '/içinde/u', 'zh' => '/之内/u', 'tl' => '/Sa ilang/u', 'fil' => '/Sa ilang/u']),
            $this->keysSaying(['Seconds browsers remember https. 31536000 = 1 year.'], ['ar' => '/متصفحات الثواني/u', 'de' => '/Sekundenschnelle/u',
                'es' => '/^Segundos los navegadores/u', 'fr' => '/navigateurs secondes/u', 'hi' => '/^सेकंड ब्राउज़र/u', 'id' => '/Beberapa detik/u',
                'ja' => '/秒ブラウザ/u', 'ko' => '/초 브라우저/u', 'ms' => '/[Pp]elayar detik/u', 'ru' => '/за секунды/u', 'th' => '/เบราว์เซอร์วินาที/u',
                'tl' => '/segundong browser/u', 'tr' => '/Saniyeler içinde/u', 'zh' => '/^浏览器会记住 https。/u']),
            $this->keysSaying(['Seconds. Short clips are not tracked.'], ['es' => '/[Aa]rtículos|segunda clase/u']),
            $this->keysSaying(['Days before old watch progress is deleted.'], ['de' => '/Überwachung/u', 'fr' => '/surveillance/u', 'ja' => '/監視|数日前/u',
                'ru' => '/несколько дней/u', 'ar' => '/قبل أيام/u', 'ko' => '/며칠 전입니다/u', 'th' => '/หลายวันก่อน/u']),
            $this->keysSaying(['Days of activity used to pick the items.'], ['de' => '/Kommissionierung|Artikel/u', 'es' => '/recoger|artículos/u',
                'fr' => '/récupérer|articles/u', 'id' => '/barang/u', 'ms' => '/barang/u', 'ru' => '/предмет/u', 'zh' => '/物品/u', 'hi' => '/वस्तु/u']),
            $this->keysSaying(['Days of uploads the creator tips box checks.', 'Days of activity the creator alerts box checks.'], ['ja' => '/チェックされる|作成者/u',
                'ko' => '/체크한|확인란/u', 'ru' => '/флажк/u', 'th' => '/เลือกช่อง|ทำเครื่องหมาย/u', 'es' => '/casilla/u', 'fr' => '/cochés|case d/u',
                'id' => '/dicentang/u', 'pt' => '/ marca\./u', 'zh' => '/选中|创建者/u', 'ar' => '/يحددها|المنشئ/u', 'hi' => '/निर्माता/u', 'tr' => '/oluşturucu/u']),
            $this->keysSaying(['Days a new video shows the "New" label. 0 = never.'], ['fr' => '/Quelques jours/u', 'id' => '/Beberapa hari kemudian/u',
                'hi' => '/आए दिन/u', 'ar' => '/^بعد أيام/u', 'ja' => '/日が続きます|決してしない/u', 'zh' => '/^天新视频/u', 'de' => '/^Tage zeigt/u',
                'es' => '/^Días un/u', 'pt' => '/^Dias um/u', 'ru' => '/^Дни новое/u']),
            $this->keysSaying(['Days before the trial ends that members get a reminder email.'], ['fr' => '/Quelques jours/u', 'hi' => '/कुछ दिन/u',
                'id' => '/Beberapa hari/u', 'ja' => '/数日前/u', 'ms' => '/Beberapa hari/u', 'ru' => '/несколько дней/u', 'tr' => '/günler önce/u',
                'zh' => '/前几天/u', 'ar' => '/قبل أيام/u']),
            $this->keysSaying(['Seconds of countdown before the next video plays. 0 = off.'], ['fr' => '/Quelques secondes/u', 'hi' => '/कुछ सेकंड/u',
                'ja' => '/数秒間/u', 'ko' => '/몇 초 동안/u', 'th' => '/ไม่กี่วินาที/u', 'tr' => '/saniyeler süren/u']),
        ), 'the helps of the same fields');
        // the "New" label quotes the card's badge; Malay says it 'Baharu' on the badge too
        $wrongBadge = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            $badge = str_replace("\u{0307}", '', mb_strtolower((string) ($file['NEW'] ?? '')));
            foreach (['"New" label for', 'Days a new video shows the "New" label. 0 = never.'] as $key) {
                if ($badge === '' || ! str_contains(str_replace("\u{0307}", '', mb_strtolower((string) ($file[$key] ?? ''))), $badge)) {
                    $wrongBadge[] = "{$locale} {$key}: ".($file[$key] ?? '-')." (the badge: ".($file['NEW'] ?? '-').')';
                }
            }
        }
        $this->assertSame([], $wrongBadge, 'the label and its help quote the badge as the card shows it');
    }

    public function test_the_admin_pages_name_the_site_homepage_one_way(): void
    {
        // TL-VERIFY6-3: every admin text that names the site's homepage uses the language's one admin word for it, and not
        // the word of the admin's own Home (de Startseite, ms Laman Utama, zh 首页 stay the admin's Home, the front menu's
        // Home item and a channel's Home tab)
        $main = ['ar' => '/رئيسية/u', 'de' => '/Homepage/u', 'es' => '/página de inicio/iu', 'fil' => '/homepage/iu', 'fr' => '/pages? d[’\']accueil/iu',
            'hi' => '/मुखपृष्ठ/u', 'id' => '/beranda/iu', 'ja' => '/ホームページ/u', 'ko' => '/홈페이지/u', 'ms' => '/halaman utama/iu', 'pt' => '/página inicial/iu',
            'ru' => '/главн/iu', 'th' => '/หน้าแรก/u', 'tl' => '/homepage/iu', 'tr' => '/ana sayfa/iu', 'vi' => '/trang chủ/iu', 'zh' => '/主页/u'];
        $other = ['de' => '/Startseite/u', 'hi' => '/होमपेज|होम पेज/u', 'ms' => '/(?<![Hh]a)[Ll]aman [Uu]tama/u', 'zh' => '/首页/u',
            'es' => '/portada|página principal/iu', 'ja' => '/ホーム(?!ページ)/u', 'ko' => '/홈(?!페이지)/u',
            'fr' => '/(?<![Pp]age d\')(?<![Pp]age d’)(?<![Pp]ages d\')(?<![Pp]ages d’)\b[Aa]ccueil\b/u'];
        $site = '/home\s?page|homepage|home feed|home layout|home rows?\b|home hero|home side|^Home Smart|^Home Creator|^Home Trending|^Home, category|snippet for home|Neon HUD home|^Widget Motion$/i';
        // other things called Home, an old label that takes the new admin's 'Main video list', and the error pages of the
        // public site (their Home is the front menu's)
        $notSite = '/^Show Homepage Videos$|admin Home|channel\'?s? home|channel home|Open Home|from Home|Back to Home|^Home$|^Home, Categories and Random|go home|Stay signed in|go back to the home page|start from the home page/i';
        $keys = [];
        foreach ($this->required() as $key => $meta) {
            $english = (string) ($meta['source'] ?? $key);
            if (preg_match($site, $english) === 1 && preg_match($notSite, $english) !== 1) {
                $keys[] = (string) $key;
            }
        }
        $this->assertGreaterThanOrEqual(55, count($keys), 'the admin texts that name the site homepage');
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach ($keys as $key) {
                $value = (string) ($file[$key] ?? '');
                if (preg_match($main[$locale], $value) !== 1) {
                    $wrong[] = "{$locale} {$key}: no {$main[$locale]} in {$value}";
                } elseif (isset($other[$locale]) && preg_match($other[$locale], $value, $m) === 1) {
                    $wrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                }
            }
        }
        $this->assertSame([], $wrong, 'one name for the site homepage on the admin pages');
        // the admin's own Home keeps its own word
        $this->assertSame(['Startseite', 'Laman Utama', '首页'], [$this->file('de')['Home'] ?? null, $this->file('ms')['Home'] ?? null, $this->file('zh')['Home'] ?? null]);
        $this->assertSame('主页精选', $this->file('zh')['Homepage picks'] ?? null, "the Featured page's menu entry, on every admin page");
        // Appearance > Effects & speed quotes the Homepage page's switch with its own words, in every language
        $quote = 'Homepage section animations and page reveals. Needed for "Animate homepage sections".';
        $unquoted = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            if (! str_contains((string) ($file[$quote] ?? ''), (string) ($file['Animate homepage sections'] ?? "\0"))) {
                $unquoted[] = "{$locale}: ".($file[$quote] ?? '-')." (the switch: ".($file['Animate homepage sections'] ?? '-').')';
            }
        }
        $this->assertSame([], $unquoted);
        // the old settings page's three row ad slots describe their row by the label shown above them
        $slots = ['Home Smart Discovery widget video feed.' => 'Smart Discovery', 'Home Creator Spotlight widget video feed.' => 'Creator Spotlight',
            'Home Trending Actors widget video feed.' => 'Trending Actors'];
        $unnamed = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach ($slots as $description => $label) {
                if (! str_contains((string) ($file[$description] ?? ''), (string) ($file[$label] ?? "\0"))) {
                    $unnamed[] = "{$locale} {$description}: ".($file[$description] ?? '-')." (the slot: ".($file[$label] ?? '-').')';
                }
            }
        }
        $this->assertSame([], $unnamed);
        // a menu entry starts with a capital letter
        $this->assertSame(['Halaman video', 'Pahina ng video', 'Pahina ng video'],
            [$this->file('ms')['Video page'] ?? null, $this->file('fil')['Video page'] ?? null, $this->file('tl')['Video page'] ?? null]);
    }

    public function test_the_section_animation_and_the_preview_switch_say_what_they_mean(): void
    {
        // TL-VERIFY6-4: Appearance > Homepage's 'Section animation' options (also the old settings page's 'Motion Style')
        // are animation names: not an elevator (Lift), a playground or presentation slide (Slide), discolouring or
        // disappearing (Fade); their help is a list of the four, not orders to the reader
        $this->assertSame([], array_merge(
            $this->keysSaying(['Slide'], ['de' => '/Rutsche/u', 'hi' => '/फिसल/u', 'es' => '/diapositiva/iu', 'fr' => '/diapositive/iu', 'ru' => '/Слайд/u',
                'tr' => '/Slayt/u', 'ar' => '/شريحة/u', 'ms' => '/Slaid/u']),
            $this->keysSaying(['Lift'], ['es' => '/ascensor/iu', 'fr' => '/ascenseur/iu', 'pt' => '/elevador/iu', 'ru' => '/Лифт/u', 'tr' => '/Asansör/u',
                'vi' => '/thang máy/iu', 'hi' => '/लिफ्ट/u', 'ja' => '/リフト/u', 'ko' => '/리프트/u', 'zh' => '/举起/u', 'ar' => '/^ارفع$/u']),
            $this->keysSaying(['Fade'], ['zh' => '/褪色/u', 'pt' => '/Desaparecer/u', 'tr' => '/Solma/u', 'es' => '/desvanecerse/u', 'ar' => '/تتلاشى/u',
                'ms' => '/^pudar$/u', 'ru' => '/Затухание/u', 'de' => '/Verblassen/u', 'vi' => '/Phai/u', 'th' => '/จางลง/u', 'hi' => '/फीका/u', 'id' => '/Memudar/u']),
            $this->keysSaying(['Zoom'], ['hi' => '/करें/u', 'ru' => '/Увеличить/u', 'tr' => '/Yakınlaştır$/u', 'id' => '/Perbesar/u', 'tl' => '/Mag-zoom/u', 'fil' => '/Mag-zoom/u']),
            $this->keysSaying(['Slide, lift, zoom or fade.'], ['ru' => '/айте/u', 'tr' => '/karartın|kaldırın|Kaydırın/u', 'ar' => '/قم ب/u',
                'ms' => '/Luncurkan/u', 'es' => '/Desliza,/u', 'fr' => '/Faites|soulevez/u', 'hi' => '/करें/u', 'pt' => '/Deslize|levante/u', 'zh' => '/抬起|褪色/u']),
        ), 'the section animation options');
        // zh says a section 区块 on the page (the labels did, the helps said 部分)
        $this->assertSame([], $this->keysSaying(['Sections slide in when they appear.', 'Cards in a section appear in a short cascade.',
            'How far sections move while appearing, in pixels.', 'Section animation', 'Animate homepage sections'], ['zh' => '/部分/u', 'fr' => '/rubrique/iu']));
        // the live preview's device switch: its name says "preview on" (not "preview is on", a preview date or a running
        // preview), and its phone is a device ('Mobile phone', its own key: 'Phone' stays the profile's phone number)
        $this->assertSame([], $this->keysSaying(['Preview on'], ['ar' => '/قيد التشغيل/u', 'tr' => '/tarihi/u', 'ru' => '/включен/u', 'pt' => '/ativada/u',
            'id' => '/aktif/u', 'ms' => '/dihidupkan/u', 'hi' => '/चालू/u', 'ko' => '/켜기/u', 'tl' => '/Naka-on/u', 'fil' => '/Naka-on/u', 'de' => '/Vorschau an$/u']));
        $this->assertSame(['ja' => 'スマホ', 'ko' => '휴대폰', 'zh' => '手机', 'id' => 'Ponsel'],
            array_map(fn (string $locale) => $this->file($locale)['Mobile phone'] ?? null, ['ja' => 'ja', 'ko' => 'ko', 'zh' => 'zh', 'id' => 'id']));
        $this->assertSame(['電話番号', '전화번호'], [$this->file('ja')['Phone'] ?? null, $this->file('ko')['Phone'] ?? null], "the profile's phone number");
        foreach (['ja' => ['スマホ', '電話番号'], 'ko' => ['휴대폰', '전화번호'], 'zh' => ['手机', '电话']] as $locale => [$device, $number]) {
            app()->setLocale($locale);
            $html = \Illuminate\Support\Facades\Blade::render('<x-admin.monitor :pages="$pages" />', ['pages' => ['/' => 'Homepage']]);
            $this->assertStringContainsString('<span>'.$device.'</span>', $html, "{$locale}: the switch's phone");
            $this->assertStringNotContainsString($number, $html, "{$locale}: not a phone number");
            $this->assertStringContainsString('aria-label="'.e((string) ($this->file($locale)['Preview on'] ?? '')).'"', $html);
        }
    }

    public function test_the_trial_card_names_the_trial_one_way(): void
    {
        // the Money pages (Plans' trial card with its two number fields, the Premium page's settings of the free-trial
        // steps) and Admin > Subscriptions say the free trial with the member Premium page's word: a membership's trial
        // period, not a "trial version" of software (ar النسخة / الإصدار التجريبي, de Testversion, ko 평가판, ru пробная
        // версия), a probation period or a sample (de Probezeit, Probe), an evaluation (pt avaliação) or "test" (hi परीक्षण)
        $keys = ['Free trial', 'Prices, free trial, and what free and premium members may upload.', 'Free trial length',
            'Free premium days before the first card payment, for members who never had premium. 0 = no trial.',
            'Remind members before the trial ends', 'Days before the trial ends that members get a reminder email.',
            '"How your trial works" steps', 'Shows trial, reminder and first payment as three steps.', 'Premium page: Trial badge text',
            'Premium page: Trial billing summary', 'Premium page: Billing summary without trial', 'Premium page: Trial step title',
            'Premium page: Trial step description', 'Trial', 'Trial Ends', 'In trial period'];
        $this->assertSame([], $this->keysSaying($keys, ['ar' => '/النسخة التجريبية|الإصدار التجريبي/u', 'de' => '/Testversion|Probezeit|Probe(?!\p{L})|Mahnung/u',
            'ko' => '/평가판/u', 'ru' => '/пробная версия|пробную версию|пробной версии|пробная длина/u', 'hi' => '/परीक्षण/u', 'ja' => '/試用|お試し/u', 'pt' => '/avaliação/u',
            'tl' => '/(?<!\p{L})trial(?!\p{L})/u', 'fil' => '/(?<!\p{L})trial(?!\p{L})/u']), 'one word for the free trial');
        // the setting quotes the member page's heading with the heading's own words
        $unquoted = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            if (! str_contains((string) ($file['"How your trial works" steps'] ?? ''), (string) ($file['How the free trial works'] ?? "\0"))) {
                $unquoted[] = "{$locale}: ".($file['"How your trial works" steps'] ?? '-')." (the heading: ".($file['How the free trial works'] ?? '-').')';
            }
        }
        $this->assertSame([], $unquoted);
    }

    public function test_the_homepage_row_directions_phone_help_and_old_motion_options(): void
    {
        // TL-VERIFY7-1 (a): the four "<row> moves" selects (home_widget_*_motion_direction, the only selects that offer
        // left/right/up/down) print a direction, not "correct / right (= true)", "low" or an adjective
        $direction = ['Left' => ['ms' => 'Kiri', 'ru' => 'Влево'],
            'Right' => ['es' => 'Derecha', 'hi' => 'दाएं', 'id' => 'Kanan', 'ms' => 'Kanan', 'pt' => 'Direita', 'ru' => 'Вправо', 'tl' => 'Kanan', 'fil' => 'Kanan'],
            'Up' => ['tl' => 'Pataas', 'fil' => 'Pataas'], 'Down' => ['pt' => 'Abaixo']];
        foreach ($direction as $key => $vals) {
            foreach ($vals as $locale => $value) {
                $this->assertSame($value, $this->file($locale)[$key] ?? null, "{$locale} {$key}: a direction");
            }
        }
        // the direction options and the old page's motion options start with a capital letter (ms 'kiri', tl 'pataas',
        // es 'deslizarse ...' did not)
        foreach (['Left', 'Right', 'Up', 'Down', 'Slide Right', 'Slide Left', 'Slide Up', 'Slide Down', 'Fade Only'] as $key) {
            foreach (['es', 'de', 'fr', 'id', 'ms', 'pt', 'tr', 'tl', 'fil', 'vi'] as $locale) {
                $v = (string) ($this->file($locale)[$key] ?? '');
                $this->assertTrue($v !== '' && ! ctype_lower($v[0]), "{$locale} {$key}: \"{$v}\" starts with a capital");
            }
        }
        // (b) the help of 'Videos per row in the main list' ("On computers. Phones use the phone setting."), the phone setting
        // (Appearance > Video cards) and its old-page label 'Mobile Video Columns' say the phone and the computer with the
        // words of the preview switch ('Computer' / 'Mobile phone', on every Appearance page), one word each, in every
        // language: ja / ko / zh / id said a telephone, ko 휴대전화 beside 휴대폰, ar الكمبيوتر beside حاسوب
        $phone = ['ar' => '/هات|هوات/u', 'de' => '/Telefon/u', 'es' => '/[Tt]eléfono/u', 'fil' => '/[Tt]elepono/u', 'fr' => '/[Tt]éléphone/u',
            'hi' => '/फ़ोन/u', 'id' => '/[Pp]onsel/u', 'ja' => '/スマホ/u', 'ko' => '/휴대폰/u', 'ms' => '/[Tt]elefon/u', 'pt' => '/[Tt]elefone/u',
            'ru' => '/[Тт]елефон/u', 'th' => '/โทรศัพท์/u', 'tl' => '/[Tt]elepono/u', 'tr' => '/[Tt]elefon/u', 'vi' => '/[Đđ]iện thoại/u', 'zh' => '/手机/u'];
        $phoneOther = ['id' => '/[Tt]elepon(?!o)/u', 'ja' => '/電話/u', 'ko' => '/전화기|휴대전화/u', 'zh' => '/电话/u'];
        $computer = ['ar' => '/حاسوب/u', 'de' => '/Computer/u', 'es' => '/[Cc]omputadora/u', 'fil' => '/[Kk]ompyuter/u', 'fr' => '/[Oo]rdinateur/u',
            'hi' => '/कंप्यूटर/u', 'id' => '/[Kk]omputer/u', 'ja' => '/コンピューター/u', 'ko' => '/컴퓨터/u', 'ms' => '/[Kk]omputer/u', 'pt' => '/[Cc]omputador/u',
            'ru' => '/[Кк]омпьютер/u', 'th' => '/คอมพิวเตอร์/u', 'tl' => '/[Kk]ompyuter/u', 'tr' => '/[Bb]ilgisayar/u', 'vi' => '/[Mm]áy tính/u', 'zh' => '/电脑/u'];
        $wrongDevice = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['Mobile phone', 'Videos per row on phones', 'Mobile Video Columns', 'On computers. Phones use the phone setting.'] as $key) {
                $v = (string) ($file[$key] ?? '');
                if (preg_match($phone[$locale], $v) !== 1 || (isset($phoneOther[$locale]) && preg_match($phoneOther[$locale], $v) === 1)) {
                    $wrongDevice[] = "{$locale} {$key} (phone): {$v}";
                }
            }
            foreach (['Computer', 'On computers. Phones use the phone setting.'] as $key) {
                $v = (string) ($file[$key] ?? '');
                if (preg_match($computer[$locale], $v) !== 1 || ($locale === 'ar' && preg_match('/كمبيوتر/u', $v) === 1)) {
                    $wrongDevice[] = "{$locale} {$key} (computer): {$v}";
                }
            }
        }
        $this->assertSame([], $wrongDevice, "one phone word and one computer word per language: the preview switch's");
        // (c) + (d): Appearance > Homepage's Row animation card and the old settings page (its 'Motion Style' select and its
        // four direction selects, index.blade.php:2509) say the slide animation with ONE word per language across 'Slide',
        // 'Slide distance' and 'Slide Right/Left/Up/Down', and the fade animation with one word across 'Fade' and 'Fade Only'
        $low = fn (string $s): string => str_replace("\u{0307}", '', mb_strtolower($s));
        $slide = ['ar' => 'انزلاق', 'de' => 'schieb', 'es' => 'desliz', 'fil' => 'slide', 'fr' => 'gliss', 'hi' => 'स्लाइड', 'id' => 'geser',
            'ja' => 'スライド', 'ko' => '슬라이드', 'ms' => 'luncur', 'pt' => 'desliz', 'ru' => 'сдвиг', 'th' => 'สไลด์', 'tl' => 'slide',
            'tr' => 'kayma', 'vi' => 'trượt', 'zh' => '滑'];
        $fade = ['ar' => 'ظهور تدريجي', 'de' => 'einblenden', 'es' => 'fundido', 'fil' => 'paglitaw', 'fr' => 'fondu', 'hi' => 'फ़ेड-इन',
            'id' => 'muncul perlahan', 'ja' => 'フェード', 'ko' => '페이드', 'ms' => 'muncul perlahan', 'pt' => 'surgir', 'ru' => 'проявлени',
            'th' => 'ค่อยๆ ปรากฏ', 'tl' => 'paglitaw', 'tr' => 'belirme', 'vi' => 'hiện dần', 'zh' => '淡入'];
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            // ('Distance Px' is the old settings page's label of the slide distance: it follows 'Slide distance')
            foreach (['Slide', 'Slide distance', 'Distance Px', 'Slide Right', 'Slide Left', 'Slide Up', 'Slide Down'] as $key) {
                if (! str_contains($low((string) ($file[$key] ?? '')), $low($slide[$locale]))) {
                    $wrong[] = "{$locale} {$key}: not the slide word \"{$slide[$locale]}\": ".($file[$key] ?? '-');
                }
            }
            foreach (['Fade', 'Fade Only'] as $key) {
                if (! str_contains($low((string) ($file[$key] ?? '')), $low($fade[$locale]))) {
                    $wrong[] = "{$locale} {$key}: not the fade word \"{$fade[$locale]}\": ".($file[$key] ?? '-');
                }
            }
        }
        $this->assertSame([], $wrong, 'one slide word and one fade word per language');
        // not a presentation slide (ru Слайд, ms/tr loanwords), "launch" (ms Luncurkan), a slip (de rutschen), a second verb
        // (ar حرك "move", th เลื่อน = this page's self-scroll, es reflexive, ja/ko English アップ/ダウン/업) or an order to
        // the reader (hi करें, pt Deslize, tr Kaydır, fil/tl I-slide) - as the help 'Slide, lift, zoom or fade.' was fixed
        $orders = ['ru' => '/Слайд/u', 'ms' => '/Luncurkan|[Ss]laid/u', 'de' => '/rutsch/u', 'ar' => '/حرك/u', 'es' => '/deslizarse/iu',
            'hi' => '/करें/u', 'ja' => '/アップ|ダウン/u', 'ko' => '/업/u', 'pt' => '/Deslize/u', 'th' => '/เลื่อน/u', 'tr' => '/Kaydır|[Ss]layt/u',
            'tl' => '/I-slide|^Slide /u', 'fil' => '/I-slide|^Slide /u'];
        $leftover = [];
        foreach (['Slide Right', 'Slide Left', 'Slide Up', 'Slide Down', 'Slide distance', 'Distance Px'] as $key) {
            foreach ($orders as $locale => $pattern) {
                $v = (string) ($this->file($locale)[$key] ?? '');
                if (preg_match($pattern, $v, $m)) {
                    $leftover[] = "{$locale} {$key}: \"{$m[0]}\" in {$v}";
                }
            }
        }
        $this->assertSame([], $leftover, 'the slide options say the slide animation, not another sense or an order');
        $fadeAway = ['de' => '/verblassen/u', 'pt' => '/desbotamento/u', 'tr' => '/Solma/u', 'hi' => '/फीका/u', 'id' => '/Pudar/u', 'ms' => '/Pudar/u',
            'th' => '/จางลง/u', 'ar' => '/تتلاشى/u', 'ru' => '/затухание/u', 'es' => '/desvanecimiento/iu', 'zh' => '/淡入淡出/u', 'vi' => '/mờ dần/u',
            'tl' => '/(?<![A-Za-z])Fade/u', 'fil' => '/(?<![A-Za-z])Fade/u'];
        foreach ($fadeAway as $locale => $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, (string) ($this->file($locale)['Fade Only'] ?? ''), "{$locale} 'Fade Only': the fade-in animation");
        }
        foreach (['Slide distance', 'Distance Px'] as $key) {
            $this->assertSame(['Jarak luncuran', 'Kayma mesafesi', 'Расстояние сдвига'],
                [$this->file('ms')[$key] ?? null, $this->file('tr')[$key] ?? null, $this->file('ru')[$key] ?? null], $key);
        }
    }

    public function test_the_creator_boxes_have_one_name_each(): void
    {
        // TL-VERIFY7-2: on Settings > Advanced each of the two "Creator studio helpers" boxes is named one way across its
        // switch label, both number-field helps and its count labels in every language. The eighth pass wrote the "Days ..."
        // helps with the lane's creator words (the test above rejects ar المنشئ, zh 创建者, hi निर्माता, tr oluşturucu there)
        // and left the label and the other help with the old words; the label and that help now take the day help's words,
        // with the box word the card's own rows use (es cuadro, ja ボックス).
        $tips = ['Creator tips box', 'How many videos the creator tips box lists.', 'Days of uploads the creator tips box checks.'];
        $alerts = ['Creator alerts box', 'How many alerts the creator alerts box lists.', 'Days of activity the creator alerts box checks.'];
        // the Arabic creator phrase as the day help writes it (one set of code points for the whole card)
        $arCreator = rtrim((string) (explode('مربع نصائح ', (string) ($this->file('ar')[$tips[2]] ?? ''), 2)[1] ?? "\0"), '.');
        $tipsRoots = ['ar' => [$arCreator, 'مربع', 'نصائح'], 'de' => ['Ersteller', 'Feld', 'Tipps'], 'es' => ['creadores', 'cuadro', 'consejos'],
            'fil' => ['creator', 'kahon', 'tip'], 'fr' => ['créateurs', 'encadré', 'conseils'], 'hi' => ['रचनाकार', 'बॉक्स', 'टिप्स'],
            'id' => ['pembuat konten', 'kotak', 'tips'], 'ja' => ['クリエイター', 'ボックス', 'ヒント'], 'ko' => ['크리에이터', '상자', '팁'],
            'ms' => ['pencipta', 'kotak', 'petua'], 'pt' => ['criadores', 'caixa', 'dicas'], 'ru' => ['автор', 'блок', 'совет'],
            'th' => ['ผู้สร้าง', 'กล่อง', 'เคล็ดลับ'], 'tl' => ['creator', 'kahon', 'tip'], 'tr' => ['üretici', 'kutu', 'ipuçları'],
            'vi' => ['nhà sáng tạo', 'ô gợi ý'], 'zh' => ['创作者', '框', '提示']];
        $alertsRoots = ['ar' => [$arCreator, 'مربع', 'تنبيهات'], 'de' => ['Ersteller', 'Feld', 'Warnungen'], 'es' => ['creadores', 'cuadro', 'alertas'],
            'fil' => ['creator', 'kahon', 'alerto'], 'fr' => ['créateurs', 'encadré', 'alertes'], 'hi' => ['रचनाकार', 'बॉक्स', 'अलर्ट'],
            'id' => ['pembuat konten', 'kotak', 'peringatan'], 'ja' => ['クリエイター', 'ボックス', 'アラート'], 'ko' => ['크리에이터', '상자', '알림'],
            'ms' => ['pencipta', 'kotak', 'makluman'], 'pt' => ['criadores', 'caixa', 'alertas'], 'ru' => ['автор', 'блок', 'оповещ'],
            'th' => ['ผู้สร้าง', 'กล่อง', 'แจ้งเตือน'], 'tl' => ['creator', 'kahon', 'alerto'], 'tr' => ['üretici', 'kutu', 'uyarı'],
            'vi' => ['nhà sáng tạo', 'ô cảnh báo'], 'zh' => ['创作者', '框', '提醒']];
        $this->assertStringStartsWith('صن', $arCreator, "ar: the day help's creator phrase");
        $low = fn (string $s): string => str_replace("\u{0307}", '', mb_strtolower($s));
        $missing = [];
        foreach ([[$tips, $tipsRoots], [$alerts, $alertsRoots]] as [$keys, $roots]) {
            foreach (self::LOCALES as $locale) {
                $file = $this->file($locale);
                foreach ($keys as $key) {
                    $value = (string) ($file[$key] ?? '');
                    foreach ($roots[$locale] as $root) {
                        if (! str_contains($low($value), $low($root))) {
                            $missing[] = "{$locale} {$key}: no \"{$root}\" in {$value}";
                        }
                    }
                }
            }
        }
        $this->assertSame([], $missing, 'each box: one creator / box / tips-or-alerts word across its label and both helps');
        // the old words are gone from both boxes (the labels' old words; the words the eighth pass judged wrong)
        $gone = ['ar' => ['منشئ', 'المبدعين'], 'de' => ['Benachrichtigung', 'Creator-', 'Box mit'], 'es' => ['recuadro', 'del creador'],
            'fr' => ['boîte', 'Boîte', 'du créateur'], 'hi' => ['क्रिएटर', 'निर्माता'], 'id' => ['lansiran', 'kreator'], 'ja' => ['欄', '作成者'],
            'ko' => ['작성자', '경고', '제작자'], 'pt' => ['do criador'], 'ru' => ['поле', 'Окно', 'уведомл', 'создател', '«'], 'th' => ['ครีเอเตอร์'],
            'tl' => ['tagalikha'], 'fil' => ['tagalikha'], 'tr' => ['oluşturucu', 'yaratıcı', 'uyarıları'], 'zh' => ['创建者', '警报']];
        $leftover = [];
        foreach ($gone as $locale => $words) {
            $file = $this->file($locale);
            foreach (array_merge($tips, $alerts) as $key) {
                foreach ($words as $word) {
                    if (str_contains((string) ($file[$key] ?? ''), $word)) {
                        $leftover[] = "{$locale} {$key}: \"{$word}\"";
                    }
                }
            }
        }
        $this->assertSame([], $leftover, 'the old creator / box / alert words are gone from both boxes');
        // the count labels carry the box's tips / alerts word
        $tipsWord = array_map(fn (array $r): string => $r[count($r) - 1], $tipsRoots);
        $alertsWord = array_map(fn (array $r): string => $r[count($r) - 1], $alertsRoots);
        $tipsWord['vi'] = 'gợi ý';
        $alertsWord['vi'] = 'cảnh báo';
        $countMissing = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->file($locale);
            foreach (['Tips shown' => $tipsWord, 'Tips look at the last' => $tipsWord, 'Alerts shown' => $alertsWord, 'Alerts look at the last' => $alertsWord] as $key => $words) {
                if (! str_contains($low((string) ($file[$key] ?? '')), $low($words[$locale]))) {
                    $countMissing[] = "{$locale} {$key}: no \"{$words[$locale]}\" in ".($file[$key] ?? '-');
                }
            }
        }
        $this->assertSame([], $countMissing, "the count labels take the box's tips / alerts word");
    }

    public function test_the_old_settings_page_and_the_touched_pages_name_the_creator_boxes_and_tiles_one_way(): void
    {
        // Tenth pass (TL-VERIFY8-1/2/3 + the orchestrator's whole-page census): on the OLD settings page each creator
        // studio box and the Creators row follow their new label, the two section headings carry the box's words with no
        // pulse / health / English; on every page the pass touched (Appearance > Homepage, Video cards, Settings >
        // Advanced) the creator is named one way, the category tile one way, the computer and row words match the page,
        // and 'Ring around creator pictures' is a ring, not an order.
        $file = [];
        foreach (self::LOCALES as $locale) {
            $file[$locale] = $this->file($locale);
        }
        $low = fn (string $s): string => str_replace("\u{0307}", '', mb_strtolower($s));

        // 1. the old-page box toggles / fields and the Creators row fields follow their new label (byte-equal, all 17)
        $followers = [
            'Pulse Enabled' => 'Creator tips box', 'Pulse Item Limit' => 'Tips shown', 'Pulse Lookback Days' => 'Tips look at the last',
            'Alerts Enabled' => 'Creator alerts box', 'Alert Limit' => 'Alerts shown', 'Alert Lookback Days' => 'Alerts look at the last',
            'Spotlight Enabled' => 'Creators row', 'Spotlight Item Limit' => 'Creators row: items', 'Creator Lookback Days' => 'Creators row: counts the last',
        ];
        $notFollowing = [];
        foreach ($followers as $old => $new) {
            foreach (self::LOCALES as $locale) {
                if (($file[$locale][$old] ?? null) !== ($file[$locale][$new] ?? '-')) {
                    $notFollowing[] = "{$locale} {$old}: ".($file[$locale][$old] ?? '-')." (new: ".($file[$locale][$new] ?? '-').')';
                }
            }
        }
        $this->assertSame([], $notFollowing, 'the old-page boxes and Creators row follow their new label');

        // 2. the two section headings carry the creator word and the tips / alerts word, and no pulse / health / God /
        // phone-credit / English leftover
        $creatorOk = ['ar' => '/صنّاع المحتوى|صانع/u', 'de' => '/Ersteller/u', 'es' => '/creador/iu', 'fil' => '/creator/iu', 'fr' => '/créateur/iu',
            'hi' => '/रचनाकार/u', 'id' => '/pembuat/iu', 'ja' => '/クリエイター/u', 'ko' => '/크리에이터/u', 'ms' => '/pencipta/iu', 'pt' => '/criador/iu',
            'ru' => '/автор/iu', 'th' => '/ผู้สร้าง/u', 'tl' => '/creator/iu', 'tr' => '/üretici/iu', 'vi' => '/nhà sáng tạo/iu', 'zh' => '/创作者/u'];
        $tipsW = ['ar' => 'نصائح', 'de' => 'Tipps', 'es' => 'consejos', 'fil' => 'tip', 'fr' => 'conseils', 'hi' => 'टिप्स', 'id' => 'tips', 'ja' => 'ヒント',
            'ko' => '팁', 'ms' => 'petua', 'pt' => 'dicas', 'ru' => 'совет', 'th' => 'เคล็ดลับ', 'tl' => 'tip', 'tr' => 'ipuçları', 'vi' => 'gợi ý', 'zh' => '提示'];
        $alertsW = ['ar' => 'تنبيهات', 'de' => 'Warnungen', 'es' => 'alertas', 'fil' => 'alerto', 'fr' => 'alertes', 'hi' => 'अलर्ट', 'id' => 'peringatan',
            'ja' => 'アラート', 'ko' => '알림', 'ms' => 'makluman', 'pt' => 'alertas', 'ru' => 'оповещ', 'th' => 'แจ้งเตือน', 'tl' => 'alerto', 'tr' => 'uyarı',
            'vi' => 'cảnh báo', 'zh' => '提醒'];
        $badHead = ['ar' => ['نبض', 'الخالق', 'صحي'], 'de' => ['Impuls', 'Pulse', 'Gesundheit', 'Health'], 'es' => ['Pulso', 'pulso', 'salud'],
            'fil' => ['Pulse', 'Kalusugan'], 'fr' => ['Pulse', 'impulsion', 'santé'], 'hi' => ['पल्स', 'लुकबैक', 'स्वास्थ्य'],
            'id' => ['Pulsa', 'Pulse', 'Kesehatan'], 'ja' => ['パルス', '健康', 'ルックバック'], 'ko' => ['펄스', '건강'], 'ms' => ['Nadi', 'Kesihatan', 'Pulse'],
            'pt' => ['Pulso', 'pulso', 'saúde', 'lookback'], 'ru' => ['Пульс', 'пульс', 'импульс', 'здоров'], 'th' => ['พัลส์', 'ชีพจร', 'สุขภาพ'],
            'tl' => ['Pulse', 'Kalusugan'], 'tr' => ['Nabız', 'nabız', 'Darbe', 'Sağlık'], 'vi' => ['xung', 'Xung', 'sức khỏe'], 'zh' => ['脉搏', '脉冲', '健康']];
        $headWrong = [];
        foreach ([['Creator Quality Pulse', $tipsW], ['Studio Health Alerts', $alertsW]] as [$key, $word]) {
            foreach (self::LOCALES as $locale) {
                $value = (string) ($file[$locale][$key] ?? '');
                if (! preg_match($creatorOk[$locale], $value)) {
                    $headWrong[] = "{$locale} {$key}: no creator word in {$value}";
                }
                if (! str_contains($low($value), $low($word[$locale]))) {
                    $headWrong[] = "{$locale} {$key}: no \"{$word[$locale]}\" in {$value}";
                }
                foreach ($badHead[$locale] as $bad) {
                    if (str_contains($value, $bad)) {
                        $headWrong[] = "{$locale} {$key}: \"{$bad}\" in {$value}";
                    }
                }
            }
        }
        $this->assertSame([], $headWrong, 'the old-page box section headings use the box words, no pulse / health / English');

        // 3. the whole-page creator sweep: no rejected creator word in any setting label/help or card title/description on
        // the pages the pass touched (plus the two old-page headings)
        $reject = ['ar' => '/مبدع|منشئ/u', 'de' => '/YouTuber/u', 'fil' => '/tagalikha|lumikha/iu', 'tl' => '/tagalikha|lumikha/iu',
            'hi' => '/निर्माता|क्रिएटर/u', 'id' => '/kreator/iu', 'ko' => '/제작자|작성자/u', 'ru' => '/создател/iu', 'th' => '/ครีเอเตอร์/u',
            'tr' => '/oluşturucu|yaratıcı/iu', 'zh' => '/创建者|创造者/u', 'vi' => '/người tạo/iu'];
        $touched = ['appearance.homepage', 'appearance.cards', 'settings.advanced'];
        $pageTexts = ['Creator Quality Pulse', 'Studio Health Alerts'];
        foreach ((array) config('admin_settings.settings') as $setting) {
            if (in_array($setting['page'] ?? '', $touched, true)) {
                foreach (['label', 'help'] as $f) {
                    if (! empty($setting[$f])) {
                        $pageTexts[] = (string) $setting[$f];
                    }
                }
            }
        }
        foreach ((array) config('admin_settings.pages') as $pageKey => $page) {
            if (in_array($pageKey, $touched, true)) {
                foreach ((array) ($page['cards'] ?? []) as $card) {
                    foreach (['title', 'description'] as $f) {
                        if (! empty($card[$f])) {
                            $pageTexts[] = (string) $card[$f];
                        }
                    }
                }
            }
        }
        $pageTexts = array_values(array_unique($pageTexts));
        $sweep = [];
        foreach ($reject as $locale => $pattern) {
            foreach ($pageTexts as $key) {
                $value = (string) ($file[$locale][$key] ?? '');
                if ($value !== '' && preg_match($pattern, $value, $m)) {
                    $sweep[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                }
            }
        }
        $this->assertSame([], $sweep, 'one creator word on every page the pass touched');

        // 4. Video cards: the category tile has one word across its three keys
        $tile = ['ar' => 'مربع', 'de' => 'Kachel', 'es' => 'mosaico', 'fil' => 'tile', 'fr' => 'tuile', 'hi' => 'टाइल', 'id' => 'ubin', 'ja' => 'タイル',
            'ko' => '타일', 'ms' => 'jubin', 'pt' => 'bloco', 'ru' => 'плитк', 'th' => 'ไทล์', 'tl' => 'tile', 'tr' => 'kutuc', 'vi' => 'ô', 'zh' => '磁贴'];
        $tileWrong = [];
        foreach (['Category tile look', 'How category tiles look on the homepage and Explore.', 'Category tiles and creator pictures.'] as $key) {
            foreach (self::LOCALES as $locale) {
                if (! str_contains($low((string) ($file[$locale][$key] ?? '')), $low($tile[$locale]))) {
                    $tileWrong[] = "{$locale} {$key}: no \"{$tile[$locale]}\" in ".($file[$locale][$key] ?? '-');
                }
            }
        }
        $this->assertSame([], $tileWrong, 'the category tile has one word across its three keys');

        // 5. Video cards: 'Videos per row on computers' = the preview switch 'Computer'; the phones help's row word = the
        // labels; 'Ring around creator pictures' is a ring, not an order
        $computer = ['ar' => 'حاسوب', 'de' => 'Computer', 'es' => 'computadora', 'fil' => 'kompyuter', 'fr' => 'ordinateur', 'hi' => 'कंप्यूटर',
            'id' => 'komputer', 'ja' => 'コンピューター', 'ko' => '컴퓨터', 'ms' => 'komputer', 'pt' => 'computador', 'ru' => 'компьютер',
            'th' => 'คอมพิวเตอร์', 'tl' => 'kompyuter', 'tr' => 'bilgisayar', 'vi' => 'máy tính', 'zh' => '电脑'];
        $computerWrong = [];
        foreach (self::LOCALES as $locale) {
            if (! str_contains($low((string) ($file[$locale]['Videos per row on computers'] ?? '')), $low($computer[$locale]))) {
                $computerWrong[] = "{$locale}: no \"{$computer[$locale]}\" in ".($file[$locale]['Videos per row on computers'] ?? '-');
            }
        }
        $this->assertSame([], $computerWrong, "'Videos per row on computers' uses the preview switch's computer word");
        // (eleventh pass, TL-VERIFY9-2: the labels and this help say glossary_123.json's row word - the tenth pass pinned
        // the data-row word here, against its own glossary; test_every_page_says_the_census_concepts_one_way checks it)
        $this->assertSame([], $this->keysSaying(['One big video or two smaller ones per row.'],
            ['ru' => '/строк/u', 'tl' => '/(?<![a-z])row(?![a-z])|hilera/iu', 'fil' => '/(?<![a-z])row(?![a-z])|hilera/iu']),
            'the phones help names the row like the labels, not another word');
        $this->assertSame([], $this->keysSaying(['Ring around creator pictures'], [
            'de' => '/Ringen Sie/u', 'fr' => '/Faites le tour/u', 'hi' => '/घूमें/u', 'ko' => '/둘러보세요/u', 'tl' => '/Mag-ring/u', 'fil' => '/Mag-ring/u',
            'ru' => '/Обходите/u', 'th' => '/โทรหา/u', 'id' => '/Berkeliling/u', 'ja' => '/リングアラウンド/u', 'ms' => '/Dering/u', 'pt' => '/Circule/u',
            'tr' => '/halkalayın/u', 'zh' => '/环绕/u']), 'the creator-ring label is a ring, not an order');

        // 6. Homepage: the Row animation card description's row word; the six row helps' pt phone word
        $this->assertSame([], $this->keysSaying(['How homepage rows move. Also limited by Effects & speed.'],
            ['de' => '/Zeile/u', 'fr' => '/ligne/u', 'pt' => '/linha/u', 'ru' => '/строк/u', 'tl' => '/hilera/u', 'fil' => '/hilera/u']),
            'the Row animation description uses the homepage row word');
        $phoneHelps = [];
        foreach (['creators row', 'popular tags row', 'trending actors row'] as $row) {
            foreach (['videos per row', 'rows of videos'] as $col) {
                $phoneHelps[] = "How many to show ({$row}: {$col}). Fewer items load faster on phones.";
            }
        }
        $this->assertSame([], $this->keysSaying($phoneHelps, ['pt' => '/celular/u']), 'the homepage row helps use one pt phone word');

        // 7. the orchestrator's whole-page census (tools/p10/census_full.py) found three more one-word breaks on touched
        // pages: Settings > Advanced's creator-dashboard-box setting ('Rows in creator dashboard boxes' and its help)
        // named the box fr "zone" / "case" and fil/tl the English "box" while the "Creator studio helpers" card uses fr
        // "encadré" / fil/tl "kahon"; and the hidden 'Tap to preview on phones' said pt "celulares" while every other
        // phone text and the preview switch 'Mobile phone' say pt "telefone"
        $boxKeys = ['Rows in creator dashboard boxes', "How many items each box on a creator's own dashboard lists. (Not the admin Home.)"];
        $this->assertSame([], $this->keysSaying($boxKeys, [
            'fr' => '/\bzone|\bcase/u', 'fil' => '/(?<![a-z])box(?![a-z])/iu', 'tl' => '/(?<![a-z])box(?![a-z])/iu',
        ]), 'the creator-dashboard-box setting drops the stray box words');
        $boxWord = ['fr' => 'encadré', 'fil' => 'kahon', 'tl' => 'kahon'];
        $boxMissing = [];
        foreach ($boxKeys as $key) {
            foreach ($boxWord as $locale => $word) {
                if (! str_contains($low((string) ($file[$locale][$key] ?? '')), $word)) {
                    $boxMissing[] = "{$locale} {$key}: no \"{$word}\" in ".($file[$locale][$key] ?? '-');
                }
            }
        }
        $this->assertSame([], $boxMissing, 'the creator-dashboard-box setting uses the card box word');
        $this->assertSame([], $this->keysSaying(['Tap to preview on phones'], ['pt' => '/celular/u']), 'one pt phone word for the tap-to-preview label');
        $this->assertStringContainsString('telefone', $low((string) ($file['pt']['Tap to preview on phones'] ?? '')), 'pt tap-to-preview uses the phone switch word');
    }

    public function test_every_page_says_the_census_concepts_one_way(): void
    {
        // Eleventh pass (TL-VERIFY9-1..4): the REAL census - every text 22 admin pages print, rendered in every UI
        // language (tools/p11/census_render.py), and every text the sources use (tools/p11/global_census.py), checked
        // with glossary_123.json (written from the censuses' own definitions, tools/p11/gloss.py). These are its pins.
        $file = [];
        foreach (self::LOCALES as $locale) {
            $file[$locale] = $this->file($locale);
        }
        $low = fn (string $s): string => str_replace("\u{0307}", '', mb_strtolower($s));

        // 1. the old settings page names each homepage row, creator box section and the comeback checklist with its ONE
        // new name: the row headings, the "<row> moves" options and the ads tab's labels ARE the row's name, the texts
        // sub-headings start with it, the videos sub-headings are the 'videos under it' labels, the former names of the
        // rows' fields / the checklist / the creator & category visuals follow their new labels, two headings = their card
        $same = [
            'Smart Discovery Tags' => 'Popular tags row', 'Smart Discovery' => 'Popular tags row', 'Creator Spotlight' => 'Creators row',
            'Trending Actors' => 'Trending actors row', 'Smart Discovery Tag Videos' => 'Popular tags row: videos under it',
            'Creator Spotlight Videos' => 'Creators row: videos under it', 'Trending Actor Videos' => 'Trending actors row: videos under it',
            'Tags Enabled' => 'Popular tags row', 'Tag Item Limit' => 'Popular tags row: items', 'Discovery Window Days' => 'Popular tags row: counts the last',
            'Actors Enabled' => 'Trending actors row', 'Actor Item Limit' => 'Trending actors row: items',
            'Actor Lookback Days' => 'Trending actors row: counts the last', 'Checklist Enabled' => 'Comeback checklist for creators',
            'Checklist Limit' => 'Checklist steps shown', 'Inactivity Days' => 'Show it after no uploads for',
            'Creator Verified Checkmarks' => 'Verified check mark', 'Creator Avatar Ring Style' => 'Ring around creator pictures',
            'Creator Video Uploads' => 'Let members upload videos', 'Creator Recovery Checklist' => 'Comeback checklist for creators',
            'Creator & category visuals' => 'Categories & creators',
        ];
        $starts = ['Smart Discovery Text' => 'Popular tags row', 'Creator Spotlight Text' => 'Creators row', 'Trending Actor Text' => 'Trending actors row'];
        $wrong = [];
        foreach (self::LOCALES as $locale) {
            foreach ($same as $old => $new) {
                if (($file[$locale][$old] ?? null) !== ($file[$locale][$new] ?? false)) {
                    $wrong[] = "{$locale} {$old}: ".($file[$locale][$old] ?? '-').' (want: '.($file[$locale][$new] ?? '-').')';
                }
            }
            foreach ($starts as $old => $row) {
                if (! str_starts_with((string) ($file[$locale][$old] ?? ''), (string) ($file[$locale][$row] ?? "\0"))) {
                    $wrong[] = "{$locale} {$old}: ".($file[$locale][$old] ?? '-').' (the row: '.($file[$locale][$row] ?? '-').')';
                }
            }
        }
        $this->assertSame([], $wrong, 'the old settings page names each row, box section and checklist with its one new name');
        $info = 'The Smart Discovery, Creator Spotlight and Trending Collections rails render in the Grid / Classic home layouts (Appearance → Home layout). The Cinema, YouTube Spotlight, Bento, TikTok Shorts and Cyberpunk HUD layouts use their own sections, so these switches have no effect there. Smart Discovery needs videos published within the lookback window; otherwise the category fallback is shown.';
        $legacy = array_fill_keys(array_values(array_diff(self::LOCALES, ['en'])), '/Smart Discovery|Creator Spotlight|Trending Collections|[Ll]ookback|Rampenlicht|聚焦|Sorotan|Tumpuan/u');
        $this->assertSame([], $this->keysSaying([$info, 'Home Smart Discovery widget video feed.', 'Home Creator Spotlight widget video feed.',
            'Home Trending Actors widget video feed.'], $legacy), 'the old page\'s info and ads texts name the rows with their new names');

        // 2. the creator: every text that names one - the visitors' texts too (footer tagline default, the homepage
        // creators row's defaults, 'Verified creator') - avoids the words glossary_123.json lists (another word for the
        // same person, God / the Creator, a file's maker, a feminine generic, English left in)
        $avoid = ['ar' => '/مبدع|منشئ|الخالق/u', 'de' => '/YouTuber|Schöpfer|Creator(?![\s-]*Studio)|[Cc]reatorn/u', 'es' => '/\bcreadoras?\b/u',
            'fr' => '/créatrices?\b|\bCreator\b(?![\s-]*Studio)/u', 'hi' => '/निर्माता|क्रिएटर/u', 'id' => '/[Kk]reator|[Pp]encipta/u',
            'ja' => '/作成者|制作者|クリエーター|投稿者|作者/u', 'ko' => '/제작자|작성자|창작자/u', 'pt' => '/\bcriadoras?\b/u', 'ru' => '/[Сс]оздател/u',
            'th' => '/ครีเอเตอร์/u', 'tl' => '/[Tt]agalikha|[Ll]umikha|[Mm]anlilikha/u', 'fil' => '/[Tt]agalikha|[Ll]umikha|[Mm]anlilikha/u',
            'tr' => '/[Oo]luşturucu|[Yy]aratıcı/u', 'vi' => '/[Nn]gười (sáng )?tạo/u', 'zh' => '/创建者|创造者/u'];
        $creatorKeys = ['Share and watch amazing videos. Upload original content and connect with creators worldwide.'];
        foreach (array_keys($this->required()) as $key) {
            if (preg_match('/\bcreators?\b/i', (string) $key) === 1 && $key !== 'Daylight Creator') {
                $creatorKeys[] = (string) $key;
            }
        }
        $this->assertGreaterThan(100, count($creatorKeys), 'the texts that name a creator');
        $creatorWrong = [];
        foreach ($avoid as $locale => $pattern) {
            foreach ($creatorKeys as $key) {
                $value = $file[$locale][$key] ?? null;
                if (is_string($value) && preg_match($pattern, $value, $m)) {
                    $creatorWrong[] = "{$locale} {$key}: \"{$m[0]}\" in {$value}";
                }
            }
        }
        $this->assertSame([], $creatorWrong, 'one creator word in every text that names a creator');

        // 3. the videos-per-row texts (Video cards, Creator channels, the old page, the ads tab) say the row word
        $rowKeys = ['Videos per row', 'Videos per row on computers', 'Videos per row on phones', 'One big video or two smaller ones per row.',
            'How videos look in lists, videos per row, labels and page sizes.', 'Videos Per Row', 'Video Rows', '1 video per row', '2 videos per row',
            'Featured items per row', 'Latest uploads per row', 'Playlists per row', 'Featured: per row', 'Latest uploads: per row', 'Playlists: per row',
            'Playlist video rows', 'Playlist videos per row', 'Rows and cards per row are the most a section shows: on smaller screens it shows fewer per row.',
            'Wide responsive row between video rows', 'Mobile Video Columns', 'Default Featured Items Per Row', 'Default Latest Uploads Items Per Row',
            'Default Playlists Per Row', 'Creators can change the featured rows and columns', 'Creators can change the latest uploads rows and columns',
            'Creators can change the playlists rows and columns'];
        $this->assertSame([], $this->keysMissing($rowKeys, ['de' => '/reihe/iu', 'fr' => '/rangée/iu', 'pt' => '/fileira/iu', 'ru' => '/[Рр]яд/u',
            'tl' => '/hanay/iu', 'fil' => '/hanay/iu', 'ko' => '/행/u', 'id' => '/baris/iu', 'ms' => '/baris/iu']), 'the videos-per-row texts say the row word');
        $this->assertSame([], $this->keysSaying($rowKeys, ['de' => '/Zeile/u', 'fr' => '/(?<!en )\blignes?\b/iu', 'pt' => '/\blinhas?\b/iu',
            'ru' => '/строк/iu', 'tl' => '/(?<![a-z])rows?(?![a-z])|hilera/iu', 'fil' => '/(?<![a-z])rows?(?![a-z])|hilera/iu', 'ko' => '/줄/u']),
            'the videos-per-row texts do not say the data-row word');

        // 4. Video cards, 'Categories & creators': ONE ring word on the label, its help and every option (never a finger
        // ring, a bell, a heartbeat or a border word next to a ring word); id 'Video count on categories' is a count
        $ringKeys = ['Ring around creator pictures', 'A coloured ring, a plain ring, or none.', 'Pulsing ring', 'A coloured ring that pulses.',
            'Colour ring', 'A soft gradient ring.', 'Plain ring', 'A thin ring in your colour.', 'No ring', 'Creator Avatar Ring Style'];
        $this->assertSame([], $this->keysMissing($ringKeys, ['ar' => '/حلقة/u', 'de' => '/ring/iu', 'es' => '/anillo/iu', 'fr' => '/anneau/iu',
            'hi' => '/रिंग/u', 'id' => '/cincin/iu', 'ja' => '/リング/u', 'ko' => '/테두리/u', 'ms' => '/cincin/iu', 'pt' => '/anel/iu', 'ru' => '/кольц/iu',
            'th' => '/วงแหวน/u', 'tl' => '/singsing/iu', 'fil' => '/singsing/iu', 'tr' => '/halka/iu', 'vi' => '/viền/iu', 'zh' => '/圆环/u']),
            'one ring word on the ring setting');
        $this->assertSame([], $this->keysSaying($ringKeys, ['ar' => '/خاتم/u', 'es' => '/\baro\b/u', 'fr' => '/bague/iu', 'hi' => '/अंगूठी|वलय|घेरा/u',
            'id' => '/[Ll]ingkaran/u', 'ja' => '/カラーリング/u', 'ko' => '/반지|컬러링|(?<![가-힣])링(?![가-힣])/u', 'ms' => '/[Ll]ingkaran/u', 'pt' => '/\baro\b/u',
            'ru' => '/обводк/u', 'th' => '/(?<!วง)แหวน/u', 'tr' => '/[Yy]üzük|[Zz]il\b|Nabız/u', 'vi' => '/[Vv]òng|nhẫn/u', 'zh' => '/戒指|光环|边框/u']),
            'the ring is not a finger ring, a bell or another word');
        $this->assertStringNotContainsString('mengandalkan', (string) ($file['id']['Video count on categories'] ?? ''), "id: not 'videos rely on categories'");

        // 5. Settings > Advanced, 'Creator studio helpers': the comeback checklist named one way (label, steps, both
        // helps, the old page's heading and switch), never "recovery"; the tagline's characters are letters
        $comeback = ['Comeback checklist for creators', 'How many steps the comeback checklist shows.',
            'Days without uploads before the comeback checklist appears.', 'Creator Recovery Checklist', 'Checklist Enabled'];
        $this->assertSame([], $this->keysMissing($comeback, ['ar' => '/قائمة العودة/u', 'de' => '/Comeback-Checkliste/u',
            'es' => '/lista de verificación de regreso/iu', 'fr' => '/liste de contrôle de retour/iu', 'hi' => '/वापसी चेकलिस्ट/u',
            'id' => '/daftar periksa untuk kembali/iu', 'ja' => '/復帰チェックリスト/u', 'ko' => '/복귀 체크리스트/u', 'ms' => '/senarai semak kembali/iu',
            'pt' => '/lista de verificação de retorno/iu', 'ru' => '/контрольн\w* спис\w* возвращения/iu', 'th' => '/รายการตรวจสอบการกลับมา/u',
            'tl' => '/checklist ng pagbabalik/iu', 'fil' => '/checklist ng pagbabalik/iu', 'tr' => '/geri dönüş kontrol listes/iu',
            'vi' => '/danh sách việc cần làm/iu', 'zh' => '/复出清单/u']), 'the comeback checklist has one name');
        $this->assertSame([], $this->keysSaying(array_merge($comeback, ['Checklist steps shown', 'Checklist Limit']), ['ar' => '/استرداد|قائمة التحقق|قائمة مرجعية/u',
            'de' => '/Wiederherstellung/u', 'es' => '/recuperación|para volver/u', 'fr' => '/récupération|reprise/u', 'hi' => '/पुनर्प्राप्ति/u',
            'id' => '/[Pp]emulihan|kembalinya/u', 'ja' => '/回復|カムバック/u', 'ko' => '/복구|컴백/u', 'ms' => '/[Pp]emulihan/u', 'pt' => '/recuperação/u',
            'ru' => '/восстановлени/u', 'th' => '/กู้คืน|คัมแบ็ก/u', 'tl' => '/[Pp]agbawi|[Cc]omeback/u', 'fil' => '/[Pp]agbawi|[Cc]omeback/u',
            'tr' => '/[Kk]urtarma/u', 'vi' => '/khôi phục|danh sách kiểm tra/u', 'zh' => '/恢复/u']), 'the comeback checklist is not a recovery');
        $this->assertSame([], $this->keysSaying(['Number of characters creators may use for their channel tagline.'], ['ar' => '/شخصي/u',
            'es' => '/personaje/u', 'fr' => '/personnage/u', 'pt' => '/personage/u', 'ru' => '/персонаж/u', 'th' => '/ตัวละคร/u']),
            'the tagline\'s characters are letters, not story characters');

        // 6. computers and phones: the preview switch's words on every admin page and the account page's sign-out texts
        $computerKeys = ['A large soft glow follows the mouse on computers.', 'A soft light follows the mouse across cards on computers.',
            'Cards tilt toward the mouse on computers.', 'Computers get every effect you chose', 'Runs only on strong computers.',
            'Side menu open on computers', 'Slow on phones and older computers. Use one at most.', 'Subtle, glass or in your theme colour (computers only).',
            'strong computers only', 'Every other phone, computer and app is signed out. This device stays signed in.',
            'Recommended. Your other phones, computers and apps will ask for the new password.'];
        $this->assertSame([], $this->keysSaying($computerKeys, ['ar' => '/كمبيوتر/u', 'es' => '/ordenador/u', 'ja' => '/パソコン/u',
            'tl' => '/(?<![a-z])[Cc]omputer/u', 'fil' => '/(?<![a-z])[Cc]omputer/u', 'zh' => '/计算机/u']), 'one computer word');
        $phoneKeys = ['(phones only)', 'A slow light sweep across colourful headings. Off on slow phones automatically.',
            'Adds the moving particle background some daily designs use. Costs speed on phones.', 'Bars show speed on a weak phone: more bars is faster.',
            'Every effect, including 3D tilt and moving backgrounds. Weak phones still get a lighter version.',
            'Glass is drawn solid on phones, on slower devices and when reduced transparency or motion is set.',
            "How many to show (playlists on a channel's home tab). Fewer items load faster on phones.",
            "How many to show (videos on a channel's home tab). Fewer items load faster on phones.",
            'Short clips, anime, fan edits, phone-first audiences.', 'Slides in from the side (from the bottom on phones).',
            'Strong blur and colour behind cards. Heavy on phones.',
            'Uses true black backgrounds, which saves battery on OLED phones. Replaces the dark background colours.', 'not on weak phones',
            'Every other phone, computer and app is signed out. This device stays signed in.',
            'Recommended. Your other phones, computers and apps will ask for the new password.'];
        $this->assertSame([], $this->keysSaying($phoneKeys, ['de' => '/Handy/u', 'es' => '/móvil/u', 'fr' => '/\bmobile\b/u', 'hi' => '/फोन/u',
            'id' => '/[Tt]elepon(?!o)/u', 'ja' => '/電話|スマートフォン/u', 'ko' => '/전화기|휴대전화|(?<![가-힣])폰/u', 'pt' => '/celular/u',
            'th' => '/มือถือ/u', 'zh' => '/电话/u']), 'one phone word');

        // 7. Money > Premium page text: one name per feature box on its 25 texts, the icon helps change the icon (no box
        // named "Changes"), the switches show the box / row (they download nothing), ru downloads and uploads are two rows
        $boxName = ['ar' => 'مربع الميزة', 'de' => 'Feature-Box', 'es' => 'cuadro de funciones', 'fr' => 'encadré de fonctionnalité',
            'hi' => 'फ़ीचर बॉक्स', 'id' => 'kotak fitur', 'ja' => '機能ボックス', 'ko' => '기능 상자', 'ms' => 'kotak ciri', 'pt' => 'caixa de recursos',
            'ru' => 'блок', 'th' => 'กล่องฟีเจอร์', 'tl' => 'kahon ng feature', 'fil' => 'kahon ng feature', 'tr' => 'özellik kutu', 'vi' => 'ô tính năng',
            'zh' => '功能框'];
        $boxWrong = [];
        $changes = [];
        foreach (['uploads', 'downloads', 'no ads'] as $f) {
            $changes[] = "Changes feature box ({$f}): icon. Use Preview to see it before publishing.";
            $keys = ["Feature box: {$f}", "Feature box ({$f}): title", "Feature box ({$f}): description", "Feature box ({$f}): icon",
                "The words shown for feature box ({$f}): title. Leave empty to use the default text (translated automatically).",
                "The sentence shown for feature box ({$f}): description. Keep it short; it is read on phones too.",
                "Changes feature box ({$f}): icon. Use Preview to see it before publishing."];
            if ($f !== 'downloads') {  // 'Shows feature box: downloads ...' is no source's text any more
                $keys[] = "Shows feature box: {$f} when on. Turn it off to hide it for every visitor.";
            }
            foreach ($keys as $key) {
                foreach ($boxName as $locale => $name) {
                    if (! str_contains($low((string) ($file[$locale][$key] ?? '')), $low($name))) {
                        $boxWrong[] = "{$locale} {$key}: no \"{$name}\" in ".($file[$locale][$key] ?? '-');
                    }
                }
            }
        }
        $this->assertSame([], $boxWrong, 'one name per feature box on the Premium page text page');
        $this->assertSame([], $this->keysSaying($changes, ['de' => '/Änderungen/u', 'ru' => '/Изменения/u', 'fr' => '/modifications/u', 'ar' => '/التغييرات/u',
            'ja' => '/変更機能/u', 'ko' => '/변경 기능/u', 'hi' => '/परिवर्तन/u', 'id' => '/Perubahan/u', 'ms' => '/[Pp]erubahan/u', 'pt' => '/alterações/u',
            'th' => '/การเปลี่ยนแปลง/u', 'tl' => '/Mga pagbabago/u', 'fil' => '/Mga pagbabago/u']), '"Changes" is the verb, not a box\'s name');
        $this->assertSame([], $this->keysSaying(['Shows feature box: uploads when on. Turn it off to hide it for every visitor.',
            'Shows comparison row: downloads when on. Turn it off to hide it for every visitor.',
            'Shows comparison row: uploads when on. Turn it off to hide it for every visitor.'], [
            'ja' => '/オンの場合は(ダウンロード|アップロード)します/u', 'ko' => '/켜져 있으면 (다운로드|업로드)됩니다/u', 'hi' => '/चालू होने पर (डाउनलोड|अपलोड) होता है/u',
            'id' => '/(mengunduh|diunduh|diunggah) saat aktif/u', 'zh' => '/打开时(下载|上传)/u', 'tr' => '/açıkken (indirilir|yüklenir)/u',
            'fr' => "/télécharge lorsqu'il/u", 'th' => '/(ดาวน์โหลด|อัปโหลด)เมื่อเปิด/u']), 'the switch shows the box or row; it does not download or upload');
        $this->assertNotSame($file['ru']['Comparison row: downloads'] ?? '', $file['ru']['Comparison row: uploads'] ?? '', 'ru: downloads and uploads are two rows');

        // 8. tiles: the card shape 'Rounded tile' and the category tile's options are tiles (not boxes, cards or floor /
        // roof tiling); the old page's hero layouts use the Homepage page's tile word; the channel box is no cardboard box
        $this->assertSame([], $this->keysSaying(['Rounded tile', 'Soft tile with rounded corners.', 'See-through tile.'], ['de' => '/Box/u',
            'es' => '/caja/iu', 'fr' => '/[Bb]oîte|[Cc]arrelage/u', 'ru' => '/коробк|черепиц/iu', 'ja' => '/箱/u', 'zh' => '/盒|卡片/u',
            'tr' => '/\bkaro\b|\bkutu\b/u', 'ar' => '/صندوق|بطاقة/u', 'id' => '/[Kk]otak/u', 'tl' => '/[Kk]ahon/u', 'fil' => '/[Kk]ahon/u']),
            'a tile is a tile, not a box, a card or floor tiling');
        $this->assertSame([], $this->keysSaying(['Apple / Swiss Bento Box (Asymmetric Dynamic Tiles)', 'Bento Grid Spotlight (1 Featured Hero + 3 Dynamic Tiles)'],
            ['ar' => '/صناديق/u', 'es' => '/[Cc]ajas|(?<!re)cuadros/u', 'fr' => '/[Bb]oîtes/u', 'ru' => '/коробк/u']), 'the hero tiles as the Homepage page names them');
        $this->assertSame([], $this->keysSaying(['Channel box'], ['ru' => '/Коробк/u', 'zh' => '/通道/u']), 'the channel box is not a cardboard box or a waterway');
    }
}
