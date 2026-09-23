<?php

declare(strict_types=1);

namespace WordPressPosts\Tests;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

final class OutageTest extends TestCase
{
    public function test_a_missing_connection_is_an_outage_not_an_exception(): void
    {
        config(['database.connections.blog' => null]);
        DB::purge('blog');

        $this->assertNull($this->posts()->random());
        $this->assertTrue(Cache::has('wordpress-posts:outage'));
    }

    public function test_a_failing_database_is_tried_once_per_outage_window(): void
    {
        // No table: the read throws, the same shape as an unreachable host. Each attempt reports once;
        // a failing query fires no QueryExecuted, so listening on the connection would count nothing.
        Exceptions::fake();
        config(['wordpress-posts.outage' => 10]);
        $this->withBlog(withTable: false);

        $this->assertNull($this->posts()->random());
        $this->assertNull($this->posts()->random());
        Exceptions::assertReportedCount(1);

        $this->travel(9)->seconds();
        $this->assertNull($this->posts()->random());
        Exceptions::assertReportedCount(1);

        $this->travel(2)->seconds();
        $this->assertNull($this->posts()->random());
        Exceptions::assertReportedCount(2);
    }

    public function test_a_cached_post_is_still_shown_while_the_database_is_failing(): void
    {
        $this->withBlog();
        $this->seedPost(['post_title' => 'Warm']);
        $this->assertSame('Warm', $this->posts()->random()?->title);

        Cache::put('wordpress-posts:outage', true, 300);

        $this->assertSame('Warm', $this->posts()->random()?->title);
    }

    public function test_a_post_not_yet_cached_is_skipped_while_the_database_is_failing(): void
    {
        $this->withBlog();
        $this->seedPost();
        Cache::put('wordpress-posts:outage', true, 300);
        $this->expectsDatabaseQueryCount(0, 'blog');

        $written = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
            $written[] = $event->key;
        });

        $this->assertNull($this->posts()->random());
        $this->assertSame([], $written, 'A refused claim caches nothing.');
    }

    public function test_a_zero_outage_window_still_reads_the_blog(): void
    {
        config(['wordpress-posts.outage' => 0]);
        $this->withBlog();
        $this->seedPost(['post_title' => 'Read']);

        $this->assertSame('Read', $this->posts()->random()?->title);
    }
}
