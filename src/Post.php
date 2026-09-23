<?php

declare(strict_types=1);

namespace WordPressPosts;

/**
 * A published post as the page prints it. `content` is already reduced to the configured
 * formatting tags, so it is echoed unescaped; `title` is plain text and must be escaped.
 */
final readonly class Post
{
    public function __construct(
        public string $title,
        public string $content,
    ) {}
}
