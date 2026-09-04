<?php
/**
 * RFC 8414 authorization server metadata, served at
 * {issuer}/.well-known/oauth-authorization-server (path-appended form).
 * See docs/oauth-well-known.md for the root-level rewrite rules.
 */
require __DIR__ . '/../../_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { oauthError('invalid_request', 'GET only', 405); }
oauthJson($oauth->metadata());
