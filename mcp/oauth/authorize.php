<?php
/**
 * OAuth 2.1 authorization endpoint + consent page.
 *
 * GET  ?response_type=code&client_id=…&redirect_uri=…&code_challenge=…&code_challenge_method=S256&state=…&scope=cms
 *      → needs a signed-in CMS user (redirects to the admin login otherwise),
 *        then shows "Allow <client> to manage <site> as <user>?"
 * POST approve / deny (CSRF-protected) → 302 back to the client with a code.
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/Permissions.php';

$auth = new Auth(__DIR__ . '/../../config/users.json');

$p = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$clientId    = (string)($p['client_id'] ?? '');
$redirectUri = (string)($p['redirect_uri'] ?? '');
$state       = (string)($p['state'] ?? '');
$challenge   = (string)($p['code_challenge'] ?? '');
$method      = (string)($p['code_challenge_method'] ?? 'S256');
$scope       = trim((string)($p['scope'] ?? OAuthServer::SCOPE));
$resource    = (string)($p['resource'] ?? '');
$responseType = (string)($p['response_type'] ?? 'code');

/** Render an error page (used when we cannot safely redirect back). */
function authorizeFail(string $title, string $detail): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 24px;color:#111}h1{font-size:22px}p{color:#555;line-height:1.55}</style></head><body>'
        . '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($detail) . '</p></body></html>';
    exit;
}

function redirectBack(string $uri, array $params): void
{
    $sep = str_contains($uri, '?') ? '&' : '?';
    header('Location: ' . $uri . $sep . http_build_query($params), true, 302);
    exit;
}

// 1. Validate the client and redirect URI first: errors here must NOT redirect.
$client = $oauth->getClient($clientId);
if (!$client) {
    authorizeFail('Unknown application', 'The client_id is not registered with this CMS. The app must register first (dynamic client registration) or use a valid Client ID Metadata Document URL.');
}
if ($redirectUri === '' || !OAuthServer::redirectUriAllowed($client, $redirectUri)) {
    authorizeFail('Redirect not allowed', 'The redirect_uri does not match what this application registered.');
}

// 2. Everything else is reported to the client via redirect (RFC 6749 §4.1.2.1)…
// except for brand-new dynamic registrations (< 10 min): registration is open,
// so a redirect without any user interaction would be an open redirector.
if (($client['kind'] ?? '') === 'dcr' && (int)($client['client_id_issued_at'] ?? 0) > time() - 600) {
    $preErr = null;
    if ($responseType !== 'code') $preErr = 'unsupported_response_type';
    elseif ($challenge === '' || $method !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43}$/', $challenge)) $preErr = 'invalid_request (PKCE S256 required)';
    elseif (array_diff(array_values(array_filter(preg_split('/\s+/', $scope) ?: [])), [OAuthServer::SCOPE, 'offline_access']) !== []) $preErr = 'invalid_scope';
    elseif ($resource !== '' && $resource !== $oauth->resource()) $preErr = 'invalid_target';
    if ($preErr !== null) {
        authorizeFail('Invalid authorization request', 'The application sent an invalid request (' . $preErr . '). Because it registered only moments ago, this CMS will not redirect back to it automatically. Retry from the app.');
    }
}
if ($responseType !== 'code') {
    redirectBack($redirectUri, ['error' => 'unsupported_response_type', 'state' => $state]);
}
if ($challenge === '' || $method !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43}$/', $challenge)) {
    redirectBack($redirectUri, ['error' => 'invalid_request', 'error_description' => 'PKCE with code_challenge_method=S256 is required', 'state' => $state]);
}
$scopes = array_values(array_filter(preg_split('/\s+/', $scope) ?: []));
foreach ($scopes as $s) {
    if ($s !== OAuthServer::SCOPE && $s !== 'offline_access') {
        redirectBack($redirectUri, ['error' => 'invalid_scope', 'state' => $state]);
    }
}
if ($resource !== '' && $resource !== $oauth->resource()) {
    redirectBack($redirectUri, ['error' => 'invalid_target', 'error_description' => 'resource must be ' . $oauth->resource(), 'state' => $state]);
}

