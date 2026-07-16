// Dev tool: run Mozilla Readability.js (the reference implementation) over
// every test page, dumping each result to js-output/<slug>.json. Compare with
// the PHP port via: php test/tools/cross-check.php
//
// Usage: cd test/tools && npm install && node cross-check.mjs [slug ...]

import { JSDOM, VirtualConsole } from "jsdom";
import { Readability } from "@mozilla/readability";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "test-pages");
const outDir = path.join(path.dirname(fileURLToPath(import.meta.url)), "js-output");
fs.mkdirSync(outDir, { recursive: true });

const only = process.argv.slice(2);
const dirs = fs.readdirSync(root).filter(d => !d.startsWith(".") && (!only.length || only.includes(d)));

function removeCommentNodesRecursively(node) {
  for (let i = node.childNodes.length - 1; i >= 0; i--) {
    const child = node.childNodes[i];
    if (child.nodeType === 8) {
      node.removeChild(child);
    } else if (child.nodeType === 1) {
      removeCommentNodesRecursively(child);
    }
  }
}

for (const dir of dirs) {
  const source = fs.readFileSync(path.join(root, dir, "source.html"), "utf-8").trim();
  let result = null;
  let error = null;
  try {
    const virtualConsole = new VirtualConsole(); // swallow CSS parse noise
    const doc = new JSDOM(source, { url: "http://fakehost/test/page.html", virtualConsole }).window.document;
    removeCommentNodesRecursively(doc);
    result = new Readability(doc, { classesToPreserve: ["caption"] }).parse();
  } catch (err) {
    error = String(err);
  }
  const out = result
    ? {
        title: result.title,
        byline: result.byline,
        dir: result.dir,
        lang: result.lang,
        excerpt: result.excerpt,
        siteName: result.siteName,
        publishedTime: result.publishedTime,
        length: result.length,
        content: result.content,
      }
    : { error: error ?? "no article" };
  fs.writeFileSync(path.join(outDir, `${dir}.json`), JSON.stringify(out, null, 2) + "\n");
  console.log(`${dir}: ${result ? "ok" : "FAILED: " + (error ?? "no article")}`);
}
