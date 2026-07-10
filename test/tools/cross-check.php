<?php

declare(strict_types=1);

// Dev tool: compare the PHP port's output against Readability.js output
// produced by cross-check.mjs. Reports per-page differences field by field
// and structurally for content.
//
// Usage: php test/tools/cross-check.php [slug ...]

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../DomCompare.php';

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use fivefilters\Readability\Test\DomCompare;

$root = __DIR__ . '/../test-pages';
$jsOutput = __DIR__ . '/js-output';

if (!is_dir($jsOutput)) {
    fwrite(STDERR, "No js-output directory. Run: cd test/tools && npm install && node cross-check.mjs\n");
    exit(1);
}

$only = array_slice($argv, 1);
$mismatches = 0;
$pages = 0;

foreach (scandir($root) as $dir) {
    if ($dir[0] === '.' || ($only && !in_array($dir, $only, true))) {
        continue;
    }
    $jsFile = "{$jsOutput}/{$dir}.json";
    if (!file_exists($jsFile)) {
        continue;
    }
    $pages++;
    $js = json_decode(file_get_contents($jsFile), true);
    $source = trim(file_get_contents("{$root}/{$dir}/source.html"));

    try {
        $document = \Dom\HTMLDocument::createFromString($source, LIBXML_NOERROR, 'UTF-8');
        removeCommentNodesRecursively($document);
        $article = new Readability(new Configuration(
            classesToPreserve: ['caption'],
            fixRelativeURLs: true,
            originalURL: 'http://fakehost/test/page.html',
        ))->parseDocument($document);
    } catch (\Throwable $e) {
        $article = null;
        $phpError = $e->getMessage();
    }

    $diffs = [];
    if (isset($js['error'])) {
        if ($article !== null) {
            $diffs[] = "JS failed ({$js['error']}) but PHP succeeded";
        }
    } elseif ($article === null) {
        $diffs[] = 'PHP failed (' . ($phpError ?? 'unknown') . ') but JS succeeded';
    } else {
        foreach (['title', 'byline', 'dir', 'lang', 'excerpt', 'siteName', 'publishedTime'] as $field) {
            // JSON.stringify drops undefined values, and JS '' metadata maps to PHP null.
            $jsValue = ($js[$field] ?? null) ?: null;
            if (($article->$field ?: null) !== $jsValue) {
                $diffs[] = sprintf('%s: php %s / js %s', $field, var_export($article->$field, true), var_export($js[$field] ?? null, true));
            }
        }
        $contentDiff = DomCompare::compare($js['content'], $article->content);
        if ($contentDiff !== null) {
            $diffs[] = "content: {$contentDiff}";
        }
    }

    if ($diffs) {
        $mismatches++;
        echo "== {$dir}\n";
        foreach ($diffs as $diff) {
            echo "   {$diff}\n";
        }
    }
}

echo "\n{$mismatches} of {$pages} pages differ from Readability.js\n";
exit($mismatches ? 1 : 0);

function removeCommentNodesRecursively(\Dom\Node $node): void
{
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $child = $node->childNodes->item($i);
        if ($child->nodeType === XML_COMMENT_NODE) {
            $node->removeChild($child);
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            removeCommentNodesRecursively($child);
        }
    }
}
