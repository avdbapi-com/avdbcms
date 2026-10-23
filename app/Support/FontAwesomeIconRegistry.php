<?php

namespace App\Support;

/**
 * The Font Awesome solid icon names shipped with this application.
 *
 * This list is generated from @fortawesome/fontawesome-free 6.7.2
 * metadata/icons.yml (entries whose styles include "solid"). Keeping the
 * registry in PHP means production installs do not need node_modules just to
 * validate a stored icon name.
 */
final class FontAwesomeIconRegistry
{
    public const string FALLBACK = 'folder';

    private const string SOLID_ICON_NAMES =
        '0|1|2|3|4|5|6|7|8|9|a|address-book|address-card|align-center|align-justify|align-left|align-right|anchor'.
        '|anchor-circle-check|anchor-circle-exclamation|anchor-circle-xmark|anchor-lock|angle-down|angle-left|angle-right|angle-up'.
        '|angles-down|angles-left|angles-right|angles-up|ankh|apple-whole|archway|arrow-down|arrow-down-1-9|arrow-down-9-1'.
        '|arrow-down-a-z|arrow-down-long|arrow-down-short-wide|arrow-down-up-across-line|arrow-down-up-lock|arrow-down-wide-short'.
        '|arrow-down-z-a|arrow-left|arrow-left-long|arrow-pointer|arrow-right|arrow-right-arrow-left|arrow-right-from-bracket'.
        '|arrow-right-long|arrow-right-to-bracket|arrow-right-to-city|arrow-rotate-left|arrow-rotate-right|arrow-trend-down'.
        '|arrow-trend-up|arrow-turn-down|arrow-turn-up|arrow-up|arrow-up-1-9|arrow-up-9-1|arrow-up-a-z|arrow-up-from-bracket'.
        '|arrow-up-from-ground-water|arrow-up-from-water-pump|arrow-up-long|arrow-up-right-dots|arrow-up-right-from-square'.
        '|arrow-up-short-wide|arrow-up-wide-short|arrow-up-z-a|arrows-down-to-line|arrows-down-to-people|arrows-left-right'.
        '|arrows-left-right-to-line|arrows-rotate|arrows-spin|arrows-split-up-and-left|arrows-to-circle|arrows-to-dot'.
        '|arrows-to-eye|arrows-turn-right|arrows-turn-to-dots|arrows-up-down|arrows-up-down-left-right|arrows-up-to-line|asterisk'.
        '|at|atom|audio-description|austral-sign|award|b|baby|baby-carriage|backward|backward-fast|backward-step|bacon|bacteria'.
        '|bacterium|bag-shopping|bahai|baht-sign|ban|ban-smoking|bandage|bangladeshi-taka-sign|barcode|bars|bars-progress'.
        '|bars-staggered|baseball|baseball-bat-ball|basket-shopping|basketball|bath|battery-empty|battery-full|battery-half'.
        '|battery-quarter|battery-three-quarters|bed|bed-pulse|beer-mug-empty|bell|bell-concierge|bell-slash|bezier-curve|bicycle'.
        '|binoculars|biohazard|bitcoin-sign|blender|blender-phone|blog|bold|bolt|bolt-lightning|bomb|bone|bong|book|book-atlas'.
        '|book-bible|book-bookmark|book-journal-whills|book-medical|book-open|book-open-reader|book-quran|book-skull|book-tanakh'.
        '|bookmark|border-all|border-none|border-top-left|bore-hole|bottle-droplet|bottle-water|bowl-food|bowl-rice|bowling-ball'.
        '|box|box-archive|box-open|box-tissue|boxes-packing|boxes-stacked|braille|brain|brazilian-real-sign|bread-slice|bridge'.
        '|bridge-circle-check|bridge-circle-exclamation|bridge-circle-xmark|bridge-lock|bridge-water|briefcase|briefcase-medical'.
        '|broom|broom-ball|brush|bucket|bug|bug-slash|bugs|building|building-circle-arrow-right|building-circle-check'.
        '|building-circle-exclamation|building-circle-xmark|building-columns|building-flag|building-lock|building-ngo'.
        '|building-shield|building-un|building-user|building-wheat|bullhorn|bullseye|burger|burst|bus|bus-simple|business-time|c'.
        '|cable-car|cake-candles|calculator|calendar|calendar-check|calendar-day|calendar-days|calendar-minus|calendar-plus'.
        '|calendar-week|calendar-xmark|camera|camera-retro|camera-rotate|campground|candy-cane|cannabis|capsules|car|car-battery'.
        '|car-burst|car-on|car-rear|car-side|car-tunnel|caravan|caret-down|caret-left|caret-right|caret-up|carrot|cart-arrow-down'.
        '|cart-flatbed|cart-flatbed-suitcase|cart-plus|cart-shopping|cash-register|cat|cedi-sign|cent-sign|certificate|chair'.
        '|chalkboard|chalkboard-user|champagne-glasses|charging-station|chart-area|chart-bar|chart-column|chart-diagram'.
        '|chart-gantt|chart-line|chart-pie|chart-simple|check|check-double|check-to-slot|cheese|chess|chess-bishop|chess-board'.
        '|chess-king|chess-knight|chess-pawn|chess-queen|chess-rook|chevron-down|chevron-left|chevron-right|chevron-up|child'.
        '|child-combatant|child-dress|child-reaching|children|church|circle|circle-arrow-down|circle-arrow-left|circle-arrow-right'.
        '|circle-arrow-up|circle-check|circle-chevron-down|circle-chevron-left|circle-chevron-right|circle-chevron-up'.
        '|circle-dollar-to-slot|circle-dot|circle-down|circle-exclamation|circle-h|circle-half-stroke|circle-info|circle-left'.
        '|circle-minus|circle-nodes|circle-notch|circle-pause|circle-play|circle-plus|circle-question|circle-radiation'.
        '|circle-right|circle-stop|circle-up|circle-user|circle-xmark|city|clapperboard|clipboard|clipboard-check|clipboard-list'.
        '|clipboard-question|clipboard-user|clock|clock-rotate-left|clone|closed-captioning|cloud|cloud-arrow-down|cloud-arrow-up'.
        '|cloud-bolt|cloud-meatball|cloud-moon|cloud-moon-rain|cloud-rain|cloud-showers-heavy|cloud-showers-water|cloud-sun'.
        '|cloud-sun-rain|clover|code|code-branch|code-commit|code-compare|code-fork|code-merge|code-pull-request|coins|colon-sign'.
        '|comment|comment-dollar|comment-dots|comment-medical|comment-nodes|comment-slash|comment-sms|comments|comments-dollar'.
        '|compact-disc|compass|compass-drafting|compress|computer|computer-mouse|cookie|cookie-bite|copy|copyright|couch|cow'.
        '|credit-card|crop|crop-simple|cross|crosshairs|crow|crown|crutch|cruzeiro-sign|cube|cubes|cubes-stacked|d|database'.
        '|delete-left|democrat|desktop|dharmachakra|diagram-next|diagram-predecessor|diagram-project|diagram-successor|diamond'.
        '|diamond-turn-right|dice|dice-d20|dice-d6|dice-five|dice-four|dice-one|dice-six|dice-three|dice-two|disease|display'.
        '|divide|dna|dog|dollar-sign|dolly|dong-sign|door-closed|door-open|dove|down-left-and-up-right-to-center|down-long'.
        '|download|dragon|draw-polygon|droplet|droplet-slash|drum|drum-steelpan|drumstick-bite|dumbbell|dumpster|dumpster-fire'.
        '|dungeon|e|ear-deaf|ear-listen|earth-africa|earth-americas|earth-asia|earth-europe|earth-oceania|egg|eject|elevator'.
        '|ellipsis|ellipsis-vertical|envelope|envelope-circle-check|envelope-open|envelope-open-text|envelopes-bulk|equals|eraser'.
        '|ethernet|euro-sign|exclamation|expand|explosion|eye|eye-dropper|eye-low-vision|eye-slash|f|face-angry|face-dizzy'.
        '|face-flushed|face-frown|face-frown-open|face-grimace|face-grin|face-grin-beam|face-grin-beam-sweat|face-grin-hearts'.
        '|face-grin-squint|face-grin-squint-tears|face-grin-stars|face-grin-tears|face-grin-tongue|face-grin-tongue-squint'.
        '|face-grin-tongue-wink|face-grin-wide|face-grin-wink|face-kiss|face-kiss-beam|face-kiss-wink-heart|face-laugh'.
        '|face-laugh-beam|face-laugh-squint|face-laugh-wink|face-meh|face-meh-blank|face-rolling-eyes|face-sad-cry|face-sad-tear'.
        '|face-smile|face-smile-beam|face-smile-wink|face-surprise|face-tired|fan|faucet|faucet-drip|fax|feather|feather-pointed'.
        '|ferry|file|file-arrow-down|file-arrow-up|file-audio|file-circle-check|file-circle-exclamation|file-circle-minus'.
        '|file-circle-plus|file-circle-question|file-circle-xmark|file-code|file-contract|file-csv|file-excel|file-export'.
        '|file-fragment|file-half-dashed|file-image|file-import|file-invoice|file-invoice-dollar|file-lines|file-medical|file-pdf'.
        '|file-pen|file-powerpoint|file-prescription|file-shield|file-signature|file-video|file-waveform|file-word|file-zipper'.
        '|fill|fill-drip|film|filter|filter-circle-dollar|filter-circle-xmark|fingerprint|fire|fire-burner|fire-extinguisher'.
        '|fire-flame-curved|fire-flame-simple|fish|fish-fins|flag|flag-checkered|flag-usa|flask|flask-vial|floppy-disk|florin-sign'.
        '|folder|folder-closed|folder-minus|folder-open|folder-plus|folder-tree|font|font-awesome|football|forward|forward-fast'.
        '|forward-step|franc-sign|frog|futbol|g|gamepad|gas-pump|gauge|gauge-high|gauge-simple|gauge-simple-high|gavel|gear|gears'.
        '|gem|genderless|ghost|gift|gifts|glass-water|glass-water-droplet|glasses|globe|golf-ball-tee|gopuram|graduation-cap'.
        '|greater-than|greater-than-equal|grip|grip-lines|grip-lines-vertical|grip-vertical|group-arrows-rotate|guarani-sign'.
        '|guitar|gun|h|hammer|hamsa|hand|hand-back-fist|hand-dots|hand-fist|hand-holding|hand-holding-dollar|hand-holding-droplet'.
        '|hand-holding-hand|hand-holding-heart|hand-holding-medical|hand-lizard|hand-middle-finger|hand-peace|hand-point-down'.
        '|hand-point-left|hand-point-right|hand-point-up|hand-pointer|hand-scissors|hand-sparkles|hand-spock|handcuffs|hands'.
        '|hands-asl-interpreting|hands-bound|hands-bubbles|hands-clapping|hands-holding|hands-holding-child|hands-holding-circle'.
        '|hands-praying|handshake|handshake-angle|handshake-simple|handshake-simple-slash|handshake-slash|hanukiah|hard-drive'.
        '|hashtag|hat-cowboy|hat-cowboy-side|hat-wizard|head-side-cough|head-side-cough-slash|head-side-mask|head-side-virus'.
        '|heading|headphones|headphones-simple|headset|heart|heart-circle-bolt|heart-circle-check|heart-circle-exclamation'.
        '|heart-circle-minus|heart-circle-plus|heart-circle-xmark|heart-crack|heart-pulse|helicopter|helicopter-symbol'.
        '|helmet-safety|helmet-un|hexagon-nodes|hexagon-nodes-bolt|highlighter|hill-avalanche|hill-rockslide|hippo|hockey-puck'.
        '|holly-berry|horse|horse-head|hospital|hospital-user|hot-tub-person|hotdog|hotel|hourglass|hourglass-end|hourglass-half'.
        '|hourglass-start|house|house-chimney|house-chimney-crack|house-chimney-medical|house-chimney-user|house-chimney-window'.
        '|house-circle-check|house-circle-exclamation|house-circle-xmark|house-crack|house-fire|house-flag|house-flood-water'.
        '|house-flood-water-circle-arrow-right|house-laptop|house-lock|house-medical|house-medical-circle-check'.
        '|house-medical-circle-exclamation|house-medical-circle-xmark|house-medical-flag|house-signal|house-tsunami|house-user'.
        '|hryvnia-sign|hurricane|i|i-cursor|ice-cream|icicles|icons|id-badge|id-card|id-card-clip|igloo|image|image-portrait'.
        '|images|inbox|indent|indian-rupee-sign|industry|infinity|info|italic|j|jar|jar-wheat|jedi|jet-fighter|jet-fighter-up'.
        '|joint|jug-detergent|k|kaaba|key|keyboard|khanda|kip-sign|kit-medical|kitchen-set|kiwi-bird|l|land-mine-on|landmark'.
        '|landmark-dome|landmark-flag|language|laptop|laptop-code|laptop-file|laptop-medical|lari-sign|layer-group|leaf|left-long'.
        '|left-right|lemon|less-than|less-than-equal|life-ring|lightbulb|lines-leaning|link|link-slash|lira-sign|list|list-check'.
        '|list-ol|list-ul|litecoin-sign|location-arrow|location-crosshairs|location-dot|location-pin|location-pin-lock|lock'.
        '|lock-open|locust|lungs|lungs-virus|m|magnet|magnifying-glass|magnifying-glass-arrow-right|magnifying-glass-chart'.
        '|magnifying-glass-dollar|magnifying-glass-location|magnifying-glass-minus|magnifying-glass-plus|manat-sign|map'.
        '|map-location|map-location-dot|map-pin|marker|mars|mars-and-venus|mars-and-venus-burst|mars-double|mars-stroke'.
        '|mars-stroke-right|mars-stroke-up|martini-glass|martini-glass-citrus|martini-glass-empty|mask|mask-face|mask-ventilator'.
        '|masks-theater|mattress-pillow|maximize|medal|memory|menorah|mercury|message|meteor|microchip|microphone|microphone-lines'.
        '|microphone-lines-slash|microphone-slash|microscope|mill-sign|minimize|minus|mitten|mobile|mobile-button|mobile-retro'.
        '|mobile-screen|mobile-screen-button|money-bill|money-bill-1|money-bill-1-wave|money-bill-transfer|money-bill-trend-up'.
        '|money-bill-wave|money-bill-wheat|money-bills|money-check|money-check-dollar|monument|moon|mortar-pestle|mosque|mosquito'.
        '|mosquito-net|motorcycle|mound|mountain|mountain-city|mountain-sun|mug-hot|mug-saucer|music|n|naira-sign|network-wired'.
        '|neuter|newspaper|not-equal|notdef|note-sticky|notes-medical|o|object-group|object-ungroup|oil-can|oil-well|om|otter'.
        '|outdent|p|pager|paint-roller|paintbrush|palette|pallet|panorama|paper-plane|paperclip|parachute-box|paragraph|passport'.
        '|paste|pause|paw|peace|pen|pen-clip|pen-fancy|pen-nib|pen-ruler|pen-to-square|pencil|people-arrows|people-carry-box'.
        '|people-group|people-line|people-pulling|people-robbery|people-roof|pepper-hot|percent|person|person-arrow-down-to-line'.
        '|person-arrow-up-from-line|person-biking|person-booth|person-breastfeeding|person-burst|person-cane|person-chalkboard'.
        '|person-circle-check|person-circle-exclamation|person-circle-minus|person-circle-plus|person-circle-question'.
        '|person-circle-xmark|person-digging|person-dots-from-line|person-dress|person-dress-burst|person-drowning|person-falling'.
        '|person-falling-burst|person-half-dress|person-harassing|person-hiking|person-military-pointing|person-military-rifle'.
        '|person-military-to-person|person-praying|person-pregnant|person-rays|person-rifle|person-running|person-shelter'.
        '|person-skating|person-skiing|person-skiing-nordic|person-snowboarding|person-swimming|person-through-window'.
        '|person-walking|person-walking-arrow-loop-left|person-walking-arrow-right|person-walking-dashed-line-arrow-right'.
        '|person-walking-luggage|person-walking-with-cane|peseta-sign|peso-sign|phone|phone-flip|phone-slash|phone-volume'.
        '|photo-film|piggy-bank|pills|pizza-slice|place-of-worship|plane|plane-arrival|plane-circle-check|plane-circle-exclamation'.
        '|plane-circle-xmark|plane-departure|plane-lock|plane-slash|plane-up|plant-wilt|plate-wheat|play|plug|plug-circle-bolt'.
        '|plug-circle-check|plug-circle-exclamation|plug-circle-minus|plug-circle-plus|plug-circle-xmark|plus|plus-minus|podcast'.
        '|poo|poo-storm|poop|power-off|prescription|prescription-bottle|prescription-bottle-medical|print|pump-medical|pump-soap'.
        '|puzzle-piece|q|qrcode|question|quote-left|quote-right|r|radiation|radio|rainbow|ranking-star|receipt|record-vinyl'.
        '|rectangle-ad|rectangle-list|rectangle-xmark|recycle|registered|repeat|reply|reply-all|republican|restroom|retweet|ribbon'.
        '|right-from-bracket|right-left|right-long|right-to-bracket|ring|road|road-barrier|road-bridge|road-circle-check'.
        '|road-circle-exclamation|road-circle-xmark|road-lock|road-spikes|robot|rocket|rotate|rotate-left|rotate-right|route|rss'.
        '|ruble-sign|rug|ruler|ruler-combined|ruler-horizontal|ruler-vertical|rupee-sign|rupiah-sign|s|sack-dollar|sack-xmark'.
        '|sailboat|satellite|satellite-dish|scale-balanced|scale-unbalanced|scale-unbalanced-flip|school|school-circle-check'.
        '|school-circle-exclamation|school-circle-xmark|school-flag|school-lock|scissors|screwdriver|screwdriver-wrench|scroll'.
        '|scroll-torah|sd-card|section|seedling|server|shapes|share|share-from-square|share-nodes|sheet-plastic|shekel-sign|shield'.
        '|shield-cat|shield-dog|shield-halved|shield-heart|shield-virus|ship|shirt|shoe-prints|shop|shop-lock|shop-slash|shower'.
        '|shrimp|shuffle|shuttle-space|sign-hanging|signal|signature|signs-post|sim-card|sink|sitemap|skull|skull-crossbones|slash'.
        '|sleigh|sliders|smog|smoking|snowflake|snowman|snowplow|soap|socks|solar-panel|sort|sort-down|sort-up|spa'.
        '|spaghetti-monster-flying|spell-check|spider|spinner|splotch|spoon|spray-can|spray-can-sparkles|square'.
        '|square-arrow-up-right|square-binary|square-caret-down|square-caret-left|square-caret-right|square-caret-up|square-check'.
        '|square-envelope|square-full|square-h|square-minus|square-nfi|square-parking|square-pen|square-person-confined'.
        '|square-phone|square-phone-flip|square-plus|square-poll-horizontal|square-poll-vertical|square-root-variable|square-rss'.
        '|square-share-nodes|square-up-right|square-virus|square-xmark|staff-snake|stairs|stamp|stapler|star|star-and-crescent'.
        '|star-half|star-half-stroke|star-of-david|star-of-life|sterling-sign|stethoscope|stop|stopwatch|stopwatch-20|store'.
        '|store-slash|street-view|strikethrough|stroopwafel|subscript|suitcase|suitcase-medical|suitcase-rolling|sun'.
        '|sun-plant-wilt|superscript|swatchbook|synagogue|syringe|t|table|table-cells|table-cells-column-lock|table-cells-large'.
        '|table-cells-row-lock|table-cells-row-unlock|table-columns|table-list|table-tennis-paddle-ball|tablet|tablet-button'.
        '|tablet-screen-button|tablets|tachograph-digital|tag|tags|tape|tarp|tarp-droplet|taxi|teeth|teeth-open'.
        '|temperature-arrow-down|temperature-arrow-up|temperature-empty|temperature-full|temperature-half|temperature-high'.
        '|temperature-low|temperature-quarter|temperature-three-quarters|tenge-sign|tent|tent-arrow-down-to-line'.
        '|tent-arrow-left-right|tent-arrow-turn-left|tent-arrows-down|tents|terminal|text-height|text-slash|text-width|thermometer'.
        '|thumbs-down|thumbs-up|thumbtack|thumbtack-slash|ticket|ticket-simple|timeline|toggle-off|toggle-on|toilet|toilet-paper'.
        '|toilet-paper-slash|toilet-portable|toilets-portable|toolbox|tooth|torii-gate|tornado|tower-broadcast|tower-cell'.
        '|tower-observation|tractor|trademark|traffic-light|trailer|train|train-subway|train-tram|transgender|trash|trash-arrow-up'.
        '|trash-can|trash-can-arrow-up|tree|tree-city|triangle-exclamation|trophy|trowel|trowel-bricks|truck|truck-arrow-right'.
        '|truck-droplet|truck-fast|truck-field|truck-field-un|truck-front|truck-medical|truck-monster|truck-moving|truck-pickup'.
        '|truck-plane|truck-ramp-box|tty|turkish-lira-sign|turn-down|turn-up|tv|u|umbrella|umbrella-beach|underline'.
        '|universal-access|unlock|unlock-keyhole|up-down|up-down-left-right|up-long|up-right-and-down-left-from-center'.
        '|up-right-from-square|upload|user|user-astronaut|user-check|user-clock|user-doctor|user-gear|user-graduate|user-group'.
        '|user-injured|user-large|user-large-slash|user-lock|user-minus|user-ninja|user-nurse|user-pen|user-plus|user-secret'.
        '|user-shield|user-slash|user-tag|user-tie|user-xmark|users|users-between-lines|users-gear|users-line|users-rays'.
        '|users-rectangle|users-slash|users-viewfinder|utensils|v|van-shuttle|vault|vector-square|venus|venus-double|venus-mars'.
        '|vest|vest-patches|vial|vial-circle-check|vial-virus|vials|video|video-slash|vihara|virus|virus-covid|virus-covid-slash'.
        '|virus-slash|viruses|voicemail|volcano|volleyball|volume-high|volume-low|volume-off|volume-xmark|vr-cardboard|w'.
        '|walkie-talkie|wallet|wand-magic|wand-magic-sparkles|wand-sparkles|warehouse|water|water-ladder|wave-square|web-awesome'.
        '|weight-hanging|weight-scale|wheat-awn|wheat-awn-circle-exclamation|wheelchair|wheelchair-move|whiskey-glass|wifi|wind'.
        '|window-maximize|window-minimize|window-restore|wine-bottle|wine-glass|wine-glass-empty|won-sign|worm|wrench|x|x-ray'.
        '|xmark|xmarks-lines|y|yen-sign|yin-yang|z';

