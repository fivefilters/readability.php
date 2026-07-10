<?php

declare(strict_types=1);

namespace fivefilters\Readability;

/**
 * The result of a successful parse.
 *
 * Mirrors the object returned by Readability.js parse().
 */
final readonly class Article
{
    public function __construct(
        /** Article title. */
        public string $title,
        /** Author metadata, if found. */
        public ?string $byline,
        /** Content direction ("ltr"/"rtl"), if found. */
        public ?string $dir,
        /** Content language, if found. */
        public ?string $lang,
        /** HTML string of the processed article content. */
        public string $content,
        /** Text content of the article, with all the HTML tags removed. */
        public string $textContent,
        /** Length of the article's text content, in characters (Unicode code points). */
        public int $length,
        /** Article description, or short excerpt from the content. */
        public ?string $excerpt,
        /** Name of the site, if found. */
        public ?string $siteName,
        /** Published time, if found. */
        public ?string $publishedTime,
        /** The article content as a DOM element, for callers who want to keep working on the tree. */
        public \Dom\Element $contentElement,
    ) {
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
