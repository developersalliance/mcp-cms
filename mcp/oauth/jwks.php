<?php
/**
 * JWKS endpoint. Access tokens are opaque (looked up server-side), so there
 * are no signing keys to publish; the document exists because OIDC-style
 * discovery requires jwks_uri to resolve.
 */
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { oauthError('invalid_request', 'GET only', 405); }
oauthJson(['keys' => []]);
