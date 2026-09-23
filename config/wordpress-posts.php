<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The Laravel connection that reaches WordPress's database, read-only. Put
    | WordPress's `$table_prefix` on the connection's `prefix` (usually `wp_`);
    | the package reads the `posts` table through it.
    |
    */
    'connection' => env('WORDPRESS_POSTS_CONNECTION', 'blog'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts, in seconds
    |--------------------------------------------------------------------------
    |
    | `timeout` is the MySQL read timeout (mysqlnd.net_read_timeout), in whole
    | seconds, while the package reads; the setting is restored afterwards.
    | mysqlnd reads it only when a connection opens, so give the package a
    | connection of its own: one opened earlier keeps its own read timeout (a
    | day by default), and one the package opens keeps `timeout` until it
    | closes. Give that connection a short PDO::ATTR_TIMEOUT too; it bounds
    | only the TCP connect. Laravel retries a lost connection, so one failing
    | attempt costs up to about four times the larger of the two.
    |
    */
    'timeout' => 3,

    /*
    |--------------------------------------------------------------------------
    | Cache lifetimes, in seconds
    |--------------------------------------------------------------------------
    |
    | `cache` covers the list of published ids and each drawn post, so a page
    | view costs no query once both are warm. `outage` is how long a failing
    | database is left alone before the next attempt: without it every page
    | view waits on the dead host. The same marker is held, as a claim, while
    | one request refills the cache, so the requests beside it skip an
    | uncached post instead of queueing on the database. Cached posts are
    | shown either way, while the id list is cached. An `outage` of 0 turns
    | off both the wait and the claim.
    |
    */
    'cache' => 60 * 60 * 24 * 30,

    'outage' => 300,

    /*
    |--------------------------------------------------------------------------
    | Formatting tags
    |--------------------------------------------------------------------------
    |
    | The HTML elements kept from `post_content`, always without attributes.
    | Any other element is unwrapped to its text; `script`, `style`, `template`
    | and SVG or MathML content are dropped whole, whatever this list says.
    | `br` is always kept: WordPress stores paragraphs as bare newlines. List
    | formatting elements only: `div`, table parts, form controls and raw-text
    | elements such as `plaintext` or `textarea` can break the page around the
    | post. A post is cached already reduced, so a change here reaches it when
    | its cache expires.
    |
    */
    'tags' => [
        'p', 'h2', 'h3', 'strong', 'em', 's', 'del', 'sup', 'sub', 'blockquote', 'ul', 'ol', 'li',
    ],
];
