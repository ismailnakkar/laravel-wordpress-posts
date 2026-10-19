<?php

declare(strict_types=1);

return [
    // The read-only connection to WordPress's database; its `prefix` is WordPress's `$table_prefix`.
    'connection' => env('WORDPRESS_POSTS_CONNECTION', 'blog'),

    // mysqlnd.net_read_timeout while the package reads, in whole seconds. mysqlnd applies it only when
    // a connection opens, so give the package a connection of its own.
    'timeout' => 3,

    // Seconds the published-id list and each drawn post stay cached.
    'cache' => 60 * 60 * 24 * 30,

    // Seconds a failing database is left alone. The same marker lets one request at a time refill the
    // cache; 0 turns off both.
    'outage' => 300,

    // Elements kept from `post_content`, without attributes. `br` is always kept; `script`, `style`,
    // `template`, SVG and MathML are always dropped. List formatting only: `div`, table parts, form
    // controls and raw-text elements can break the page. Cached posts change when they expire.
    'tags' => [
        'p', 'h2', 'h3', 'strong', 'em', 's', 'del', 'sup', 'sub', 'blockquote', 'ul', 'ol', 'li',
    ],
];