// 3. Require a signed-in CMS user.
if (!$auth->isLoggedIn()) {
    $self = '/cms/mcp/oauth/authorize.php?' . http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri,
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => $state,
        'scope' => $scope, 'resource' => $resource,
    ]);
    header('Location: /cms/admin/login.php?redirect=' . rawurlencode($self), true, 302);
    exit;
}
$user = $auth->getCurrentUser();
$username = (string)($user['username'] ?? '');
$role = (string)($user['role'] ?? 'viewer');
if ($role === 'viewer') {
    redirectBack($redirectUri, ['error' => 'access_denied', 'error_description' => 'Your CMS role (viewer) cannot edit content', 'state' => $state]);
}

// 4. Consent decision.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    if (($_POST['decision'] ?? '') !== 'approve') {
        redirectBack($redirectUri, ['error' => 'access_denied', 'state' => $state]);
    }
    try {
        $code = $oauth->issueCode([
            'client_id' => $clientId,
            'client_name' => $client['client_name'] ?? '',
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'user' => $username,
            'role' => $role,
            'scope' => OAuthServer::SCOPE,
            'resource' => $oauth->resource(),
        ]);
    } catch (Throwable $e) {
        redirectBack($redirectUri, ['error' => 'server_error', 'error_description' => 'Could not store the authorization code', 'state' => $state]);
    }
    redirectBack($redirectUri, ['code' => $code, 'state' => $state]);
}

// 5. Consent page.
$perms = new Permissions(__DIR__ . '/../../config/roles.json');
$capLabels = [];
foreach ($perms->roleCapabilities($role) as $cap) {
    foreach (Permissions::CATALOG as $group) {
        if (isset($group[$cap])) { $capLabels[] = $group[$cap]; }
    }
}
$siteName = (string)($config['site_name'] ?? 'CMS');
$clientName = (string)($client['client_name'] ?? 'MCP client');
$clientHost = parse_url($redirectUri, PHP_URL_HOST) ?: '';
$clientUri = $client['client_uri'] ?? null;
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Allow <?php echo htmlspecialchars($clientName); ?> · <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'] },
            colors: { accent: { 50:'#fff5f3',100:'#ffe8e4',500:'#f96a4d',600:'#e64d2e',700:'#c13d21' } } } } };
    </script>
</head>
<body class="bg-gray-50 font-sans antialiased text-gray-900 min-h-screen flex items-center justify-center px-4">
<main class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8">
    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-2">Connect an app</p>
    <h1 class="text-2xl font-bold mb-1"><?php echo htmlspecialchars($clientName); ?> wants to manage <?php echo htmlspecialchars($siteName); ?></h1>
    <?php if ($clientUri || $clientHost): ?>
    <p class="text-sm text-gray-500 mb-6"><?php echo htmlspecialchars($clientUri ?: $clientHost); ?></p>
    <?php else: ?>
    <p class="text-sm text-gray-500 mb-6">via the MCP endpoint</p>
    <?php endif; ?>

    <div class="rounded-xl bg-gray-50 border border-gray-200 p-4 mb-6">
        <p class="text-sm text-gray-700 mb-2">It will act as <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($perms->roleLabel($role)); ?>) and can:</p>
        <ul class="text-sm text-gray-700 list-disc pl-5 space-y-1">
            <?php foreach (array_slice($capLabels, 0, 14) as $label): ?>
            <li><?php echo htmlspecialchars($label); ?></li>
            <?php endforeach; ?>
            <?php if (count($capLabels) > 14): ?><li>… and <?php echo count($capLabels) - 14; ?> more</li><?php endif; ?>
        </ul>
        <p class="text-xs text-gray-500 mt-3">You can disconnect it at any time under Settings → MCP Config → Connected apps.</p>
    </div>

    <form method="post" class="flex gap-3">
        <?php echo CSRF::inputField(); ?>
        <?php foreach (['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => $scope, 'resource' => $resource, 'response_type' => 'code'] as $k => $v): ?>
        <input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($v); ?>">
        <?php endforeach; ?>
        <button type="submit" name="decision" value="deny" class="flex-1 px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-medium hover:bg-gray-50">Deny</button>
        <button type="submit" name="decision" value="approve" class="flex-1 px-4 py-3 rounded-xl bg-accent-600 hover:bg-accent-700 text-white font-semibold">Allow</button>
    </form>
    <p class="text-xs text-gray-400 mt-6">Signed in as <?php echo htmlspecialchars($username); ?>. <a class="underline" href="/cms/admin/logout.php">Not you?</a></p>
</main>
</body>
</html>
