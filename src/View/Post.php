<?php

declare(strict_types=1);

namespace WordPressPosts\View;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use WordPressPosts\Post as DrawnPost;
use WordPressPosts\WordPressPosts;

/** `<x-wordpress-posts::post />`: one post drawn per tag, nothing at all without one. */
final class Post extends Component
{
    public readonly ?DrawnPost $post;

    public function __construct(WordPressPosts $posts)
    {
        $this->post = $posts->random();
    }

    public function shouldRender(): bool
    {
        return $this->post !== null;
    }

    public function render(): View
    {
        return view('wordpress-posts::components.post');
    }
}
