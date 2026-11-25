<?php

declare(strict_types=1);

return [
    // The read-only connection to WordPress's database; its `prefix` is WordPress's `$table_prefix`.
    'connection' => env('WORDPRESS_POSTS_CONNECTION', 'blog'),

    // Elements kept from `post_content`, without attributes. `br` is always kept; `script`, `style`,
    // `template`, SVG and MathML are always dropped. List formatting only: `div`, table parts, form
    // controls and raw-text elements can break the page. Cached posts change when they expire.
    'tags' => [
        'p', 'h2', 'h3', 'strong', 'em', 's', 'del', 'sup', 'sub', 'blockquote', 'ul', 'ol', 'li',
    ],
];
