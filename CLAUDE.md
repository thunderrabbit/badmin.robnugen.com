# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Workflow guardrails

- Claude cannot commit on `master` — a git wrapper blocks it (logged to `~/.claude/git-wrapper.log`). Do the work on a feature branch; Rob integrates.

## Project Overview

This is "badmin" (b.robnugen.com admin), the PHP image tools that have not yet moved to badmint. The bulk upload form (mg #706) and the MT3 Worker/Part photo uploads (mg #709, `bullet.php` and `workers/`) now live on badmint; `index.php` and `workers/` only point there.

## Architecture

- **index.php**: the menu and a link to badmint's upload page
- **image_resize_lib.php**: the shared resize/thumbnail/embed helpers the remaining pages (`ai/`, `items/`, `peeps/`, …) call
- **autocrop_lib.php**: blue-felt subject detection, kept for `tools/autocrop_selftest.php` (badmint's `Mt3\Autocrop` is a port of it)
- **bulletproof_config_sample.php**: template for the password-hash config

## Environment / Deployment

- **Deploying is Rob's job and it goes through git, not scp.** `b.rn:/home/thundergoblin/badmin.robnugen.com/`
  is a git checkout; work goes live when Rob advances master and the server fetches/checks out.
  Claude never deploys. Finish on a feature branch and say so — don't hand Rob a "run this to deploy" step.
- **`./scp_modified_files_to_badmin.sh` is NOT a deploy command.** It is a foreground `inotifywait`
  watcher Rob leaves running in his own terminal while iterating: it mirrors each file to the server as
  it is written, and *only* while it is running. Running it after an edit copies **nothing** — there are
  no pending changes for it to see, it just sits there watching. Never describe it as a way to push work.
- Corollary: while that watcher IS running, every file Claude writes is already on the production server
  the moment it is saved — before review, before commit. Untested edits are live edits.
- Production paths are hardcoded under `/home/thundergoblin/`; uploads land in `b.robnugen.com/<category>/<year>/`.
- Depends on the Bulletproof PHP library, external to this repo at `/home/thundergoblin/bulletproof/`.

## Configuration

- Set the password hash in `bulletproof_config.php` (copy `bulletproof_config_sample.php`); auth uses `password_verify()`.
