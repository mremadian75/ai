# Future Island Deep Trend Finder Add-on v0.3.20

## Patch name
Apidojo Output Repair Refresh

## Production problem
TikTok live collection could show `Discovery returned zero posts` in WordPress even when the Apify run for `apidojo/tiktok-scraper` succeeded and returned dataset rows.

The previous v0.3.19 patch fixed premature terminal handling of Apify `READY`, but another failure path remained:

1. old false-zero jobs were not refreshed from report/evidence endpoints after the frontend stopped polling;
2. some Apidojo TikTok dataset rows can appear as flat/display-label shaped output, such as `ID`, `Title`, `Post URL`, `Channel Username`, `Uploaded At Formatted`, etc.; those rows were not handled defensively enough by the provider flattener and normalizer.

## Fixes

- Added safe provider refresh/repair before report loading.
- Added safe provider refresh/repair before evidence/items loading.
- Added canonicalization for Apidojo TikTok display-label rows.
- Extended TikTok post detection to recognize flat/display-label Apidojo dataset rows.
- Extended normalizer aliases for Apidojo output fields:
  - `ID`
  - `Title`
  - `Post URL`
  - `Channel Username`
  - `Channel Name`
  - `Channel Avatar`
  - `Uploaded At`
  - `Uploaded At Formatted`
  - `Views`, `Likes`, `Comments`, `Shares`, `Bookmarks`
- Kept v0.3.19 Apify status correction:
  - only `SUCCEEDED` is treated as terminal success;
  - `READY` and `STARTING` stay queued;
  - `RUNNING` and `TIMING-OUT` stay running;
  - dataset fetch happens only after `SUCCEEDED`.
- Adjusted core Apify client bridge diagnostics to remain strict in production while allowing mocked unit-test environments where `VES_Apify_Client` exists without `VES_Config`.

## Files changed

- `future-island-deep-trend-finder-addon.php`
- `includes/class-fidtf-rest-controller.php`
- `includes/class-fidtf-settings.php`
- `includes/providers/class-fidtf-provider-tiktok.php`
- `includes/class-fidtf-normalizer.php`
- `tests/test-fidtf-v0320-apidojo-output-repair-refresh.php`
- `tests/test-fidtf-v039-scraptik-social-request-contract.php`
- `tests/test-fidtf-v0313-apidojo-tiktok-input-schema-hotfix.php`

## Validation

- PHP lint passed for all PHP files.
- Full local PHP test suite passed.
- New focused regression test passed: `FIDTF v0.3.20 Apidojo flat output and repair refresh checks passed: 64 / 64`.

## Manual QA checklist

1. Install and activate the add-on with the matching Future Island core plugin.
2. Start a TikTok run using a query that is known to return Apify results.
3. Confirm the run does not close while Apify status is `READY`, `STARTING`, `RUNNING`, or `TIMING-OUT`.
4. Confirm dataset fetch happens after Apify status becomes `SUCCEEDED`.
5. Open the run report.
6. Confirm the report no longer says zero posts if the Apify dataset contains rows.
7. Open evidence/items.
8. Confirm TikTok rows show post URL, title/text, author/channel, and metrics when Apidojo returns flat/display-label rows.

## Known limitation

This patch was validated at code and fixture level. It was not live-tested against the user's exact Apify run from the assistant environment.
