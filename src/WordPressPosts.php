<?php

declare(strict_types=1);

namespace WordPressPosts;

use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Text;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Throwable;

/** Neither final nor readonly: Mockery refuses both, and an app's tests mock this. */
class WordPressPosts
{
    private const string IDS_KEY = 'wordpress-posts:ids';

    private const string POST_KEY = 'wordpress-posts:post:';

    private const string OUTAGE_KEY = 'wordpress-posts:outage';

    /** Longer posts are left out: no filler is this long, and a crafted one costs seconds to parse. */
    private const int MAX_BYTES = 200_000;

    /** Deeper elements are dropped: only a crafted post nests this far, and each level costs memory. */
    private const int MAX_DEPTH = 64;

    /** Dropped with everything inside: their text is code, not prose. */
    private const array DROPPED = ['script', 'style', 'template'];

    /** Whitespace touching one of these is layout, not a line break. */
    private const array BLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'div', 'figure', 'blockquote', 'pre', 'table', 'hr'];

    /** WordPress's own media shortcodes: the brackets go, a caption's text and an embed's URL stay. */
    private const string SHORTCODES = '~\[/?(?:caption|wp_caption|gallery|embed|video|audio|playlist)(?![\w-])[^\[\]]*+\]~';

    /**
     * A block delimiter as WordPress's block parser reads it. Its JSON ends at the first `}` before
     * `-->` and never runs past a `<!--`, which every delimiter starts with: a crafted post stays linear.
     */
    private const string DELIMITER = '~<!--\s+(/)?wp:(?:[a-z][a-z0-9_-]*/)?[a-z][a-z0-9_-]*\s+(\{(?:[^}<]++|\}(?!\s+/?-->)|<(?!!--))*+\}\s+)?(/)?-->~';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /** A random published post; null when there is none to show, or when it is not cached and cannot be read now. */
    public function random(): ?Post
    {
        // mysqlnd reads this when a connection opens. PDO::ATTR_TIMEOUT bounds only the connect: a host
        // that accepts and then says nothing would otherwise hold the page for mysqlnd's default day.
        // Whole seconds: mysqlnd warns on a fraction, and 0 means no bound at all.
        $readTimeout = ini_set('mysqlnd.net_read_timeout', (string)max(1, (int)$this->config->get('wordpress-posts.timeout')));

        try {
            $ids = $this->remember(self::IDS_KEY, fn (): array => array_map(intval(...), $this->query()->pluck('ID')->all())) ?? [];

            // A second draw when the first is gone: a deleted post stays in the cached list.
            $keys = $ids === [] ? [] : (array)array_rand($ids, min(2, count($ids)));
            shuffle($keys);

            foreach ($keys as $key) {
                if (($post = $this->post($ids[$key])) !== null) {
                    return $post;
                }
            }

            return null;
        } catch (Throwable $e) {
            // The claim taken in remember() stays behind as the outage marker for the whole window.
            report($e);

            return null;
        } finally {
            if ($readTimeout !== false) {
                ini_set('mysqlnd.net_read_timeout', $readTimeout);
            }
        }
    }

    private function post(int $id): ?Post
    {
        // `false`, not null: a cached null reads as a miss. An array, not a Post: an app may refuse
        // to unserialize objects from its cache (`cache.serializable_classes`).
        $post = $this->remember(self::POST_KEY . $id, function () use ($id): array|false {
            // A too-long body comes back as null, so it is never loaded into PHP.
            $row = $this->query()->where('ID', $id)->selectRaw(
                'post_title, CASE WHEN LENGTH(post_content) > ? THEN NULL ELSE post_content END AS post_content',
                [self::MAX_BYTES],
            )->first();

            if ($row === null || $row->post_content === null) {
                return false;
            }

            $content = $this->content((string)$row->post_content);

            // Images, embeds and hidden blocks alone leave no text: nothing to show. `\S` skips `&nbsp;` too.
            return preg_match('/\S/u', strip_tags($content)) !== 1 ? false : [
                'title'   => $this->title((string)$row->post_title),
                'content' => $content,
            ];
        });

        return is_array($post) ? new Post($post['title'], $post['content']) : null;
    }

    /**
     * Cache::remember(), except that a refused claim caches nothing. One request at a time reaches the
     * database: the claim is the outage marker itself, released on success and left for the window on
     * failure, so the requests that arrive while a dead host is timing out return null instead of
     * queueing behind it.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T|null
     */
    private function remember(string $key, Closure $read): mixed
    {
        $value = $this->cache->get($key);

        if ($value !== null) {
            return $value;
        }

        // A refused claim with no marker behind it means the store cannot keep one (an `outage` of 0,
        // the null store): read without the claim rather than never.
        if (! $this->cache->add(self::OUTAGE_KEY, true, (int)$this->config->get('wordpress-posts.outage'))
            && $this->cache->has(self::OUTAGE_KEY)) {
            return null;
        }

        $value = $read();

        // An empty list is held only for the outage window: a blog still being built must not stay empty for a month.
        $this->cache->put($key, $value, (int)$this->config->get($value === [] ? 'wordpress-posts.outage' : 'wordpress-posts.cache'));
        $this->cache->forget(self::OUTAGE_KEY);

        return $value;
    }

    private function query(): Builder
    {
        return $this->db->connection($this->config->get('wordpress-posts.connection'))
            ->table('posts') // WordPress's `wp_` is the connection's prefix.
            ->where('post_status', 'publish')
            ->where('post_type', 'post')
            ->where('post_password', ''); // A protected post is published too, but shown only with its password.
    }

    /** The page prints this unescaped: only the configured tags leave here, with no attributes. */
    private function content(string $postContent): string
    {
        $body = $this->body((string)preg_replace(self::SHORTCODES, '', $this->withoutHiddenBlocks($postContent)));

        return $body === null ? '' : $this->formatting($body, (array)$this->config->get('wordpress-posts.tags'));
    }

    /** WordPress stores the title as HTML too: the block editor saves `&` as `&amp;`. */
    private function title(string $postTitle): string
    {
        $body = $this->body($postTitle);

        foreach ($body?->querySelectorAll('br') ?? [] as $br) {
            $br->replaceWith(' '); // A line break in a title reads as a space.
        }

        return trim((string)$body?->textContent);
    }

    private function body(string $html): ?HTMLElement
    {
        return HTMLDocument::createFromString('<!doctype html>' . $html, LIBXML_NOERROR, 'UTF-8')->body;
    }

    /** A block set to "Omit from published content" keeps its HTML in post_content; WordPress renders nothing for it. */
    private function withoutHiddenBlocks(string $content): string
    {
        if (! str_contains($content, 'blockVisibility')) {
            return $content;
        }

        preg_match_all(self::DELIMITER, $content, $delimiters, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $kept = '';
        $from = 0;      // Where the text not yet copied starts.
        $depth = 0;
        $hidden = null; // The depth the hidden block opened at, while it is being cut.

        foreach ($delimiters as [[$delimiter, $at], [$closer], [$attributes], [$void]]) {
            if ($void !== null) {
                continue; // Self-closing: no HTML of its own.
            }

            if ($closer === null) {
                if ($hidden === null && (json_decode((string)$attributes, true)['metadata']['blockVisibility'] ?? null) === false) {
                    $kept .= substr($content, $from, $at - $from);
                    $hidden = $depth;
                }

                $depth++;
            } elseif ($depth === 0) {
                break; // A closer with nothing open: WordPress parses no block after it.
            } elseif (--$depth === $hidden) {
                $from = $at + strlen($delimiter);
                $hidden = null;
            }
        }

        return $hidden === null ? $kept . substr($content, $from) : $kept;
    }

    /** @param array<int, string> $tags */
    private function formatting(HTMLElement $parent, array $tags, int $depth = 0): string
    {
        $html = '';
        $before = null; // The last element passed: previousElementSibling, without walking back.
        $after = false; // The next element, looked up once per run of nodes between two elements.

        foreach ($parent->childNodes as $node) {
            if ($node instanceof Element) {
                $before = $node;
                $after = false;
            }

            if ($node instanceof Text) {
                $after = $after === false ? $node->nextElementSibling : $after;

                // A classic-editor post keeps its paragraphs as bare newlines; the newlines around
                // block markup (every Gutenberg post) are only layout. Element siblings, because
                // WordPress's block comments sit between that whitespace and the block.
                $html .= trim($node->data) === '' && (in_array($before?->localName, self::BLOCKS, true)
                    || in_array($after?->localName, self::BLOCKS, true))
                    ? $node->data
                    : nl2br(e($node->data));
            } elseif ($node instanceof HTMLElement && $depth < self::MAX_DEPTH && ! in_array($node->localName, self::DROPPED, true)) {
                // HTMLElement is the HTML namespace only: SVG and MathML are dropped whole.
                $tag = $node->localName;
                $inner = $this->formatting($node, $tags, $depth + 1);

                $html .= match (true) {
                    $tag === 'br'               => '<br>',
                    in_array($tag, $tags, true) => "<{$tag}>{$inner}</{$tag}>",
                    default                     => $inner,
                };
            }
        }

        return $html;
    }
}
