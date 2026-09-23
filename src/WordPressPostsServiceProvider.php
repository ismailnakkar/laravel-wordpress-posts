<?php

declare(strict_types=1);

namespace WordPressPosts;

use Illuminate\Support\ServiceProvider;

class WordPressPostsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No binding: WordPressPosts is stateless and the container autowires it.
        $this->mergeConfigFrom(__DIR__ . '/../config/wordpress-posts.php', 'wordpress-posts');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/wordpress-posts.php' => config_path('wordpress-posts.php'),
        ], 'wordpress-posts-config');
    }
}