    /**
     * Legacy Free aliases mapped to their canonical solid icon names.
     *
     * This is generated from metadata/icons.yml aliases.names. Aliases are
     * accepted by normalize(), but are deliberately excluded from all() and
     * isSupported() so new stored values stay canonical.
     */
    private const string SOLID_ICON_ALIASES =
        'contact-book=address-book|contact-card=address-card|vcard=address-card|angle-double-down=angles-down'.
        '|angle-double-left=angles-left|angle-double-right=angles-right|angle-double-up=angles-up|apple-alt=apple-whole'.
        '|sort-numeric-asc=arrow-down-1-9|sort-numeric-down=arrow-down-1-9|sort-numeric-desc=arrow-down-9-1'.
        '|sort-numeric-down-alt=arrow-down-9-1|sort-alpha-asc=arrow-down-a-z|sort-alpha-down=arrow-down-a-z'.
        '|long-arrow-down=arrow-down-long|sort-amount-desc=arrow-down-short-wide|sort-amount-down-alt=arrow-down-short-wide'.
        '|sort-amount-asc=arrow-down-wide-short|sort-amount-down=arrow-down-wide-short|sort-alpha-desc=arrow-down-z-a'.
        '|sort-alpha-down-alt=arrow-down-z-a|long-arrow-left=arrow-left-long|mouse-pointer=arrow-pointer'.
        '|exchange=arrow-right-arrow-left|sign-out=arrow-right-from-bracket|long-arrow-right=arrow-right-long'.
        '|sign-in=arrow-right-to-bracket|arrow-left-rotate=arrow-rotate-left|arrow-rotate-back=arrow-rotate-left'.
        '|arrow-rotate-backward=arrow-rotate-left|undo=arrow-rotate-left|arrow-right-rotate=arrow-rotate-right'.
        '|arrow-rotate-forward=arrow-rotate-right|redo=arrow-rotate-right|level-down=arrow-turn-down|level-up=arrow-turn-up'.
        '|sort-numeric-up=arrow-up-1-9|sort-numeric-up-alt=arrow-up-9-1|sort-alpha-up=arrow-up-a-z|long-arrow-up=arrow-up-long'.
        '|external-link=arrow-up-right-from-square|sort-amount-up-alt=arrow-up-short-wide|sort-amount-up=arrow-up-wide-short'.
        '|sort-alpha-up-alt=arrow-up-z-a|arrows-h=arrows-left-right|refresh=arrows-rotate|sync=arrows-rotate'.
        '|arrows-v=arrows-up-down|arrows=arrows-up-down-left-right|carriage-baby=baby-carriage|fast-backward=backward-fast'.
        '|step-backward=backward-step|shopping-bag=bag-shopping|haykal=bahai|cancel=ban|smoking-ban=ban-smoking|band-aid=bandage'.
        '|navicon=bars|tasks-alt=bars-progress|reorder=bars-staggered|stream=bars-staggered|baseball-ball=baseball'.
        '|shopping-basket=basket-shopping|basketball-ball=basketball|bathtub=bath|battery-0=battery-empty|battery=battery-full'.
        '|battery-5=battery-full|battery-3=battery-half|battery-2=battery-quarter|battery-4=battery-three-quarters'.
        '|procedures=bed-pulse|beer=beer-mug-empty|concierge-bell=bell-concierge|zap=bolt|atlas=book-atlas|bible=book-bible'.
        '|journal-whills=book-journal-whills|book-reader=book-open-reader|quran=book-quran|book-dead=book-skull|tanakh=book-tanakh'.
        '|border-style=border-top-left|archive=box-archive|boxes=boxes-stacked|boxes-alt=boxes-stacked|quidditch=broom-ball'.
        '|quidditch-broom-ball=broom-ball|bank=building-columns|institution=building-columns|museum=building-columns'.
        '|university=building-columns|hamburger=burger|bus-alt=bus-simple|briefcase-clock=business-time|tram=cable-car'.
        '|birthday-cake=cake-candles|cake=cake-candles|calendar-alt=calendar-days|calendar-times=calendar-xmark|camera-alt=camera'.
        '|automobile=car|battery-car=car-battery|car-crash=car-burst|car-alt=car-rear|dolly-flatbed=cart-flatbed'.
        '|luggage-cart=cart-flatbed-suitcase|shopping-cart=cart-shopping|blackboard=chalkboard|chalkboard-teacher=chalkboard-user'.
        '|glass-cheers=champagne-glasses|area-chart=chart-area|bar-chart=chart-bar|line-chart=chart-line|pie-chart=chart-pie'.
        '|vote-yea=check-to-slot|child-rifle=child-combatant|arrow-circle-down=circle-arrow-down'.
        '|arrow-circle-left=circle-arrow-left|arrow-circle-right=circle-arrow-right|arrow-circle-up=circle-arrow-up'.
        '|check-circle=circle-check|chevron-circle-down=circle-chevron-down|chevron-circle-left=circle-chevron-left'.
        '|chevron-circle-right=circle-chevron-right|chevron-circle-up=circle-chevron-up|donate=circle-dollar-to-slot'.
        '|dot-circle=circle-dot|arrow-alt-circle-down=circle-down|exclamation-circle=circle-exclamation|hospital-symbol=circle-h'.
        '|adjust=circle-half-stroke|info-circle=circle-info|arrow-alt-circle-left=circle-left|minus-circle=circle-minus'.
        '|pause-circle=circle-pause|play-circle=circle-play|plus-circle=circle-plus|question-circle=circle-question'.
        '|radiation-alt=circle-radiation|arrow-alt-circle-right=circle-right|stop-circle=circle-stop|arrow-alt-circle-up=circle-up'.
        '|user-circle=circle-user|times-circle=circle-xmark|xmark-circle=circle-xmark|clock-four=clock|history=clock-rotate-left'.
        '|cloud-download=cloud-arrow-down|cloud-download-alt=cloud-arrow-down|cloud-upload=cloud-arrow-up'.
        '|cloud-upload-alt=cloud-arrow-up|thunderstorm=cloud-bolt|commenting=comment-dots|sms=comment-sms'.
        '|drafting-compass=compass-drafting|mouse=computer-mouse|credit-card-alt=credit-card|crop-alt=crop-simple'.
        '|backspace=delete-left|desktop-alt=desktop|project-diagram=diagram-project|directions=diamond-turn-right'.
        '|dollar=dollar-sign|usd=dollar-sign|dolly-box=dolly|compress-alt=down-left-and-up-right-to-center'.
        '|long-arrow-alt-down=down-long|tint=droplet|tint-slash=droplet-slash|deaf=ear-deaf|deafness=ear-deaf'.
        '|hard-of-hearing=ear-deaf|assistive-listening-systems=ear-listen|globe-africa=earth-africa|earth=earth-americas'.
        '|earth-america=earth-americas|globe-americas=earth-americas|globe-asia=earth-asia|globe-europe=earth-europe'.
        '|globe-oceania=earth-oceania|ellipsis-h=ellipsis|ellipsis-v=ellipsis-vertical|mail-bulk=envelopes-bulk|eur=euro-sign'.
        '|euro=euro-sign|eye-dropper-empty=eye-dropper|eyedropper=eye-dropper|low-vision=eye-low-vision|angry=face-angry'.
        '|dizzy=face-dizzy|flushed=face-flushed|frown=face-frown|frown-open=face-frown-open|grimace=face-grimace|grin=face-grin'.
        '|grin-beam=face-grin-beam|grin-beam-sweat=face-grin-beam-sweat|grin-hearts=face-grin-hearts|grin-squint=face-grin-squint'.
        '|grin-squint-tears=face-grin-squint-tears|grin-stars=face-grin-stars|grin-tears=face-grin-tears'.
        '|grin-tongue=face-grin-tongue|grin-tongue-squint=face-grin-tongue-squint|grin-tongue-wink=face-grin-tongue-wink'.
        '|grin-alt=face-grin-wide|grin-wink=face-grin-wink|kiss=face-kiss|kiss-beam=face-kiss-beam'.
        '|kiss-wink-heart=face-kiss-wink-heart|laugh=face-laugh|laugh-beam=face-laugh-beam|laugh-squint=face-laugh-squint'.
        '|laugh-wink=face-laugh-wink|meh=face-meh|meh-blank=face-meh-blank|meh-rolling-eyes=face-rolling-eyes|sad-cry=face-sad-cry'.
        '|sad-tear=face-sad-tear|smile=face-smile|smile-beam=face-smile-beam|smile-wink=face-smile-wink|surprise=face-surprise'.
        '|tired=face-tired|feather-alt=feather-pointed|file-download=file-arrow-down|file-upload=file-arrow-up'.
        '|arrow-right-from-file=file-export|arrow-right-to-file=file-import|file-alt=file-lines|file-text=file-lines'.
        '|file-edit=file-pen|file-medical-alt=file-waveform|file-archive=file-zipper|funnel-dollar=filter-circle-dollar'.
        '|fire-alt=fire-flame-curved|burn=fire-flame-simple|save=floppy-disk|folder-blank=folder|font-awesome-flag=font-awesome'.
        '|font-awesome-logo-full=font-awesome|football-ball=football|fast-forward=forward-fast|step-forward=forward-step'.
        '|futbol-ball=futbol|soccer-ball=futbol|dashboard=gauge|gauge-med=gauge|tachometer-alt-average=gauge'.
        '|tachometer-alt=gauge-high|tachometer-alt-fast=gauge-high|gauge-simple-med=gauge-simple|tachometer-average=gauge-simple'.
        '|tachometer=gauge-simple-high|tachometer-fast=gauge-simple-high|legal=gavel|cog=gear|cogs=gears|golf-ball=golf-ball-tee'.
        '|mortar-board=graduation-cap|grip-horizontal=grip|hand-paper=hand|hand-rock=hand-back-fist|allergies=hand-dots'.
        '|fist-raised=hand-fist|hand-holding-usd=hand-holding-dollar|hand-holding-water=hand-holding-droplet|sign-language=hands'.
        '|signing=hands|american-sign-language-interpreting=hands-asl-interpreting|asl-interpreting=hands-asl-interpreting'.
        '|hands-american-sign-language-interpreting=hands-asl-interpreting|hands-wash=hands-bubbles|praying-hands=hands-praying'.
        '|hands-helping=handshake-angle|handshake-alt=handshake-simple|handshake-alt-slash=handshake-simple-slash|hdd=hard-drive'.
        '|header=heading|headphones-alt=headphones-simple|heart-broken=heart-crack|heartbeat=heart-pulse|hard-hat=helmet-safety'.
        '|hat-hard=helmet-safety|hospital-alt=hospital|hospital-wide=hospital|hot-tub=hot-tub-person|hourglass-empty=hourglass'.
        '|hourglass-3=hourglass-end|hourglass-2=hourglass-half|hourglass-1=hourglass-start|home=house|home-alt=house'.
        '|home-lg-alt=house|home-lg=house-chimney|house-damage=house-chimney-crack|clinic-medical=house-chimney-medical'.
        '|laptop-house=house-laptop|home-user=house-user|hryvnia=hryvnia-sign|heart-music-camera-bolt=icons'.
        '|drivers-license=id-card|id-card-alt=id-card-clip|portrait=image-portrait|indian-rupee=indian-rupee-sign'.
        '|inr=indian-rupee-sign|fighter-jet=jet-fighter|first-aid=kit-medical|landmark-alt=landmark-dome'.
        '|long-arrow-alt-left=left-long|arrows-alt-h=left-right|chain=link|chain-broken=link-slash|chain-slash=link-slash'.
        '|unlink=link-slash|list-squares=list|tasks=list-check|list-1-2=list-ol|list-numeric=list-ol|list-dots=list-ul'.
        '|location=location-crosshairs|map-marker-alt=location-dot|map-marker=location-pin|search=magnifying-glass'.
        '|search-dollar=magnifying-glass-dollar|search-location=magnifying-glass-location|search-minus=magnifying-glass-minus'.
        '|search-plus=magnifying-glass-plus|map-marked=map-location|map-marked-alt=map-location-dot'.
        '|mars-stroke-h=mars-stroke-right|mars-stroke-v=mars-stroke-up|glass-martini-alt=martini-glass'.
        '|cocktail=martini-glass-citrus|glass-martini=martini-glass-empty|theater-masks=masks-theater|expand-arrows-alt=maximize'.
        '|comment-alt=message|microphone-alt=microphone-lines|microphone-alt-slash=microphone-lines-slash'.
        '|compress-arrows-alt=minimize|subtract=minus|mobile-android=mobile|mobile-phone=mobile|mobile-android-alt=mobile-screen'.
        '|mobile-alt=mobile-screen-button|money-bill-alt=money-bill-1|money-bill-wave-alt=money-bill-1-wave'.
        '|money-check-alt=money-check-dollar|coffee=mug-saucer|sticky-note=note-sticky|dedent=outdent|paint-brush=paintbrush'.
        '|file-clipboard=paste|pen-alt=pen-clip|pencil-ruler=pen-ruler|edit=pen-to-square|pencil-alt=pencil'.
        '|people-arrows-left-right=people-arrows|people-carry=people-carry-box|percentage=percent|male=person|biking=person-biking'.
        '|digging=person-digging|diagnoses=person-dots-from-line|female=person-dress|hiking=person-hiking|pray=person-praying'.
        '|running=person-running|skating=person-skating|skiing=person-skiing|skiing-nordic=person-skiing-nordic'.
        '|snowboarding=person-snowboarding|swimmer=person-swimming|walking=person-walking|blind=person-walking-with-cane'.
        '|phone-alt=phone-flip|volume-control-phone=phone-volume|photo-video=photo-film|add=plus|poo-bolt=poo-storm'.
        '|prescription-bottle-alt=prescription-bottle-medical|quote-left-alt=quote-left|quote-right-alt=quote-right'.
        '|ad=rectangle-ad|list-alt=rectangle-list|rectangle-times=rectangle-xmark|times-rectangle=rectangle-xmark'.
        '|window-close=rectangle-xmark|mail-reply=reply|mail-reply-all=reply-all|sign-out-alt=right-from-bracket'.
        '|exchange-alt=right-left|long-arrow-alt-right=right-long|sign-in-alt=right-to-bracket|sync-alt=rotate'.
        '|rotate-back=rotate-left|rotate-backward=rotate-left|undo-alt=rotate-left|redo-alt=rotate-right'.
        '|rotate-forward=rotate-right|feed=rss|rouble=ruble-sign|rub=ruble-sign|ruble=ruble-sign|rupee=rupee-sign'.
        '|balance-scale=scale-balanced|balance-scale-left=scale-unbalanced|balance-scale-right=scale-unbalanced-flip|cut=scissors'.
        '|tools=screwdriver-wrench|torah=scroll-torah|sprout=seedling|triangle-circle-square=shapes|mail-forward=share'.
        '|share-square=share-from-square|share-alt=share-nodes|ils=shekel-sign|shekel=shekel-sign|sheqel=shekel-sign'.
        '|sheqel-sign=shekel-sign|shield-blank=shield|shield-alt=shield-halved|t-shirt=shirt|tshirt=shirt|store-alt=shop'.
        '|store-alt-slash=shop-slash|random=shuffle|space-shuttle=shuttle-space|sign=sign-hanging|signal-5=signal'.
        '|signal-perfect=signal|map-signs=signs-post|sliders-h=sliders|unsorted=sort|sort-desc=sort-down|sort-asc=sort-up'.
        '|pastafarianism=spaghetti-monster-flying|utensil-spoon=spoon|air-freshener=spray-can-sparkles'.
        '|external-link-square=square-arrow-up-right|caret-square-down=square-caret-down|caret-square-left=square-caret-left'.
        '|caret-square-right=square-caret-right|caret-square-up=square-caret-up|check-square=square-check'.
        '|envelope-square=square-envelope|h-square=square-h|minus-square=square-minus|parking=square-parking|pen-square=square-pen'.
        '|pencil-square=square-pen|phone-square=square-phone|phone-square-alt=square-phone-flip|plus-square=square-plus'.
        '|poll-h=square-poll-horizontal|poll=square-poll-vertical|square-root-alt=square-root-variable|rss-square=square-rss'.
        '|share-alt-square=square-share-nodes|external-link-square-alt=square-up-right|times-square=square-xmark'.
        '|xmark-square=square-xmark|rod-asclepius=staff-snake|rod-snake=staff-snake|staff-aesculapius=staff-snake'.
        '|star-half-alt=star-half-stroke|gbp=sterling-sign|pound-sign=sterling-sign|medkit=suitcase-medical|th=table-cells'.
        '|th-large=table-cells-large|columns=table-columns|th-list=table-list|ping-pong-paddle-ball=table-tennis-paddle-ball'.
        '|table-tennis=table-tennis-paddle-ball|tablet-android=tablet|tablet-alt=tablet-screen-button'.
        '|digital-tachograph=tachograph-digital|cab=taxi|temperature-down=temperature-arrow-down'.
        '|temperature-up=temperature-arrow-up|temperature-0=temperature-empty|thermometer-0=temperature-empty'.
        '|thermometer-empty=temperature-empty|temperature-4=temperature-full|thermometer-4=temperature-full'.
        '|thermometer-full=temperature-full|temperature-2=temperature-half|thermometer-2=temperature-half'.
        '|thermometer-half=temperature-half|temperature-1=temperature-quarter|thermometer-1=temperature-quarter'.
        '|thermometer-quarter=temperature-quarter|temperature-3=temperature-three-quarters'.
        '|thermometer-3=temperature-three-quarters|thermometer-three-quarters=temperature-three-quarters|tenge=tenge-sign'.
        '|remove-format=text-slash|thumb-tack=thumbtack|thumb-tack-slash=thumbtack-slash|ticket-alt=ticket-simple'.
        '|broadcast-tower=tower-broadcast|subway=train-subway|transgender-alt=transgender|trash-restore=trash-arrow-up'.
        '|trash-alt=trash-can|trash-restore-alt=trash-can-arrow-up|exclamation-triangle=triangle-exclamation'.
        '|warning=triangle-exclamation|shipping-fast=truck-fast|ambulance=truck-medical|truck-loading=truck-ramp-box|teletype=tty'.
        '|try=turkish-lira-sign|turkish-lira=turkish-lira-sign|level-down-alt=turn-down|level-up-alt=turn-up|television=tv'.
        '|tv-alt=tv|unlock-alt=unlock-keyhole|arrows-alt-v=up-down|arrows-alt=up-down-left-right|long-arrow-alt-up=up-long'.
        '|expand-alt=up-right-and-down-left-from-center|external-link-alt=up-right-from-square|user-md=user-doctor'.
        '|user-cog=user-gear|user-friends=user-group|user-alt=user-large|user-alt-slash=user-large-slash|user-edit=user-pen'.
        '|user-times=user-xmark|users-cog=users-gear|cutlery=utensils|shuttle-van=van-shuttle|video-camera=video'.
        '|volleyball-ball=volleyball|volume-up=volume-high|volume-down=volume-low|volume-mute=volume-xmark'.
        '|volume-times=volume-xmark|magic=wand-magic|magic-wand-sparkles=wand-magic-sparkles|ladder-water=water-ladder'.
        '|swimming-pool=water-ladder|weight=weight-scale|wheat-alt=wheat-awn|wheelchair-alt=wheelchair-move'.
        '|glass-whiskey=whiskey-glass|wifi-3=wifi|wifi-strong=wifi|wine-glass-alt=wine-glass-empty|krw=won-sign|won=won-sign'.
        '|close=xmark|multiply=xmark|remove=xmark|times=xmark|cny=yen-sign|jpy=yen-sign|rmb=yen-sign|yen=yen-sign';

