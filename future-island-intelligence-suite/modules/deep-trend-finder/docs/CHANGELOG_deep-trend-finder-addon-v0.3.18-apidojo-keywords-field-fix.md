# CHANGELOG — Future Island Deep Trend Finder Add-on v0.3.18

**Slug:** `0.3.18-apidojo-keywords-field-fix`

## Focus
Definitive root-cause fix for "TikTok actor rejected the run input (HTTP 400)".
The discovery actor in use is `apidojo/tiktok-scraper`, and the add-on was sending
the wrong field name (`searchKeywords`) plus invalid `dateRange` / `sortType`
enum values. The actor rejected every run.

---

## Critical Bug Fixes

### [ROOT CAUSE] `build_apidojo_input()` sent `searchKeywords` — actor expects `keywords`
**File:** `includes/providers/class-fidtf-provider-tiktok.php`

The `apidojo/tiktok-scraper` actor's input contract (confirmed against the main
plugin's `platform-input.php` `tiktok_apidojo_discovery` contract) accepts ONLY:
```
customMapFunction, dateRange, includeSearchKeywords, location,
maxItems, sortType, startUrls, keywords
```

The add-on was sending `searchKeywords` — **not a recognized field**. With no
valid `keywords` field, the actor failed input validation and returned HTTP 400
on every run. The "Sanitized discovery actor input preview" in admin settings
showed this clearly:
```json
{"searchKeywords":["beverage trends","beverage"], ...}
```

**Fix:** `build_apidojo_input()` now emits `keywords` (correct field name),
capped at 5 entries (same as the main plugin's `array_slice($keywords, 0, 5)`),
and only ever sends fields from the actor's allowed list.

### Invalid `dateRange` enum values
**File:** `includes/providers/class-fidtf-provider-tiktok.php`

`apidojo_date_range()` returned `LAST_24_HOURS`, `LAST_WEEK`, `LAST_MONTH` — none
of which are valid for the apidojo actor. Valid values are:
`DEFAULT, YESTERDAY, THIS_WEEK, THIS_MONTH, LAST_THREE_MONTHS, LAST_SIX_MONTHS`.

**Fix:** mapping corrected to valid enums (mirrors `ves_apidojo_tiktok_date_range`):
- `last_24_hours` → `YESTERDAY`
- `last_7_days` → `THIS_WEEK`
- `last_14_days` → `LAST_THREE_MONTHS`
- `last_30_days` / `this_month` → `THIS_MONTH`
- `last_60_days` / `last_90_days` → `LAST_THREE_MONTHS`
- 180/365 days / default → `LAST_SIX_MONTHS`

### Invalid `sortType` enum value
**File:** `includes/providers/class-fidtf-provider-tiktok.php`

`apidojo_sort_type()` could return `'DATE'` — not a valid value. Valid values are
`RELEVANCE, DATE_POSTED, MOST_LIKED`.

**Fix:** mapping corrected. `"relevance"` is now checked first, because the
default strategy `relevance_then_engagement` contains both "relevance" and
"engagement" — relevance is the primary sort and must win.

### `validate_discovery_actor_input()` now checks the correct field
**File:** `includes/providers/class-fidtf-provider-tiktok.php`

Updated to look for `keywords` / `startUrls` (the actor's `required_any_fields`)
and gives a clearer error message.

### Enrichment actor guard
**File:** `includes/class-fidtf-settings.php`

The screenshots showed the enrichment actor was set to `apidojo/tiktok-scraper`.
The enrichment input builder produces **scraptik-format input only**
(`post_awemeId`, `listComments_awemeId`, …) which a non-scraptik actor cannot
consume — every enrichment call would have failed with HTTP 400.

**Fix:** `tiktok_enrichment_actor_id()` now falls back to `scraptik/tiktok-api`
whenever a non-scraptik actor is configured, keeping enrichment functional.
(Symmetric with the existing guard on `tiktok_discovery_actor_id()`.)

---

## Feature / Migration

### One-time `tiktok_min_views` migration: 20,000 → 10,000
**Files:** `includes/class-fidtf-settings.php`, `includes/class-fidtf-plugin.php`

v0.3.16 lowered the *default* to 10,000, but existing installs already had the
old default (20,000) persisted in the database, so the change had no effect for
them (admin settings still showed 20,000).

**Fix:** added `FIDTF_Settings::maybe_migrate()`, run on `boot()` and on
`activate()`. It runs **once** (gated by the `fidtf_settings_migrated_min_views_10k`
option flag) and updates the stored value from 20,000 → 10,000 only when it is
still exactly the old default. A later deliberate change to any value is never
overwritten.

To verify after upgrade: **TikTok item limits → Minimum views** should read
`10000`. You can still set any value manually.

---

## Admin Text
- Discovery-actor help text corrected: apidojo receives `keywords/startUrls`
  (was incorrectly documented as `searchKeywords/startUrls`).
- Minimum-views field fallback display corrected to 10,000.

---

## Unchanged
- All v0.3.16 / v0.3.17 fixes remain (clockworks input cleanup, core-client
  token check, `waiting_for_provider → failed` promotion, JS messaging,
  `map_core_error` handling of `apify_invalid_input` / `apify_memory_limit`).
- The min_views filter still runs as a local post-scrape filter (same as the
  main plugin) — not passed to the actor.
- No database schema change. No shortcode change.

---

## Files Changed
```
future-island-deep-trend-finder-addon.php            (version bump)
includes/providers/class-fidtf-provider-tiktok.php   (apidojo input/enum fixes, validation)
includes/class-fidtf-settings.php                    (min_views migration, enrichment actor guard)
includes/class-fidtf-plugin.php                      (maybe_migrate hook)
includes/class-fidtf-admin.php                       (help text corrections)
```
