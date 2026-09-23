<?php

declare(strict_types=1);

namespace WordPressPosts\Tests;

use Illuminate\Support\Facades\Cache;

final class ContentTest extends TestCase
{
    public function test_only_formatting_survives_without_attributes_or_foreign_content(): void
    {
        $this->assertSame(
            '<p>Read <strong>this &amp; that</strong>.</p><br><em>End</em>',
            $this->contentOf('<p onmouseover="alert(1)">Read <strong style="position:fixed">this &amp; that</strong>.</p>'
                . '<svg><foreignObject><p>Foreign</p></foreignObject></svg>'
                . '<script>alert(2)</script><img src=x onerror=alert(3)><br><em title="unsafe">End</em>'),
        );
    }

    public function test_escaped_markup_beside_a_block_stays_text(): void
    {
        $content = '<p>a</p>&lt;img src=x onerror=alert(1)&gt;<p>&amp;lt;b&amp;gt;</p>';

        $this->assertSame($content, $this->contentOf($content));
    }

    public function test_an_unlisted_element_is_unwrapped_to_its_text(): void
    {
        $this->assertSame('<p>A link and a <strong>word</strong></p>', $this->contentOf(
            '<p><a href="javascript:alert(1)">A link</a> and a <span class="x"><strong>word</strong></span></p>',
        ));
    }

    public function test_code_carrying_elements_are_dropped_whatever_the_tag_list_says(): void
    {
        config(['wordpress-posts.tags' => ['p', 'script', 'style', 'template']]);

        $this->assertSame('<p>Kept</p>', $this->contentOf('<p>Kept</p><script>alert(1)</script><style>p{}</style><template><p>x</p></template>'));
    }

    public function test_the_tag_list_is_configurable(): void
    {
        config(['wordpress-posts.tags' => ['p']]);

        $this->assertSame('<p>Plain bold</p>', $this->contentOf('<p>Plain <strong>bold</strong></p>'));
    }

    public function test_edits_quotes_and_super_and_subscripts_keep_their_markup(): void
    {
        $content = '<p><del>Closed</del> <s>today</s>. E = mc<sup>2</sup>, H<sub>2</sub>O.</p><blockquote><p>Quoted.</p></blockquote>';

        $this->assertSame($content, $this->contentOf($content));
    }

    public function test_bare_newlines_become_breaks_and_comments_vanish(): void
    {
        $this->assertSame("One<br />\nTwo", $this->contentOf("<!-- wp:paragraph -->One\nTwo<!-- /wp:paragraph -->"));
    }

    public function test_the_newlines_around_block_markup_are_layout_not_breaks(): void
    {
        $content = $this->contentOf("<!-- wp:paragraph -->\n<p>First.</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->\n\n"
            . "<!-- wp:list-item -->\n<li>Two</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n"
            . "<!-- wp:paragraph -->\n<p>Last.</p>\n<!-- /wp:paragraph -->");

        $this->assertStringNotContainsString('<br', $content);
        $this->assertSame('<p>First.</p><ul><li>One</li><li>Two</li></ul><p>Last.</p>', preg_replace('/\s+/', '', $content));
    }

    public function test_inline_newlines_still_break(): void
    {
        $this->assertSame("<strong>a</strong><br />\n<em>b</em>\n<p>c</p>", $this->contentOf("<strong>a</strong>\n<em>b</em>\n<p>c</p>"));
    }

    public function test_blocks_omitted_from_published_content_are_left_out(): void
    {
        $hidden = '{"metadata":{"blockVisibility":false}}';

        $this->assertSame('<p>Shown.</p><p>Also shown.</p>', $this->contentOf(
            "<!-- wp:latest-posts {$hidden} /-->"
            . "<!-- wp:paragraph {$hidden} --><p>Hidden first.</p><!-- /wp:paragraph -->"
            . '<!-- wp:paragraph --><p>Shown.</p><!-- /wp:paragraph -->'
            . "<!-- wp:group {$hidden} --><div>"
            . '<!-- wp:group --><div><p>Hidden inside.</p></div><!-- /wp:group -->'
            . "<!-- wp:paragraph {$hidden} --><p>Hidden twice.</p><!-- /wp:paragraph -->"
            . '<p>Hidden last.</p></div><!-- /wp:group -->'
            . '<!-- wp:paragraph --><p>Also shown.</p><!-- /wp:paragraph -->'
            . "<!-- wp:paragraph {$hidden} --><p>Never closed.</p>",
        ));
    }

