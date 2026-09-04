<?php
/**
 * OpenID-Connect-style discovery alias ({issuer}/.well-known/openid-configuration).
 * The MCP SDKs try this form when the RFC 8414 root-level path is unavailable.
 */
require __DIR__ . '/../../_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { oauthError('invalid_request', 'GET only', 405); }
oauthJson($oauth->metadata());
