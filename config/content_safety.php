<?php

/*
 * AVDBCMS 1.2.3: the words that keep AVDBAPI and XVIDAPI items out of the site. The words and why each one is in live in
 * App\Support\ContentSafety (one place for the rules); this file only hands the default list to the settings form:
 * Content > Import > Blocked words (the setting import_blocked_words) shows it while the owner never saved a list of their
 * own (config/admin_settings.php: 'default' => ['config' => 'content_safety.default_words']).
 */

return [
    'default_words' => App\Support\ContentSafety::defaultText(),
];
