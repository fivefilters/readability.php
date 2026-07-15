# Upgrading from 3.x to 4.0

Version 4.0 is a ground-up rewrite on PHP's native DOM extension (the Lexbor HTML parser included since PHP 8.4), at feature parity with Mozilla's Readability.js v0.6.0. The public API changed: this guide shows everything a 3.x user needs to update.

## Requirements

| | 3.x | 4.0 |
| --- | --- | --- |
| PHP | >= 8.1 | >= 8.4 |
| Extensions | dom, xml, mbstring | dom, mbstring |
| HTML parser | libxml or HTML5-PHP | native (Lexbor) |

```bash
composer require "fivefilters/readability.php:^4.0@beta"
```

(The `@beta` stability flag is needed while 4.0 is in beta; drop it once the stable release is out.)

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
$readability = new Readability(); // options are named constructor arguments now, all optional

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
| `$readability->getImage()` | `$article->image` |
| `$readability->getImages()` | `$article->images` |
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

3.x required a `Configuration` built from an options array (or fluent setters). In 4.0, options are named arguments passed directly to `Readability` (like the options object in Readability.js), and they're all optional — `new Readability()` uses the defaults:

```php
// 3.x
$configuration = new Configuration([
    'fixRelativeURLs' => true,
    'originalURL' => 'https://example.com/article.html',
]);
// or: $configuration->setFixRelativeURLs(true)->setOriginalURL('...');
$readability = new Readability($configuration);

// 4.0
$readability = new Readability(
    fixRelativeURLs: true,
    originalURL: 'https://example.com/article.html',
);
```

A readonly `Configuration` object still exists, taking the same named arguments, for options built up separately or shared between instances: `new Readability(new Configuration(fixRelativeURLs: true))`. To build one from an options array, use `Configuration::fromArray(['fixRelativeURLs' => true])`.

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
| `articleByline` | `keepInlineByline` | see the note below; the byline is now always extracted, and this only controls whether it stays in the content |
| `logger` (PSR-3) | `logger` | still a PSR-3 `LoggerInterface`; passed as a named argument instead of via `setLogger()` |
| `parser` | removed | always the native Lexbor parser |
| `substituteEntities` | removed | libxml workaround, no longer needed |
| `normalizeEntities` | removed | libxml workaround, no longer needed |
| `summonCthulhu` | removed | libxml workaround, no longer needed |
| — | `debug` | new: log via `error_log()`, like Readability.js's debug flag |
| — | `maxElemsToParse` | new, from Readability.js |
| — | `classesToPreserve` | new, from Readability.js |
| — | `allowedVideoRegex` | new, from Readability.js |
| — | `linkDensityModifier` | new, from Readability.js 0.6.0 |

## Changed features

### Image extraction: `getImage()` / `getImages()` → `$article->image` / `->images`

Image extraction (not part of Readability.js) is kept, moved onto the result object:

```php
// 3.x
$mainImage = $readability->getImage();     // og:image / twitter:image / <link rel="img_src">
$allImages = $readability->getImages();    // main image + every <img> in the content

// 4.0
$mainImage = $article->image;              // ?string
$allImages = $article->images;             // string[] (lead image first, de-duplicated)
```

As in 3.x, the URLs are absolutized when `fixRelativeURLs` is enabled.

### Byline: `articleByline` → `keepInlineByline`

> **⚠️ Default behavior changed.** In 3.x, `articleByline` defaulted to `false`, which meant an inline byline (e.g. `<p class="byline">By Jane Doe</p>`) was **left in the content**. In 4.0 the default is to **remove** it from the content (matching Readability.js). If you relied on the 3.x default and want the byline to stay in `$article->content`, set `keepInlineByline: true`.

In 3.x, `articleByline` (default `false`) both enabled byline *detection* and, when on, removed the byline from the content. In 4.0 the byline is **always extracted** into `$article->byline` (matching Readability.js), and the new `keepInlineByline` option only controls whether an inline byline element stays in the content:

