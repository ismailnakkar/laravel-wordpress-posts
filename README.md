# Laravel WordPress Posts

Draws a random published post from a WordPress database for a Laravel page, typically as filler
beside the real content. It reads the database directly and read-only, caches what it reads, reduces
the post to safe formatting HTML, and returns `null` instead of failing when the blog is down. A
Blade component renders the post, or nothing.

## Install

Needs PHP 8.4 with `ext-dom` and Laravel 12.1 or 13. The read timeout works only through mysqlnd;
without it reads are unbounded.

```bash
composer require ismailnakkar/laravel-wordpress-posts
php artisan vendor:publish --tag=wordpress-posts-config   # only to change a default
```

Give the package a connection of its own in `config/database.php`: mysqlnd applies the read timeout
only when a connection opens, so a shared one keeps the wrong timeout.

```php
'blog' => [
    'driver'   => 'mysql',
    'host'     => env('BLOG_DB_HOST'),
    'database' => env('BLOG_DB_DATABASE'),
    'username' => env('BLOG_DB_USERNAME'),
    'password' => env('BLOG_DB_PASSWORD'),
    'charset'  => 'utf8mb4',                    // WordPress's DB_CHARSET
    'prefix'   => env('BLOG_DB_PREFIX', 'wp_'), // $table_prefix; wp_2_ for multisite site 2
    'options'  => [PDO::ATTR_TIMEOUT => 3],     // a short connect timeout
],
```

Name it `blog`, or set `WORDPRESS_POSTS_CONNECTION`. Its user needs only
`GRANT SELECT ON <database>.<prefix>posts TO …;`

## Configuration

| Key          | Default   | Purpose                             |
|--------------|-----------|-------------------------------------|
| `connection` | `blog`    | Connection to WordPress's database. |
| `tags`       | see below | Elements kept from `post_content`.  |

`tags` defaults to `p`, `h2`, `h3`, `strong`, `em`, `s`, `del`, `sup`, `sub`, `blockquote`, `ul`,
`ol` and `li`. List formatting elements only: `div`, table parts, form controls and raw-text
elements such as `textarea` can break the page around the post.

`PDO::ATTR_TIMEOUT` bounds only the connect; the package reads under a 3-second
`mysqlnd.net_read_timeout`. Laravel retries a lost connection, so one failed attempt can take about
four times the larger of the two.

## Usage

```blade
<x-wordpress-posts::post class="blog-post" />
```

Each tag draws one post with `random()` and renders nothing, not even its wrapper, when that is
`null`. Its attributes go on the wrapper, an `<article>` holding the title as `<h2>` and the content.

To change the markup, put your own at `resources/views/vendor/wordpress-posts/components/post.blade.php`
(`php artisan vendor:publish --tag=wordpress-posts-views` copies the default there). It gets the
`Post` as `$post` and the tag's `$attributes`.

To work with the `Post` yourself, call `random()`:

```php
use WordPressPosts\WordPressPosts;

public function __invoke(WordPressPosts $posts): View
{
    return view('page', ['post' => $posts->random()]);
}
```

```blade
@isset($post)
    <h2>{{ $post->title }}</h2>
    <div>{!! $post->content !!}</div>
@endisset
```

`random()` returns a `WordPressPosts\Post`, or `null` when nothing can be shown now: the connection
has no `database` name (no blog: nothing is read, reported or shown from the cache); the blog has
no published post; the drawn post (and one redraw) is gone, empty or too large; or the id list or
that post is not cached while the database is failing or another request is reading it.

- `title` is plain text, decoded from WordPress's HTML (`Fish &amp; Chips`). Escape it.
- `content` is safe to echo unescaped: only the configured `tags` and `br`, without attributes,
  and bare newlines as `<br />`. Other elements are unwrapped to their text; `script`, `style`,
  `template`, SVG and MathML are dropped whole.
- Password-protected posts are never drawn. Blocks set to *Omit from published content* are left
  out; a block hidden only on some screen sizes shows on all of them.
- Shortcodes are not run. WordPress's media shortcode tags (`[caption]`, `[gallery]`, `[embed]`,
  `[video]`, `[audio]`, `[playlist]`) are removed, keeping a caption's text and an embed's URL; any
  other shortcode prints as written.
- A post over 200 KB is never shown, and elements nested more than 64 deep are dropped.

## Caching and outages

- The id list and each drawn post stay cached for 30 days. New, edited or deleted posts and `tags`
  changes show when the cache expires. To apply one now:

  ```php
  Cache::forget('wordpress-posts:post:' . $id); // the post
  Cache::forget('wordpress-posts:ids');         // the list it is drawn from
  ```

- An empty id list is cached for 5 minutes only, so a new blog's first post shows soon.
- Any failure (unreachable host, missing table or connection) is reported to the exception handler
  and leaves the database alone for 5 minutes.
- One request at a time reads the database; the others skip uncached posts instead of queueing on
  a dead host. A store that cannot hold a key (the `null` store, an unreachable Memcached) turns
  off this and the outage wait: every miss reads, and a failing database is tried and reported on
  every view.
- Posts are cached as arrays, so `cache.serializable_classes` set to `false` works.

## Testing

Mock it in your app's tests:

```php
use WordPressPosts\Post;
use WordPressPosts\WordPressPosts;

$this->mock(WordPressPosts::class)
    ->shouldReceive('random')
    ->andReturn(new Post('A title', '<p>A body.</p>'));
```

The mock reaches `<x-wordpress-posts::post />` too.

## API

| | |
|---|---|
| `<x-wordpress-posts::post />` | Draws a post and renders it, or nothing. Attributes go on the wrapper. |
| `random(): ?Post` on an injected `WordPressPosts\WordPressPosts` | A drawn post, or `null` ([Usage](#usage)). |
| `WordPressPosts\Post` | Readonly `title` (plain text) and `content` (safe HTML). |
| `wordpress-posts:ids`, `wordpress-posts:post:{id}` | The cache keys to forget ([Caching and outages](#caching-and-outages)). |
| `wordpress-posts-config`, `wordpress-posts-views` | The `vendor:publish` tags. |

## Upgrading

### From 1.2

The `timeout`, `cache` and `outage` config keys are gone. The package keeps their 1.x defaults:
a 3-second read timeout, posts cached for 30 days, a failing database left alone for 5 minutes.

- Require `^2.0`.
- A published `config/wordpress-posts.php`: delete the three keys. They are ignored now, so a value
  other than the default no longer applies.

### From 1.1

Nothing to change.

### From 1.0

Nothing to change.

## Development

`composer check` runs Pint (`--test`), PHPStan, then PHPUnit on SQLite in memory.

## Licence

MIT.
