<?php
/**
 * anthropic_config_sample.php — template for the Anthropic API key.
 *
 * The AI single-item flow (ai/name_item.php) calls the Claude API to suggest
 * filenames. It loads the key from OUTSIDE the web root, exactly like the
 * existing bulletproof_config.php:
 *
 *   ai/name_item.php  ->  require_once "/home/thundergoblin/anthropic_config.php";
 *
 * Setup on the b.rn host (NOT in this repo):
 *   1. cp ai/anthropic_config_sample.php /home/thundergoblin/anthropic_config.php
 *   2. paste your real key into $anthropic_api_key below
 *   3. leave it at /home/thundergoblin/ (above the web root, like bulletproof_config.php)
 *
 * The .gitignore pattern *_config.php keeps the real key file out of git.
 * (This *_config_sample.php does NOT match that pattern, so it stays tracked.)
 */

$anthropic_api_key = "sk-ant-...replace-me...";