| | 3.x (`articleByline`) | 4.0 (`keepInlineByline`) |
| --- | --- | --- |
| Default value | `false` | `false` |
| Byline in `$article->content` by default | **kept** | **removed** |
| `$article->byline` populated | only when `articleByline: true` | always |
| To keep the byline in the content | (default) | `keepInlineByline: true` |

```php
// Restore the 3.x default (byline stays in the content):
$readability = new Readability(keepInlineByline: true);
```

### PSR-3 logging

Still supported. Pass a PSR-3 `LoggerInterface` as the `logger` option instead of calling `setLogger()`:

```php
// 3.x
$configuration->setLogger($myLogger);

// 4.0
$readability = new Readability(logger: $myLogger);
```

Debug messages are sent to the logger independently of the `debug` flag (which only controls `error_log()` output).

## Removed features

- `parser`, `substituteEntities`, `normalizeEntities`, `summonCthulhu` — all were libxml/HTML5-PHP workarounds and have no equivalent (the native Lexbor parser doesn't have the bugs they patched).
- `getDOMDocument(false)` — the whole-document variant is gone; `$article->contentElement` gives the extracted content only. If you need the full page, parse it yourself with `\Dom\HTMLDocument::createFromString()` and pass the document to `parse()`.
- `getPathInfo()`, `loadHTML()`, `setExcerpt()` — internal helpers, not carried over.

## Behavior changes to be aware of

- **`parse()` never returns `false`/`bool`.** It always returns an `Article`. When no article content is found (where Readability.js returns `null` and 3.x returned `false`), the `Article` still carries the extracted title and metadata, with `content`/`textContent`/`length`/`contentElement` set to `null` — check `Article::hasContent()`. `ParseException` is thrown only for empty input or documents over `maxElemsToParse`.
- **The content is wrapped** in `<div id="readability-page-1" class="page">…</div>`, exactly as Readability.js outputs. 3.x serialized the extracted elements with no wrapper around them. If you post-process the HTML, account for the wrapper — or reproduce the unwrapped 3.x output with:

  ```php
  $html = $article->contentElement->firstElementChild->innerHTML;
  ```

  (`firstElementChild` is the wrapper `div` itself — always the only child of `contentElement` — and `innerHTML` serializes everything inside it, so nothing is lost when the article consists of several top-level elements.)
- **The byline is always extracted, and inline bylines are removed from the content by default** (see `keepInlineByline` above). A 3.x install that never set `articleByline` kept the byline in the content.
- **Relative URL fixing follows the WHATWG URL Standard** (what browsers and Readability.js do), via PHP 8.5's native `Uri\WhatWg\Url` or rowbot/url on PHP 8.4. Edge-case outputs may differ slightly from 3.x's RFC 3986 resolution (e.g. `https://example.com` serializes as `https://example.com/`).
- **`javascript:` links are now neutralized regardless of `fixRelativeURLs`.** Previously this ran only when `fixRelativeURLs` was enabled; it now always runs (matching Readability.js), since stripping the scheme needs no base URL. Absolutizing relative URLs remains opt-in via `fixRelativeURLs`. This is defense-in-depth only — see the Security section of the README; you still need a real sanitizer for untrusted input.
- **Encoding:** input is parsed with the encoding declared in the document, defaulting to UTF-8 (as a browser would). The 3.x mb_* guessing hacks are gone — supply UTF-8 or make sure the document declares its charset.
- **Class attributes** are stripped by default as before (`keepClasses: false`), but the preserved-classes list now also honors `classesToPreserve`.

## New in 4.0

- `Readerable::isProbablyReaderable($html)` — Mozilla's quick pre-check for whether a page is worth parsing, ported for the first time.
- `parse()` also accepts a `\Dom\HTMLDocument` you've already created, not just an HTML string (note: a passed document is modified in place).
- `$article->textContent`, `->length`, `->lang`, `->publishedTime` outputs.
- Metadata sources at Readability.js 0.6.0 parity: JSON-LD (`@graph`, `@context` objects), parsely, `article:author`, `itemprop`.
