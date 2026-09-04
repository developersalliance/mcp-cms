<?php
/**
 * RFC 9728 protected resource metadata for the MCP endpoint. Pointed at by
 * the WWW-Authenticate header on every 401 from mcp/index.php, so clients
 * find it without a root-level /.well-known/ route.
 */
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { oauthError('invalid_request', 'GET only', 405); }
oauthJson($oauth->protectedResourceMetadata());
