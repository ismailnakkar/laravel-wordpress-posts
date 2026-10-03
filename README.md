# Laravel WordPress Posts

Draw a random published post from a WordPress database and print it on a Laravel page: cached,
safe to echo, and harmless when the blog is down.

It exists for one job: a filler post beside the real content of a page. It reads WordPress's
database directly, read-only, and never calls WordPress itself.

- [Requirements](#requirements)
- [Install](#install)
- [Use](#use)
- [What you get](#what-you-get)
- [Caching and outages](#caching-and-outages)
- [Configuration](#configuration)
- [Testing](#testing)
- [Licence](#licence)

## Requirements

PHP 8.4 with `ext-dom`, Laravel 12.1 or 13, and a MySQL or MariaDB connection through mysqlnd. The
bounded read (see [Caching and outages](#caching-and-outages)) works only on mysqlnd; on another
driver it is skipped silently.

## Install

```bash
composer require ismailnakkar/laravel-wordpress-posts
php artisan vendor:publish --tag=wordpress-posts-config   # only to change a default
```

Add a connection to WordPress's database in `config/database.php`, for this package alone:

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

`blog` is the default name; `WORDPRESS_POSTS_CONNECTION` picks another.

## Use

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

`random()` returns a `WordPressPosts\Post`, or `null` when there is nothing to show: the blog has
no published post, the drawn one is gone, empty or too large, or it is not cached and cannot be
read now (the database is failing, or another request is reading it). Render the page without it
on `null`.

## What you get

- **`title` is plain text.** WordPress stores it as HTML (`Fish &amp; Chips`); the package decodes
  it. Escape it with `{{ }}` like any other string.

- **`content` is safe to echo unescaped.** It keeps only the configured formatting tags, always
  without attributes, and turns WordPress's bare newlines into `<br />`. Every other element is
  unwrapped to its text, and `script`, `style`, `template`, SVG and MathML are dropped whole.
  Do not run it through `strip_tags()`: that keeps attributes on the tags it allows, so
  `<h2 onmouseover=…>` survives it.

- **Hidden posts and blocks stay out.** Password-protected posts are never drawn, and blocks set
  to *Omit from published content* are left out. A block hidden only on some screen sizes is
  shown on all of them.

- **Shortcodes are not run.** WordPress's media shortcodes (`[caption]`, `[gallery]`, `[embed]`,
  `[video]`, `[audio]`, `[playlist]`) lose their brackets, so a caption keeps its text and an
  embed its URL. Any other shortcode prints as written.

- **Crafted posts are bounded.** A post over 200 KB is never shown, and elements nested more than
  64 deep are dropped, so the worst crafted post takes a second or two to reduce, once per cache
  period. No real post comes close to either limit.

## Caching and outages

- **Warm page views make no query.** The list of published ids and each drawn post are cached for
  `cache` seconds (30 days). A post is cached already reduced, so a change to `tags` reaches it
  when its cache expires.

- **Changes in WordPress wait for the cache too.** A newly published post joins the draw when the
  id list expires; an edited, unpublished or deleted one changes on the page when its own cache
  expires. To apply a change now:

  ```php
  Cache::forget('wordpress-posts:post:' . $id); // the post
  Cache::forget('wordpress-posts:ids');         // the list it is drawn from
  ```

- **An empty blog is retried soon.** An empty id list is cached for `outage` seconds, not `cache`,
  so a blog that had no published post yet gives filler shortly after its first one.

- **A gone post is skipped.** If the drawn post was deleted or unpublished since the id list was
  cached, one other id is drawn before giving up.

- **A failing database is left alone.** Any failure (an unreachable host, a missing table, a
  missing connection) is reported through the exception handler and leaves the database alone for
  `outage` seconds (5 minutes). Cached posts are still shown meanwhile, as long as the id list is
  cached too; it expires first, and without it every view returns `null`.

- **One request at a time reads the database.** The outage marker doubles as the claim: taken on a
  cache miss, released on success and left in place on failure, so the requests that arrive while
  a dead host is timing out get `null` at once instead of queueing behind it. An `outage` of 0, or
  a cache that cannot hold the marker (the `null` store, an unreachable Memcached), turns off both:
  every miss reads the database, and a failing one is tried and reported on every view.

- **Reads are bounded.** `PDO::ATTR_TIMEOUT` bounds only the TCP connect. A host that accepts and
  then says nothing is bounded by `mysqlnd.net_read_timeout`, whose default is a day, so the
  package sets it to `timeout` seconds for its read and restores the setting afterwards. Laravel
  retries a lost connection, so one failed attempt can take about four times the larger of the
  two timeouts.

- **Give the package its own connection.** mysqlnd reads the timeout only when a connection opens.
  A connection the app opened first keeps its own (a day by default), and one the package opens
  holds every later query on it to `timeout` seconds until it closes.

- **Posts are cached as arrays, never objects.** The package works with
  `cache.serializable_classes` set to `false`.

## Configuration

| Key          | Default   | Purpose                                                  |
|--------------|-----------|----------------------------------------------------------|
| `connection` | `blog`    | The connection to WordPress's database.                  |
| `timeout`    | `3`       | MySQL read timeout while the package reads, in seconds.  |
| `cache`      | `2592000` | Seconds the id list and each post are cached.            |
| `outage`     | `300`     | Seconds a failing database is left alone.                |
| `tags`       | see below | Formatting kept from `post_content`; `br` always.        |

The default `tags` are `p`, `h2`, `h3`, `strong`, `em`, `s`, `del`, `sup`, `sub`, `blockquote`,
`ul`, `ol` and `li`. List formatting elements only: `div`, table parts, form controls and raw-text
elements such as `plaintext` or `textarea` can break the page around the post.

## Testing

In your app's tests, mock it like any other class:

```php
use WordPressPosts\Post;
use WordPressPosts\WordPressPosts;

$this->mock(WordPressPosts::class)
    ->shouldReceive('random')
    ->andReturn(new Post('A title', '<p>A body.</p>'));
```

The package's own checks:

```bash
composer check
```

## Licence

MIT.
