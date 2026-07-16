# Known divergences from Readability.js

This file records the accepted differences between this port and Readability.js,
found with the cross-check tools in this directory (`cross-check.mjs` runs the
npm release of Readability.js over every test page; `cross-check.php` diffs the
PHP port's output against it).

## The port tracks Mozilla's git master, not the npm release

The port was transcribed from Readability.js git master (post-0.6.0), which is
also what Mozilla's committed `expected.html`/`expected-metadata.json` fixtures
are generated from. The npm `@mozilla/readability` 0.6.0 release lags master,
so `cross-check.php` reports differences on pages that exercise unreleased
upstream changes. As of the 0.6.0-era master this covers:

- `mathjax` — master added `mathjax` to `okMaybeItsACandidate`
- `mercurial`, `title-en-dash` — master added `–`/`—` to the title separators
  in `_getArticleTitle`
- `lemonde-2`, `lwn-1`, `uses-getfirstelementchild-function`, `wapo-1` — master
  reworked the phrasing-content collection in `_grabArticle` (a document
  fragment instead of per-node paragraph splitting), changing `<br>` handling

In every one of these cases the PHP output agrees with Mozilla's committed
test fixtures, which are the authority this library tests against. When a new
Readability.js version is released to npm, bump it in `package.json` here and
these entries should disappear.

## Semantic mapping differences (cosmetic, normalized away by cross-check.php)

- Where JS returns `''` or `undefined` for absent metadata (excerpt, byline,
  siteName, publishedTime), the PHP port returns `null`.
- JS `parse()` returns `null` when no article is found; the PHP port throws
  `ParseException`.
