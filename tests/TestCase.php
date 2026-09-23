<?php

declare(strict_types=1);

namespace WordPressPosts\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use WordPressPosts\WordPressPosts;
use WordPressPosts\WordPressPostsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [WordPressPostsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function posts(): WordPressPosts
    {
        return $this->app->make(WordPressPosts::class);
    }

    /** A WordPress `posts` table behind the default `blog` connection, prefixed as WordPress prefixes it. */
    protected function withBlog(bool $withTable = true, string $connection = 'blog'): void
    {
        config(["database.connections.{$connection}" => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => 'wp_',
        ]]);

        DB::purge($connection);

        if ($withTable) {
            Schema::connection($connection)->create('posts', function (Blueprint $table): void {
                $table->increments('ID');
                $table->string('post_title');
                $table->text('post_content');
                $table->string('post_status');
                $table->string('post_type');
                $table->string('post_password')->default('');
            });
        }
    }

    /** @param array<string, string> $attributes */
    protected function seedPost(array $attributes = [], string $connection = 'blog'): int
    {
        return DB::connection($connection)->table('posts')->insertGetId([
            'post_title'   => 'A post',
            'post_content' => 'Body.',
            'post_status'  => 'publish',
            'post_type'    => 'post',
            ...$attributes,
        ], 'ID');
    }
}
