# Contributing

Contributions are **welcome** and will be fully **credited**.

When it comes to the core article-extraction functionality, please contribute to [Mozilla's Readability](https://github.com/mozilla/readability/) repository, as we're trying to mirror that here.

For anything else, we accept contributions via Pull Requests on [Github](https://github.com/fivefilters/readability.php/).

## Pull Requests

- **Document any change in behaviour** - Make sure the `README.md` and any other relevant documentation are kept up-to-date.

- **Add tests!** - Your patch won't be accepted if it doesn't have tests.

- **Create feature branches** - Don't ask us to pull from your master branch.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](http://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.

- **Don't forget to add yourself to AUTHORS.md** - If you want to be credited, make sure you add your information (whatever you want to include) in `AUTHORS.md`.


## Syncing with a new Readability.js release

The private methods in `src/Readability.php` mirror the prototype methods of Readability.js in name (minus the underscore prefix) and order, so syncing is a mechanical diff exercise:

1. Diff the new `Readability.js` against the previous synced version (git master at the time of the last sync — see `test/tools/known-divergences.md`).
2. For each changed `_method`, apply the same change to the matching method in `src/Readability.php`. Regex changes go to `src/RegExps.php` (constants are the UPPER_SNAKE forms of the JS `REGEXPS` keys). `Readability-readerable.js` changes go to `src/Readerable.php`.
3. Copy any added/changed directories from Mozilla's `test/test-pages/` into `test/test-pages/` verbatim (drop each page's `expected-images.json`-era leftovers if any; sources, `expected.html` and `expected-metadata.json` are used as-is).
4. Run `./vendor/bin/phpunit`, then the cross-check harness in `test/tools/` (bump the `@mozilla/readability` version in its `package.json`), and update `known-divergences.md`.

Things that intentionally differ from the JS (don't "fix" these): scoring state lives in `SplObjectStorage` maps instead of node expandos; `getAllNodesWithTag` materializes querySelectorAll snapshots; URL resolution goes through `src/Url.php`, which wraps a real WHATWG URL parser (PHP 8.5's native `Uri\WhatWg\Url`, or rowbot/url on PHP 8.4) and returns `null` where JS `new URL()` throws; JS `''`/`undefined` metadata maps to PHP `null`; `parse()` throws instead of returning null.

## Running Tests

``` bash
$ ./vendor/bin/phpunit      # requires PHP 8.4+
$ ./vendor/bin/psalm        # static analysis; CI runs this too
```

CI runs both on PHP 8.4 and 8.5.


**Happy coding**!
