<?php

declare(strict_types=1);

namespace fivefilters\Readability\Test;

final readonly class TestPage
{
    public function __construct(
        public string $slug,
        public string $source,
        public string $expectedContent,
        public array $expectedMetadata,
    ) {
    }
}
