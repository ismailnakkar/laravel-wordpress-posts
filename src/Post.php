<?php

declare(strict_types=1);

namespace WordPressPosts;

/** `content` holds only the configured tags, safe to echo unescaped; `title` is plain text to escape. */
final readonly class Post
{
    public function __construct(
        public string $title,
        public string $content,
    ) {}
}