    /**
     * Return the canonical installed solid glyph for the requested value.
     *
     * Stored values are class-name suffixes (for example, "video"), never
     * complete CSS classes. Unknown, malformed, or prefixed values resolve to
     * the safe folder glyph so a missing icon cannot render as an empty box or
     * become an arbitrary class injection.
     */
    public static function normalize(mixed $icon, string $fallback = self::FALLBACK): string
    {
        $fallback = self::isSupported($fallback) ? strtolower(trim($fallback)) : self::FALLBACK;

        if (! is_string($icon)) {
            return $fallback;
        }

        $icon = strtolower(trim($icon));

        if (self::isSupported($icon)) {
            return $icon;
        }

        return self::aliases()[$icon] ?? $fallback;
    }

    /**
     * True only for canonical names in the installed solid glyph set.
     */
    public static function isSupported(mixed $icon): bool
    {
        if (! is_string($icon)) {
            return false;
        }

        $icon = strtolower(trim($icon));

        return $icon !== ''
            && preg_match('/^[a-z0-9-]+$/', $icon) === 1
            && isset(self::icons()[$icon]);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::icons());
    }

    /**
     * @return array<string, true>
     */
    private static function icons(): array
    {
        static $icons;

        return $icons ??= array_fill_keys(explode('|', self::SOLID_ICON_NAMES), true);
    }

    /**
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        static $aliases;

        if ($aliases !== null) {
            return $aliases;
        }

        $aliases = [];

        foreach (explode('|', self::SOLID_ICON_ALIASES) as $pair) {
            [$alias, $canonical] = explode('=', $pair, 2);
            $aliases[$alias] = $canonical;
        }

        return $aliases;
    }
}
