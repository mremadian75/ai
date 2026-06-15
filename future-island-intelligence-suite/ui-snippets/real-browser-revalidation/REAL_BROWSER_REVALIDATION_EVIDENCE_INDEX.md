# Real Browser Revalidation — Evidence Index

This folder is the reproducible real-browser evidence for the v0.9.33 sprint.

## How it was produced

- Engine: headless **Chromium 1194** driven by **Playwright 1.56** (a real browser
  layout engine, not a static parser).
- Method: each UI snippet is injected into a page that links the **actual shipped
  plugin CSS** (`assets/css/*.css`), rendered at **1440 / 768 / 375** widths, then the
  DOM is measured and screenshotted.
- Result rows are conditionally unhidden exactly as the app's JS does (e.g.
  `.ves-results` gets `.show`), so hidden panels are measured, not skipped.

Run it yourself:

```bash
cd ui-snippets/real-browser-revalidation
ln -sfn "$(npm root -g)" node_modules        # needs a playwright install on NODE path
PLAYWRIGHT_BROWSERS_PATH=/path/to/pw-browsers node render-harness.mjs
```

## What it measures (the browser-observed bug classes)

- **character-by-character columns** — a text element rendered absurdly narrow while
  holding multi-character content.
- **mid-word break on prose** — an element's content box is narrower than its longest
  non-token word (URLs/long tokens are excluded; they may wrap safely).
- **narrow cards** — result/metric/route cards collapsing below a usable width on
  desktop/tablet.
- grid column counts for `.ves-items`, `.fiis-route-track`, `.ves-kpis`.

## Result

- 57 snippet×viewport combinations measured.
- **2 acceptable flags remain**, both at 375px on non-prose technical tokens
  (`2026-06-15` date cell; `google_search` mono key), off by 2–5px — within the
  "URLs/tokens may wrap safely" allowance. No prose character-by-character or mid-word
  breaks remain.
- Machine-readable detail: `measurements.json`.

## Files

- `render-harness.mjs` — the self-locating render+measure harness.
- `measurements.json` — per-combo measurements and any flags.
- `shots/` — 38 screenshots (`<group>__<screen>__<desktop|mobile>.png`).

## Scope / honesty

This validates the shipped **CSS + snippet markup in a real browser engine**. It does
**not** stand up a live WordPress (no WP runtime, DB, admin chrome, or WordPress core
admin CSS). Admin screens (`wrap fi-provider-admin`, `wrap fi-beta-qa`) therefore render
here using base + plugin styles only; in WordPress they additionally inherit core
`widefat`/admin styling. One live-WP staging pass is still required before pilot.
