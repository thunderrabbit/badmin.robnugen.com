# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Workflow guardrails

- Claude cannot commit on `master` — a git wrapper blocks it (logged to `~/.claude/git-wrapper.log`). Do the work on a feature branch; Rob integrates.

## Project Overview

This is a PHP-based image upload and management system called "badmin" (b.robnugen.com admin). It provides a web interface for uploading multiple images with descriptions, organizing them into categorized directories, and generating markdown embed codes for use in blogs and Signal messaging.

## Architecture

- **index.php**: upload form — 12 image slots, category dropdown, date prefixing
- **bullet.php**: upload processing — file handling, image resizing, embed-code generation. The worker functions all live here (`grep "^function" bullet.php` for the current list).
- **bulletproof_config_sample.php**: template for the password-hash config

Category → on-disk path mapping is defined in `determine_storage_directory()` in bullet.php — that function is the source of truth (the index.php dropdown is the user-facing label list). Don't trust a copied table here; read the function.

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
- `debug_level` request param controls upload output verbosity (0 = quiet).
