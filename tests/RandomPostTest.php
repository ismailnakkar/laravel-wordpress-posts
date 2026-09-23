<?php

declare(strict_types=1);

namespace WordPressPosts\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use WordPressPosts\Post;
use WordPressPosts\WordPressPosts;

final class RandomPostTest extends TestCase
{
    public function test_a_published_post_is_drawn(): void
    {
        $this->withBlog();
        $this->seedPost(['post_title' => 'Ten ways to share a link', 'post_content' => '<p>Read on.</p>']);

        $this->assertEquals(new Post('Ten ways to share a link', '<p>Read on.</p>'), $this->posts()->random());
    }

    public function test_only_published_posts_are_drawn(): void
    {
        $this->withBlog();
        $this->seedPost(['post_status' => 'draft']);
        $this->seedPost(['post_type' => 'attachment']);
        $this->seedPost(['post_password' => 'secret']); // Published, but shown only with its password.

        $this->assertNull($this->posts()->random());
        $this->assertFalse(Cache::has('wordpress-posts:outage'), 'An empty blog is not an outage.');
    }

    public function test_every_published_post_can_be_drawn(): void
    {
        $this->withBlog();
        $this->seedPost(['post_title' => 'First']);
        $this->seedPost(['post_title' => 'Second']);

        $titles = [];

        foreach (range(1, 64) as $ignored) {
            $titles[(string)$this->posts()->random()?->title] = true;
        }

        ksort($titles);

        $this->assertSame(['First', 'Second'], array_keys($titles));
    }

    public function test_an_app_can_mock_it_in_its_own_tests(): void
    {
        $this->mock(WordPressPosts::class)->shouldReceive('random')->andReturn(new Post('Mocked', ''));

        $this->assertSame('Mocked', $this->posts()->random()?->title);
    }

    public function test_the_configured_connection_is_read(): void
    {
        $this->withBlog(connection: 'wordpress');
        $this->seedPost(['post_title' => 'From elsewhere'], connection: 'wordpress');
        config(['wordpress-posts.connection' => 'wordpress']);

        $this->assertSame('From elsewhere', $this->posts()->random()?->title);
    }

    public function test_the_id_list_is_cached_for_the_configured_lifetime(): void
    {
        config(['wordpress-posts.cache' => 60]);
        $this->withBlog();
        $this->seedPost(['post_title' => 'Cached once']);

        $this->assertSame('Cached once', $this->posts()->random()?->title);

        DB::connection('blog')->table('posts')->delete();
        $this->seedPost(['post_title' => 'Published since']);

        $this->travel(59)->seconds();
        $this->assertSame('Cached once', $this->posts()->random()?->title);

        $this->travel(2)->seconds();
        $this->assertSame('Published since', $this->posts()->random()?->title);
    }

    public function test_a_post_is_cached_for_the_configured_lifetime(): void
    {
        config(['wordpress-posts.cache' => 60]);
        $this->withBlog();
        $id = $this->seedPost(['post_title' => 'Before']);

        $this->assertSame('Before', $this->posts()->random()?->title);

        DB::connection('blog')->table('posts')->where('ID', $id)->update(['post_title' => 'After']);

        $this->travel(59)->seconds();
        $this->assertSame('Before', $this->posts()->random()?->title);

        $this->travel(2)->seconds();
        $this->assertSame('After', $this->posts()->random()?->title);
    }

    public function test_one_request_at_a_time_reads_the_database(): void
    {
        $this->withBlog();
        $this->seedPost();

        $claimed = [];
        DB::connection('blog')->listen(function () use (&$claimed): void {
            $claimed[] = Cache::has('wordpress-posts:outage');
        });

        $this->assertNotNull($this->posts()->random());
        $this->assertSame([true, true], $claimed, 'The id list and the post are each read under the claim.');
        $this->assertFalse(Cache::has('wordpress-posts:outage'), 'A successful read releases the claim.');
    }

    public function test_the_read_timeout_is_short_while_reading_and_restored_after(): void
    {
        $previous = ini_get('mysqlnd.net_read_timeout');

        if ($previous === false) {
            $this->markTestSkipped('mysqlnd is not loaded.');
        }

        config(['wordpress-posts.timeout' => 7]);
        $this->withBlog();
        $this->seedPost();

        $during = [];
        DB::connection('blog')->listen(function () use (&$during): void {
            $during[] = ini_get('mysqlnd.net_read_timeout');
        });

        $this->posts()->random();

        $this->assertSame(['7', '7'], $during, 'The id list and the post are each read under it.');
        $this->assertSame($previous, ini_get('mysqlnd.net_read_timeout'));
    }

    public function test_a_fractional_timeout_is_raised_to_a_whole_second(): void
    {
        if (ini_get('mysqlnd.net_read_timeout') === false) {
            $this->markTestSkipped('mysqlnd is not loaded.');
        }

        config(['wordpress-posts.timeout' => 0.5]);
        $this->withBlog();
        $this->seedPost();

        $during = null;
        DB::connection('blog')->listen(function () use (&$during): void {
            $during = ini_get('mysqlnd.net_read_timeout');
        });

        $this->assertNotNull($this->posts()->random());
        $this->assertSame('1', $during);
    }

    public function test_each_post_is_cached_once_and_as_an_array(): void
    {
        $this->withBlog();
        $first = $this->seedPost(['post_title' => 'First']);
        $second = $this->seedPost(['post_title' => 'Second']);

        $this->posts()->random();

        $this->assertTrue(
            Cache::has('wordpress-posts:post:' . $first) !== Cache::has('wordpress-posts:post:' . $second),
            'Exactly one post is cached after one draw.',
        );
        $this->assertIsArray(Cache::get('wordpress-posts:post:' . $first) ?? Cache::get('wordpress-posts:post:' . $second));

        $queries = 0;
        DB::connection('blog')->listen(function () use (&$queries): void {
            $queries++;
        });

        foreach (range(1, 20) as $ignored) {
            $this->posts()->random();
        }

        $this->assertLessThanOrEqual(1, $queries, 'Only the not-yet-drawn post may still be read.');
    }

    public function test_a_vanished_post_is_cached_as_gone(): void
    {
        $this->withBlog();
        $id = $this->seedPost();
        $this->assertNotNull($this->posts()->random()); // Warms the id list.
        Cache::forget('wordpress-posts:post:' . $id);
        DB::connection('blog')->table('posts')->delete();

        $this->assertNull($this->posts()->random());
        $this->assertFalse(Cache::get('wordpress-posts:post:' . $id));
    }
}
