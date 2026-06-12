# Release Evidence Pack — Specification v2 (schema 2.0)

Phase 9E. Supersedes schema 1.0 (`RELEASE-EVIDENCE-PACK-SPEC.md`). A live
staging validation may only be recorded as **passed** through a schema-2.0,
hash-verified, **file-backed** evidence pack. A pack JSON alone — however
well-formed — is classified `json_only_unverified` and is never trusted.

## Files

Produced by `scripts/future-island-live-validation-v3.sh` inside the evidence
folder:

```
fi-evidence-<UTC timestamp>/
  evidence-pack-v2.json          ← the pack
  manifest-commands.txt          ← per-command exit codes + file hashes
  manifest-files.txt             ← sha256 of every artifact (archive manifest)
  build-sha256.txt  db-backup-sha256.txt
  commands/<slug>.out|.err|.combined.txt   (stdout/stderr/combined per command)
  screenshots/01-…12-*.png       ← the 12 required screenshots
  logs/browser-console.log  logs/network-errors.log  logs/php-error-log-tail.log
```

## Required fields (all mandatory)

`schema_version`(=2.0) · `build_sha256` · `plugin_version` · `siteurl` · `home`
· `wp_version` · `php_version` · `db_version` · `db_backup_sha256` ·
`command_outputs` · `screenshots_manifest` · `screenshot_files` ·
`php_error_log_file`+`_sha256` · `browser_console_log_file`+`_sha256` ·
`network_errors_file`+`_sha256` · `evidence_archive_sha256` · `generated_at` ·
`operator{name,user_id}` · `validation_status` · `limitations` ·
`evidence_pack_hash`

Per command (all 18 required commands, exit code 0):

```json
"wp ves verify-schema": {
  "exit_code": 0,
  "stdout_file": "commands/verify-schema.out",   "stdout_sha256": "…",
  "stderr_file": "commands/verify-schema.err",   "stderr_sha256": "…",
  "combined_file": "commands/verify-schema.combined.txt", "combined_sha256": "…"
}
```

Required commands: `php -v`, `wp --info`, `wp core version`,
`wp db query "SELECT VERSION();"`, `wp option get siteurl`, `wp option get home`,
`wp plugin list`, `wp theme list`, `wp ves verify-schema`,
`wp ves validate-staging --format=json`, `wp ves readiness-check --format=json`,
`wp ves rc-readiness-check --format=json`, `wp ves memory-summary --format=json`,
`wp ves memory-context-preview …`, `wp ves generation-context-preview …`,
`wp ves generation-prompt-preview …`, `wp ves operator-queue …`,
`wp ves memory-expire --dry-run`.

Browser artifacts: all **12** screenshots from
`LIVE-BROWSER-VALIDATION-CHECKLIST.md` must be in `screenshots_manifest` with a
SHA-256 each in `screenshot_files`; console/network/php-error logs must exist
and hash (clean runs use `NO_CONSOLE_ERRORS_OBSERVED` /
`NO_NETWORK_ERRORS_OBSERVED` file content — file presence is still mandatory).

`validation_status` is **computed, never asserted**: `passed` requires all 18
commands at exit 0 with all three captured streams, all 12 screenshots, all
three logs, `db_backup_sha256`, and an operator name.

## Hashes

- `evidence_pack_hash` — deterministic SHA-256 of the canonicalized pack
  (recursive key sort, compact encoding, hash field excluded). Any edit
  invalidates the pack.
- `evidence_archive_sha256` — **archive manifest hash**: the SHA-256 of
  `manifest-files.txt` (which lists every artifact's hash). Non-circular by
  design: the manifest excludes itself and the pack file, so the pack can carry
  it before archiving. Recording verifies it against the extracted archive.

## Recording (the only trusted write)

```
wp ves rc-record-live-validation --evidence-pack=…/evidence-pack-v2.json \
  ( --evidence-root=…/fi-evidence-<ts>  |  --evidence-archive=…/fi-evidence-<ts>.tar.gz )
```

Verification order: pack hash → schema → `validation_status=passed` →
**every referenced file exists and its SHA-256 matches** (root, or archive
extracted via PharData + manifest hash check) → no readiness blockers. Failure
classifications: `json_only_unverified` (no root/archive given),
`missing_required_artifacts`, `file_hash_mismatch` — none of them record a pass.

Stored state on success carries `files_verified: true`; the classifier reports
`passed` only for such states. `wp ves rc-readiness-check --strict` trusts only
`passed`. Nothing in this pipeline can produce a production-ready claim.
