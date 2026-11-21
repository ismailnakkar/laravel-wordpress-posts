<?php

declare(strict_types=1);

namespace WordPressPosts;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

/** @internal */
final class WordPressPostsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/wordpress-posts.php', 'wordpress-posts');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'wordpress-posts');

        $this->callAfterResolving(BladeCompiler::class, static function (BladeCompiler $blade): void {
            $blade->componentNamespace('WordPressPosts\\View', 'wordpress-posts');
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/wordpress-posts.php' => config_path('wordpress-posts.php'),
            ], 'wordpress-posts-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/wordpress-posts'),
            ], 'wordpress-posts-views');
        }
    }
}
