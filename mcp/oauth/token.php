<?php
/**
 * OAuth 2.1 token endpoint.
 *   grant_type=authorization_code  code + code_verifier (PKCE S256) + redirect_uri + client_id
 *   grant_type=refresh_token       refresh_token + client_id  (rotates the refresh token)
 * Accepts application/x-www-form-urlencoded (RFC 6749) and JSON bodies.
 */
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    oauthError('invalid_request', 'POST only', 405);
}

$req = oauthRequestBody();
$grantType = (string)($req['grant_type'] ?? '');
$clientId = (string)($req['client_id'] ?? '');

// Client authentication: confidential clients send client_secret (post).
$client = $oauth->getClient($clientId);
if (!$client) {
    header('WWW-Authenticate: Basic realm="cms-mcp-oauth"');
    oauthError('invalid_client', 'Unknown client_id', 401);
}
if (($client['token_endpoint_auth_method'] ?? 'none') === 'client_secret_post') {
    $secret = (string)($req['client_secret'] ?? '');
    if ($secret === '' || !hash_equals((string)($client['client_secret_hash'] ?? ''), hash('sha256', $secret))) {
        oauthError('invalid_client', 'Bad client_secret', 401);
    }
}

if ($grantType === 'authorization_code') {
    $code = (string)($req['code'] ?? '');
    $verifier = (string)($req['code_verifier'] ?? '');
    $redirectUri = (string)($req['redirect_uri'] ?? '');
    if ($code === '' || $verifier === '') {
        oauthError('invalid_request', 'code and code_verifier are required');
    }
    $grant = $oauth->consumeCode($code);
    if (!$grant) {
        oauthError('invalid_grant', 'Authorization code is invalid, expired or already used');
    }
    if (($grant['client_id'] ?? '') !== $clientId) {
        oauthError('invalid_grant', 'Code was issued to a different client');
    }
    if ($redirectUri !== '' && $redirectUri !== ($grant['redirect_uri'] ?? '')) {
        oauthError('invalid_grant', 'redirect_uri does not match the authorization request');
    }
    if (!OAuthServer::pkceMatches($verifier, (string)($grant['code_challenge'] ?? ''))) {
        oauthError('invalid_grant', 'PKCE verification failed');
    }
    $role = $oauth->currentRole((string)$grant['user']);
    if ($role === null) {
        oauthError('invalid_grant', 'The approving user no longer exists');
    }
    try {
        $tokens = $oauth->issueTokens([
            'user' => $grant['user'],
            'role' => $role,
            'client_id' => $clientId,
            'client_name' => $client['client_name'] ?? '',
            'scope' => $grant['scope'] ?? OAuthServer::SCOPE,
        ]);
    } catch (Throwable $e) {
        oauthError('server_error', 'Could not store the token', 500);
    }
    oauthJson($tokens);
}

if ($grantType === 'refresh_token') {
    $refresh = (string)($req['refresh_token'] ?? '');
    if ($refresh === '') {
        oauthError('invalid_request', 'refresh_token is required');
    }
    $tokens = $oauth->refresh($refresh, $clientId);
    if (!$tokens) {
        oauthError('invalid_grant', 'Refresh token is invalid, expired, revoked or belongs to another client');
    }
    oauthJson($tokens);
}

oauthError('unsupported_grant_type', 'Use authorization_code or refresh_token');
