<?php
/**
 * secure_config_sample.php — template for ai_secure/secure_config.php.
 *
 * Copy to secure_config.php (git-ignored by the *_config.php rule). There is NO
 * secret in here; it is a config only so the secure-bin root can be overridden
 * per environment and so the path lives in exactly one place.
 *
 * secure_bin lives OUTSIDE the public web root (/home/thundergoblin/b.robnugen.com).
 * Nothing under SECURE_BIN_ROOT is ever web-served — that is the whole point.
 *
 * One-time server setup (as the thundergoblin user):
 *   mkdir -p /home/thundergoblin/secure_bin/{.staging,receipts,bills_paid,taxes_filed}
 *   chmod 700 /home/thundergoblin/secure_bin /home/thundergoblin/secure_bin/*
 */

const SECURE_BIN_ROOT     = "/home/thundergoblin/secure_bin";
const SECURE_BIN_STAGING  = SECURE_BIN_ROOT . "/.staging";
const SECURE_BIN_MANIFEST = SECURE_BIN_ROOT . "/secure_manifest.jsonl";

/** The only destinations ai_secure will ever write to. Keys are the routing buckets. */
const SECURE_BUCKETS = ['receipts', 'bills_paid', 'taxes_filed'];

/**
 * Whitelist a bucket key and return its absolute dir, or null if not allowed.
 * Never concatenate user input into a path without passing it through here.
 */
function secure_bucket_dir(string $bucket): ?string
{
    return in_array($bucket, SECURE_BUCKETS, true)
        ? SECURE_BIN_ROOT . "/" . $bucket
        : null;
}
