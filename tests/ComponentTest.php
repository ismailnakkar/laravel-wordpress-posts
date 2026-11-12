<?php

declare(strict_types=1);

namespace WordPressPosts\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use WordPressPosts\Post;
use WordPressPosts\WordPressPosts;

final class ComponentTest extends TestCase
{
    public function test_the_title_is_escaped_and_the_content_is_not(): void
    {
        $this->drawing(new Post('Fish & <Chips>', '<p>Fried <strong>twice</strong>.</p>'));

        $html = $this->rendered('<x-wordpress-posts::post />');

        $this->assertStringContainsString('<h2>Fish &amp; &lt;Chips&gt;</h2>', $html);
        $this->assertStringContainsString('<p>Fried <strong>twice</strong>.</p>', $html);
    }

    public function test_without_a_post_nothing_renders_not_even_the_wrapper(): void
    {
        $this->withBlog(); // Unmocked: an empty blog draws null.

        $this->assertSame('', $this->rendered('<x-wordpress-posts::post class="blog" />'));
    }

    public function test_an_apps_mock_reaches_each_component_once(): void
    {
        $this->mock(WordPressPosts::class)->shouldReceive('random')->twice()->andReturn(new Post('Mocked', ''));

        $this->assertSame(2, substr_count($this->rendered('<x-wordpress-posts::post /><x-wordpress-posts::post />'), '<h2>Mocked</h2>'));
    }

    public function test_attributes_land_on_the_wrapper(): void
    {
        $this->drawing(new Post('Title', ''));

        $this->assertStringStartsWith('<article class="blog" id="filler">', $this->rendered('<x-wordpress-posts::post class="blog" id="filler" />'));
    }

    #[DefineEnvironment('withAppViews')]
    public function test_an_app_view_at_the_vendor_path_replaces_the_markup(): void
    {
        $this->drawing(new Post('Title', '<p>Body.</p>'));

        $this->assertSame('<section class="blog"><h4>Title</h4></section>', trim($this->rendered('<x-wordpress-posts::post class="blog" />')));
    }

    protected function withAppViews(Application $app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
    }

    /** Recompiled every run: a compiled view left from an earlier run would keep resolving a removed component. */
    private function rendered(string $blade): string
    {
        return Blade::render($blade, deleteCachedView: true);
    }

    private function drawing(Post $post): void
    {
        $this->mock(WordPressPosts::class)->shouldReceive('random')->once()->andReturn($post);
    }
}