    public function test_blocks_are_read_as_wordpress_parses_them(): void
    {
        $this->assertSame('<p>A</p><p>B</p><p>C</p>', $this->contentOf(
            // Decoded, not matched as text: spaced JSON hides, a key outside `metadata` does not.
            '<!-- wp:paragraph {"metadata":{"blockVisibility": false}} --><p>Spaced.</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph {"blockVisibility":false} --><p>A</p><!-- /wp:paragraph -->'
            // A raw `<` in a child's JSON, and a comment WordPress does not read as a delimiter.
            . '<!-- wp:group {"metadata":{"blockVisibility":false}} --><div>'
            . '<!-- wp:heading {"content":"<em>x</em>"} --><h2>Hidden.</h2><!-- /wp:heading -->'
            . '<!-- /wp:a/b/c --><p>Still hidden.</p></div><!-- /wp:group -->'
            // A closer with nothing open: WordPress parses no block after it.
            . '<p>B</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph {"metadata":{"blockVisibility":false}} --><p>C</p><!-- /wp:paragraph -->',
        ));
    }

    public function test_core_shortcodes_are_stripped_to_their_text(): void
    {
        $this->assertSame('<p>Look:</p>My cat.[gallery-note]', $this->contentOf(
            '<p>Look:</p>[caption id="attachment_1" width="300"]<img src="cat.jpg">My cat.[/caption][gallery ids="1,2"][gallery-note]',
        ));
    }

    public function test_a_post_too_large_to_reduce_is_left_out(): void
    {
        $this->withBlog();
        $id = $this->seedPost(['post_content' => str_repeat('a', 200_001)]);

        $this->assertNull($this->posts()->random());
        $this->assertFalse(Cache::get('wordpress-posts:post:' . $id), 'Cached as gone, so it is not read again.');
    }

    public function test_a_post_at_the_size_limit_is_kept(): void
    {
        $this->assertSame(str_repeat('a', 200_000), $this->contentOf(str_repeat('a', 200_000)));
    }

    public function test_a_post_with_no_text_left_is_left_out(): void
    {
        $this->withBlog();
        $id = $this->seedPost(['post_content' => "[gallery ids=\"1,2\"]<img src=\"a.jpg\">\n\n&nbsp;"]);

        $this->assertNull($this->posts()->random());
        $this->assertFalse(Cache::get('wordpress-posts:post:' . $id), 'Cached as gone, not failed.');
    }

    public function test_a_crafted_run_of_comments_reduces_in_linear_time(): void
    {
        // Whitespace between comments once made every text node walk its siblings: this took 11 s.
        $started = microtime(true);

        $this->contentOf('x' . str_repeat(' <?>', 49_999)); // The shortest comment there is, just under the size limit.

        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    public function test_nesting_up_to_64_deep_is_kept(): void
    {
        $this->assertSame(
            str_repeat('<blockquote>', 64) . 'deep' . str_repeat('</blockquote>', 64),
            $this->contentOf(str_repeat('<blockquote>', 64) . 'deep'),
        );
    }

    public function test_nesting_deeper_than_any_real_post_is_dropped(): void
    {
        $this->assertSame(
            '<p>kept</p>' . str_repeat('<blockquote>', 64) . str_repeat('</blockquote>', 64),
            $this->contentOf('<p>kept</p>' . str_repeat('<blockquote>', 65) . 'deep'),
        );
    }

    public function test_the_title_is_decoded_to_plain_text(): void
    {
        $this->withBlog();
        $this->seedPost(['post_title' => '<em> Fish &amp; Chips,<br>1<2 </em>']);

        $this->assertSame('Fish & Chips, 1<2', $this->posts()->random()?->title);
    }

    private function contentOf(string $postContent): string
    {
        $this->withBlog();
        $this->seedPost(['post_content' => $postContent]);

        $post = $this->posts()->random();
        $this->assertNotNull($post);

        return $post->content;
    }
}
