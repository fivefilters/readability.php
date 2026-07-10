# Upgrading from 3.x to 4.0

Version 4.0 is a ground-up rewrite on PHP's native DOM extension (the Lexbor HTML parser included since PHP 8.4), at feature parity with Mozilla's Readability.js v0.6.0. The public API changed: this guide shows everything a 3.x user needs to update.

## Requirements

| | 3.x | 4.0 |
| --- | --- | --- |
| PHP | >= 8.1 | >= 8.4 |
| Extensions | dom, xml, mbstring | dom, mbstring |
| HTML parser | libxml or HTML5-PHP | native (Lexbor) |

```bash
composer require "fivefilters/readability.php:^4.0"
```

## The one-minute version

**3.x** — `parse()` returns a bool and you read results off the Readability instance:

```php
$readability = new Readability(new Configuration());

try {
    $readability->parse($html);
    echo $readability->getTitle();
    echo $readability->getContent();
} catch (ParseException $e) {
    // ...
}
```

**4.0** — `parse()` returns a readonly `Article` value object (or throws):

```php
$readability = new Readability(new Configuration());

try {
    $article = $readability->parse($html);
    echo $article->title;
    echo $article->content;
} catch (ParseException $e) {
    // ...
}
```

## Reading the result

Getters on the Readability instance became properties on the returned `Article`:

| 3.x | 4.0 |
| --- | --- |
| `$readability->getTitle()` | `$article->title` |
| `$readability->getContent()` | `$article->content` |
| `$readability->getExcerpt()` | `$article->excerpt` |
| `$readability->getAuthor()` | `$article->byline` |
| `$readability->getSiteName()` | `$article->siteName` |
| `$readability->getDirection()` | `$article->dir` |
| `$readability->getDOMDocument()` | `$article->contentElement` (see below) |
| `echo $readability;` | `echo $article;` (same as `$article->content`) |
| `$readability->getImage()`, `->getImages()` | removed (see below) |
| — | new: `$article->textContent` (plain text) |
| — | new: `$article->length` (character count of textContent) |
| — | new: `$article->lang` |
| — | new: `$article->publishedTime` |

`Article` is immutable — there are no setters, and a Readability instance holds no result state (you can safely reuse one instance for multiple `parse()` calls, or parse concurrently in Fibers with separate instances).

### getDOMDocument() → contentElement

3.x returned a `DOMDocument` (the legacy DOM API). 4.0 exposes the article as a `\Dom\Element` from PHP's new DOM API:

```php
$element = $article->contentElement;          // \Dom\Element (the article container)
$document = $element->ownerDocument;          // \Dom\Document, if you need the document
$firstParagraph = $element->querySelector('p'); // CSS selectors work natively
```

## Configuration

3.x used an options array (or fluent setters). 4.0 uses a readonly object with named constructor arguments:

```php
// 3.x
$configuration = new Configuration([
    'fixRelativeURLs' => true,
    'originalURL' => 'https://example.com/article.html',
]);
// or: $configuration->setFixRelativeURLs(true)->setOriginalURL('...');

// 4.0
$configuration = new Configuration(
    fixRelativeURLs: true,
    originalURL: 'https://example.com/article.html',
);
// or: Configuration::fromArray(['fixRelativeURLs' => true, 'originalURL' => '...'])
```

Option mapping:

| 3.x | 4.0 | Notes |
| --- | --- | --- |
| `maxTopCandidates` | `nbTopCandidates` | renamed to match Readability.js |
| `charThreshold` | `charThreshold` | unchanged |
| `fixRelativeURLs` | `fixRelativeURLs` | unchanged |
| `originalURL` | `originalURL` | unchanged; now also honors `<base href>` |
| `keepClasses` | `keepClasses` | unchanged |
| `disableJSONLD` | `disableJSONLD` | unchanged |
| `stripUnlikelyCandidates` | `stripUnlikelyCandidates` | unchanged |
| `weightClasses` | `weightClasses` | unchanged |
| `cleanConditionally` | `cleanConditionally` | unchanged |
| `articleByline` | removed | byline detection is always on, as in Readability.js |
| `parser` | removed | always the native Lexbor parser |
| `substituteEntities` | removed | libxml workaround, no longer needed |
| `normalizeEntities` | removed | libxml workaround, no longer needed |
| `summonCthulhu` | removed | libxml workaround, no longer needed |
| `logger` (PSR-3) | removed | use `debug: true` (see below) |
| — | `debug` | new: log via `error_log()`, like Readability.js's debug flag |
| — | `maxElemsToParse` | new, from Readability.js |
| — | `classesToPreserve` | new, from Readability.js |
| — | `allowedVideoRegex` | new, from Readability.js |
| — | `linkDensityModifier` | new, from Readability.js 0.6.0 |

## Removed features and their replacements

### Image extraction (`getImage()` / `getImages()`)

Not part of Readability.js, so dropped. Both are a few lines with the new DOM API if you need them:

```php
// Images inside the extracted article (was: getImages())
$images = [];
foreach ($article->contentElement->querySelectorAll('img[src]') as $img) {
    $images[] = $img->getAttribute('src');
}

// The page's "main" image (was: getImage()) — read it off the original document
$doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
$mainImage = $doc->querySelector('meta[property="og:image"]')?->getAttribute('content');
```

### PSR-3 logging

Replaced by the `debug` option, matching Readability.js. With `debug: true`, the same messages Readability.js logs go to `error_log()`. There is no logger injection point anymore; if you need the output somewhere specific, set PHP's `error_log` ini setting for the duration of the call.

## Behavior changes to be aware of

- **`parse()` never returns `false`/`bool`.** It returns an `Article` or throws `ParseException` — including for empty input, documents over `maxElemsToParse`, and pages where no article could be found (all cases where Readability.js returns `null`).
- **The content is wrapped** in `<div id="readability-page-1" class="page">…</div>`, exactly as Readability.js outputs. If you post-process the HTML, account for the wrapper.
- **Byline is always detected and removed from the content.** In 3.x this only happened with `articleByline` enabled.
- **Relative URL fixing follows the WHATWG URL Standard** (what browsers and Readability.js do), via PHP 8.5's native `Uri\WhatWg\Url` or rowbot/url on PHP 8.4. Edge-case outputs may differ slightly from 3.x's RFC 3986 resolution (e.g. `https://example.com` serializes as `https://example.com/`).
- **Encoding:** input is parsed with the encoding declared in the document, defaulting to UTF-8 (as a browser would). The 3.x mb_* guessing hacks are gone — supply UTF-8 or make sure the document declares its charset.
- **Class attributes** are stripped by default as before (`keepClasses: false`), but the preserved-classes list now also honors `classesToPreserve`.

## New in 4.0

- `Readerable::isProbablyReaderable($html)` — Mozilla's quick pre-check for whether a page is worth parsing, ported for the first time.
- `parseDocument(\Dom\HTMLDocument $document)` — parse a document you've already created (note: it's modified in place).
- `$article->textContent`, `->length`, `->lang`, `->publishedTime` outputs.
- Metadata sources at Readability.js 0.6.0 parity: JSON-LD (`@graph`, `@context` objects), parsely, `article:author`, `itemprop`.
