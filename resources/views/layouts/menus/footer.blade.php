@php
    $footerDefaults = \App\Support\FooterSettings::defaults();
    $footerBool = fn (string $key): bool => (bool) config('site.' . $key, $footerDefaults[$key] ?? false);
    $footerTextSetting = fn (string $key): string => trim((string) config('site.' . $key, $footerDefaults[$key] ?? ''));
    $socialLinks = [
        'facebook'  => ['url' => config('site.social_facebook'),  'icon' => 'fa-facebook-f',  'label' => 'Facebook'],
        'twitter'   => ['url' => config('site.social_twitter'),   'icon' => 'fa-x-twitter',   'label' => 'X / Twitter'],
        'instagram' => ['url' => config('site.social_instagram'), 'icon' => 'fa-instagram',   'label' => 'Instagram'],
        'youtube'   => ['url' => config('site.social_youtube'),   'icon' => 'fa-youtube',     'label' => 'YouTube'],
        'tiktok'    => ['url' => config('site.social_tiktok'),    'icon' => 'fa-tiktok',      'label' => 'TikTok'],
        'discord'   => ['url' => config('site.social_discord'),   'icon' => 'fa-discord',     'label' => 'Discord'],
        'telegram'  => ['url' => config('site.social_telegram'),  'icon' => 'fa-telegram',    'label' => 'Telegram'],
    ];
    $footerSocialEnabled = $footerBool('footer_social_enabled');
    $hasSocial = $footerSocialEnabled && collect($socialLinks)->filter(fn($s) => !empty($s['url']))->isNotEmpty();
    $categoryLimit = max(0, min(24, (int) $footerTextSetting('footer_categories_limit')));
    $topCategories = isset($categories) ? $categories->where('in_menu', true)->take($categoryLimit) : collect();
    $footerText = trim((string) config('site.footer_text', ''));
    $allowLanguageSwitch = config('site.allow_language_switch', true);
    $footerBrandEnabled = $footerBool('footer_brand_enabled');
    $footerBrandTitle = $footerTextSetting('footer_brand_title') ?: config('app.name', 'AVDBCMS');
    $footerTagline = $footerTextSetting('footer_tagline');
    $exploreLinks = \App\Support\FooterSettings::parseLinks($footerTextSetting('footer_explore_links'));
    $customCategoryLinks = \App\Support\FooterSettings::parseLinks($footerTextSetting('footer_categories_links'));
    $categoryLinks = $customCategoryLinks ?: $topCategories->map(fn ($category) => [
        'label' => $category->title,
        'url' => locale_url(route('pages.category', $category->slug)),
        'external' => false,
    ])->values()->all();
    $supportLinks = \App\Support\FooterSettings::parseLinks($footerTextSetting('footer_support_links'));
    $bottomLinks = \App\Support\FooterSettings::parseLinks($footerTextSetting('footer_bottom_links'), 8);
    $footerSettingsEnabled = $footerBool('footer_settings_enabled')
        && (($allowLanguageSwitch && $footerBool('footer_language_switcher_enabled')) || $footerBool('footer_theme_switcher_enabled'));
    $renderFooterUrl = fn(string $url): string => (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || $url === '#' || str_starts_with($url, '#')) ? $url : (function_exists('locale_url') ? locale_url($url) : url($url));
@endphp

<footer class="avd-footer" id="site-footer">
    <div class="avd-footer-accent"></div>
    <div class="avd-footer-inner">
        @if($footerBrandEnabled || $hasSocial)
            <div class="avd-footer-top">
                @if($footerBrandEnabled)
                    <div>
                        <a href="{{ locale_url(route('pages.home')) }}" class="avd-footer-logo">
                            {{ __($footerBrandTitle) }}
                        </a>
                        @if($footerTagline !== '')
                            <p class="avd-footer-tagline">
                                {{ __($footerTagline) }}
                            </p>
                        @endif
                    </div>
                @endif
                @if($hasSocial)
                <div class="avd-footer-social">
                    @foreach($socialLinks as $social)
                        @if(!empty($social['url']))
                            <a href="{{ $social['url'] }}" target="_blank" rel="noopener" title="{{ $social['label'] }}" aria-label="{{ $social['label'] }}" class="avd-social-btn">
                                <i class="fa-brands {{ $social['icon'] }}" aria-hidden="true"></i>
                            </a>
                        @endif
                    @endforeach
                </div>
                @endif
            </div>
        @endif

        <div class="avd-footer-links-grid">
            @if($footerBool('footer_explore_enabled') && count($exploreLinks) > 0)
                <div class="avd-footer-col">
                    <h6>{{ __($footerTextSetting('footer_explore_title') ?: 'Explore') }}</h6>
                    <ul>
                        @foreach($exploreLinks as $link)
                            <li>
                                <a href="{{ $renderFooterUrl($link['url']) }}" @if($link['external']) target="_blank" rel="noopener" @endif>{{ __($link['label']) }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if($footerBool('footer_categories_enabled') && count($categoryLinks) > 0)
                <div class="avd-footer-col">
                    <h6>{{ __($footerTextSetting('footer_categories_title') ?: 'Categories') }}</h6>
                    <ul>
                        @foreach($categoryLinks as $link)
                            <li>
                                <a href="{{ $renderFooterUrl($link['url']) }}" @if($link['external']) target="_blank" rel="noopener" @endif>{{ __($link['label']) }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if($footerBool('footer_support_enabled') && count($supportLinks) > 0)
                <div class="avd-footer-col">
                    <h6>{{ __($footerTextSetting('footer_support_title') ?: 'Support') }}</h6>
                    <ul>
                        @foreach($supportLinks as $link)
                            <li>
                                <a href="{{ $renderFooterUrl($link['url']) }}" @if($link['external']) target="_blank" rel="noopener" @endif>{{ __($link['label']) }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if($footerSettingsEnabled)
                <div class="avd-footer-col">
                    <h6>{{ __($footerTextSetting('footer_settings_title') ?: 'Settings') }}</h6>
                    @if($allowLanguageSwitch && $footerBool('footer_language_switcher_enabled'))
                    <select onchange="if (this.value) window.location.href = this.value" class="avd-footer-select" aria-label="{{ __('Language') }}">
                        @foreach(config('languages') as $lang => $info)
                            <option @selected(App::currentLocale() === $lang) value="{{ locale_switch_url($lang) }}">
                                {{ $info['emoji'] }} {{ $info['label'] }}
                            </option>
                        @endforeach
                    </select>
                    @endif
                    @if($footerBool('footer_theme_switcher_enabled'))
                        <div class="mt-2">
                            <theme-switch id="theme-switcher-footer"/>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="avd-footer-bottom">
            <span>{!! $footerText !== '' ? e(__($footerText)) : '&copy; ' . now()->format('Y') . ' ' . e(config('app.name')) . '. ' . e(__('All Rights Reserved')) !!}</span>
            @if($footerBool('footer_bottom_links_enabled') && count($bottomLinks) > 0)
                <div class="avd-footer-bottom-links">
                    @foreach($bottomLinks as $link)
                        <a href="{{ $renderFooterUrl($link['url']) }}" @if($link['external']) target="_blank" rel="noopener" @endif>{{ __($link['label']) }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</footer>
