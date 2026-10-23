<?php

return [
    'front' => [
        'top' => [
            [
                [
                    'title' => 'Home',
                    'icon' => 'house',
                    'route' => 'pages.home',
                ],
                [
                    'title' => 'Trend',
                    'icon' => 'fire',
                    'route' => 'pages.trend',
                ],
                [
                    'title' => 'Subscriptions',
                    'icon' => 'user-check',
                    'route' => 'subscription.index',
                ],
            ],
            [
                [
                    'title' => 'History',
                    'icon' => 'history',
                    'route' => 'pages.history',
                ],
                [
                    'title' => 'Your videos',
                    'icon' => 'video',
                    'route' => 'user.videos.index',
                    'new_tab' => true,
                ],
                [
                    'title' => 'Watch later',
                    'icon' => 'clock',
                    'route' => 'custom-playlist.show',
                    'params' => [
                        'type' => \App\Enums\CustomPlaylistType::WATCH_LATER
                    ]
                ],
                [
                    'title' => 'Liked videos',
                    'icon' => 'thumbs-up',
                    'route' => 'custom-playlist.show',
                    'params' => [
                        'type' => \App\Enums\CustomPlaylistType::LIKED_VIDEOS
                    ]
                ]
            ],
        ],
    ],
    'user' => [
        [
            'title' => 'Dashboard',
            'icon' => 'chart-line',
            'route' => 'user.index',
        ],
        [
            'title' => 'Videos',
            'icon' => 'video',
            'route' => 'user.videos.index',
        ],
        [
            'title' => 'Playlists',
            'icon' => 'rectangle-list',
            'route' => 'user.playlists.index',
        ],
        [
            'title' => 'Subscribers',
            'icon' => 'users',
            'route' => 'user.subscribers',
        ],
        [
            'title' => 'Comments',
            'icon' => 'comments',
            'route' => 'user.comments.index',
        ],
        [
            'title' => 'Activity',
            'icon' => 'bars-staggered',
            'route' => 'user.activity.index',
        ],
        [
            'title' => 'Reports',
            'icon' => 'flag',
            'route' => 'user.reports.index',
        ],
        [
            'title' => 'Invoices',
            'icon' => 'receipt',
            'route' => 'user.invoices.index',
        ]
    ],
    'admin' => [
        // ── Dashboard (standalone, always visible) ──────────────────────────
        [
            'title' => 'Dashboard',
            'icon' => 'chart-line',
            'route' => 'admin.index',
        ],
        [
            'title' => 'AVDBAPI Import',
            'icon' => 'download',
            'route' => 'admin.avdb-import.index',
            'label_class' => 'fw-bold',
        ],
        [
            'title' => 'XVIDAPI Import',
            'icon' => 'cloud-arrow-down',
            'route' => 'admin.xvid-import.index',
            'label_class' => 'fw-bold',
        ],

        // ── Content Management ──────────────────────────────────────────────
        [
            'group' => 'Content',
            'group_icon' => 'film',
            'children' => [
                ['title' => 'Videos',           'icon' => 'video',          'route' => 'admin.videos.index'],
                ['title' => 'Categories',       'icon' => 'folder',         'route' => 'admin.categories.index'],
                ['title' => 'Tags',             'icon' => 'tags',           'route' => 'admin.tags.index'],
                ['title' => 'Playlists',        'icon' => 'rectangle-list', 'route' => 'admin.playlists.index'],
                ['title' => 'Thumbnails',       'icon' => 'image',          'route' => 'admin.thumbnails.index'],
                ['title' => 'Featured Content', 'icon' => 'star',           'route' => 'admin.featured.index'],
                ['title' => 'Media Library',    'icon' => 'photo-film',     'route' => 'admin.media.index'],
                ['title' => 'Custom Pages',     'icon' => 'file-pen',       'route' => 'admin.pages.index'],
            ],
        ],

        // ── Users & Access ──────────────────────────────────────────────────
        [
            'group' => 'Users & Access',
            'group_icon' => 'users',
            'children' => [
                ['title' => 'Users',           'icon' => 'users',          'route' => 'admin.users.index'],
                ['title' => 'Verification',    'icon' => 'user-check',     'route' => 'admin.verification.index'],
                ['title' => 'Password Resets', 'icon' => 'key',            'route' => 'admin.password-resets.index'],
                ['title' => 'Sessions',        'icon' => 'desktop',        'route' => 'admin.sessions.index'],
                ['title' => 'Devices',         'icon' => 'mobile-screen',  'route' => 'admin.devices.index'],
                ['title' => 'IP Bans',         'icon' => 'shield-halved',  'route' => 'admin.ip-bans.index'],
            ],
        ],

        // ── Community ───────────────────────────────────────────────────────
        [
            'group' => 'Community',
            'group_icon' => 'comments',
            'children' => [
                ['title' => 'Comments',     'icon' => 'comment',        'route' => 'admin.comments.index'],
                ['title' => 'Reports',      'icon' => 'flag',           'route' => 'admin.reports.index'],
                ['title' => 'Spam',         'icon' => 'envelope',       'route' => 'admin.spams.index'],
                ['title' => 'Activities',   'icon' => 'bars-staggered', 'route' => 'admin.activities.index'],
                ['title' => 'Interactions', 'icon' => 'thumbs-up',      'route' => 'admin.interactions.index'],
            ],
        ],

        // ── Monetization ────────────────────────────────────────────────────
        [
            'group' => 'Monetization',
            'group_icon' => 'credit-card',
            'children' => [
                ['title' => 'Plans',            'icon' => 'toolbox',         'route' => 'admin.plans.index'],
                ['title' => 'Subscriptions',    'icon' => 'crown',           'route' => 'admin.subscriptions.index'],
                ['title' => 'Premium Records',  'icon' => 'crown',           'route' => 'admin.premiums.index'],
                ['title' => 'Invoices',         'icon' => 'receipt',         'route' => 'admin.invoices.index'],
                ['title' => 'Transactions',     'icon' => 'money-bill-wave', 'route' => 'admin.transactions.index'],
                ['title' => 'Exports',          'icon' => 'file-export',     'route' => 'admin.exports.index'],
            ],
        ],

        // ── Communication ───────────────────────────────────────────────────
        [
            'group' => 'Communication',
            'group_icon' => 'paper-plane',
            'children' => [
                ['title' => 'Notifications',    'icon' => 'bell',              'route' => 'admin.notifications.index'],
                ['title' => 'Announcements',    'icon' => 'bullhorn',          'route' => 'admin.announcements.index'],
                ['title' => 'Mass Broadcast',   'icon' => 'bullhorn',          'route' => 'admin.broadcast.index'],
                ['title' => 'Contact Messages', 'icon' => 'envelope-open-text','route' => 'admin.contacts.index'],
                ['title' => 'Email Test',       'icon' => 'paper-plane',       'route' => 'admin.email-test.index'],
            ],
        ],

        // ── Analytics ───────────────────────────────────────────────────────
        [
            'group' => 'Analytics',
            'group_icon' => 'chart-bar',
            'children' => [
                ['title' => 'Views Analytics', 'icon' => 'eye',              'route' => 'admin.views.index'],
                ['title' => 'Searches',        'icon' => 'magnifying-glass', 'route' => 'admin.searches.index'],
            ],
        ],

        // ── Settings ────────────────────────────────────────────────────────
        [
            'group' => 'Settings',
            'group_icon' => 'gear',
            'children' => [
                ['title' => 'Settings',       'icon' => 'gear',     'route' => 'admin.settings.index'],
                ['title' => 'Languages',      'icon' => 'language', 'route' => 'admin.languages.index'],
                ['title' => 'URL Redirects',  'icon' => 'share',    'route' => 'admin.redirects.index'],
                ['title' => 'API Tokens',     'icon' => 'key',      'route' => 'admin.api-tokens.index'],
            ],
        ],

        // ── System ──────────────────────────────────────────────────────────
        [
            'group' => 'System',
            'group_icon' => 'server',
            'children' => [
                ['title' => 'Preflight',      'icon' => 'circle-check',         'route' => 'admin.preflight.index', 'requires_ops' => true],
                ['title' => 'System Info',    'icon' => 'server',               'route' => 'admin.system.index', 'requires_ops' => true],
                ['title' => 'Database',       'icon' => 'database',             'route' => 'admin.database.index', 'requires_ops' => true],
                ['title' => 'Backups',        'icon' => 'box-archive',          'route' => 'admin.backups.index', 'requires_ops' => true],
                ['title' => 'Storage',        'icon' => 'hard-drive',           'route' => 'admin.storage.index', 'requires_ops' => true],
                ['title' => 'Cron / Jobs',    'icon' => 'clock',                'route' => 'admin.cron.index', 'requires_ops' => true],
                ['title' => 'Queue Monitor',  'icon' => 'layer-group',          'route' => 'admin.queue.index', 'requires_ops' => true],
                ['title' => 'Failed Jobs',    'icon' => 'triangle-exclamation', 'route' => 'admin.failed-jobs.index', 'requires_ops' => true],
                ['title' => 'Audit Log',      'icon' => 'clipboard-list',       'route' => 'admin.audit.index'],
                ['title' => 'Log Viewer',     'icon' => 'file-lines',           'route' => 'admin.logs.index', 'requires_ops' => true],
                ['title' => '.env Editor',    'icon' => 'file-code',            'route' => 'admin.env-editor.index', 'requires_ops' => true],
                ['title' => 'Artisan CLI',    'icon' => 'terminal',             'route' => 'admin.artisan.index', 'requires_ops' => true],
                ['title' => 'PHP Info',       'icon' => 'info-circle',          'route' => 'admin.phpinfo.index', 'requires_ops' => true],
                ['title' => 'Horizon',        'icon' => 'bars-progress',        'route' => 'admin.horizon', 'new_tab' => true, 'requires_ops' => true],
            ],
        ],
    ]
];
