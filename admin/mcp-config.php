<?php
/**
 * MCP Configuration Generator
 *
 * Generates a config.json file for ChatGPT Desktop and other MCP clients
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_capability('settings.manage');
require_once __DIR__ . '/../core/CSRF.php';

// Handle MCP tool permissions update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    CSRF::verifyOrDie();

    try {
        // Load known tool names so we can validate the submitted list
        require_once __DIR__ . '/../mcp/tools-definition.php';
        $knownTools = array_keys(getMCPTools());

        // Get allowed tools from form (empty array if none selected) and
        // restrict to known tool names only
        $submittedTools = $_POST['mcp_allowed_tools'] ?? [];
        if (!is_array($submittedTools)) {
            $submittedTools = [];
        }
        $mcpAllowedTools = array_values(array_filter($submittedTools, function($tool) use ($knownTools) {
            return is_string($tool) && in_array($tool, $knownTools, true);
        }));

        // Load current config and mutate the array safely
        $configPath = __DIR__ . '/../config/config.php';
        $currentConfig = require $configPath;
        $newConfig = $currentConfig;
        $newConfig['mcp_allowed_tools'] = $mcpAllowedTools;
        // Deny-list too: tools the admin unticked. Tools added by a later
        // engine update appear in neither list and stay enabled (mcp/index.php).
        $newConfig['mcp_disabled_tools'] = array_values(array_diff($knownTools, $mcpAllowedTools));

        // Emit config via var_export which safely escapes all string values
        $configContent = "<?php\n/**\n * Core configuration for flat MCP CMS.\n */\nreturn " . var_export($newConfig, true) . ";\n";

        if (file_put_contents($configPath, $configContent) === false) {
            throw new Exception('Failed to update config file');
        }

        // Reload config
        $config = require $configPath;

        $successMessage = 'MCP tool permissions updated successfully.';
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Handle token regeneration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'regenerate_token') {
    CSRF::verifyOrDie();

    try {
        // Generate new token
        $newToken = bin2hex(random_bytes(32));

        // Update config file
        $configPath = __DIR__ . '/../config/config.php';
        $configContent = file_get_contents($configPath);

        // Replace token in config
        $configContent = preg_replace(
            "/'mcp_token'\s*=>\s*'[^']*'/",
            "'mcp_token' => '{$newToken}'",
            $configContent
        );

        if (file_put_contents($configPath, $configContent) === false) {
            throw new Exception('Failed to update config file');
        }

        // Reload config
        $config = require $configPath;

        $successMessage = 'MCP token regenerated successfully. Download the new config below.';
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// OAuth: revoke a connected app (all tokens of one grant)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_grant') {
    CSRF::verifyOrDie();
    require_once __DIR__ . '/../core/OAuthServer.php';
    $grantId = (string)($_POST['grant_id'] ?? '');
    if (preg_match('/^g_[a-f0-9]{16}$/', $grantId)) {
        $n = (new OAuthServer($config))->revokeGrant($grantId);
        $successMessage = $n > 0 ? 'Connected app disconnected; its tokens no longer work.' : 'That connection was already gone.';
    } else {
        $errorMessage = 'Invalid grant id.';
    }
}

// Tool presets: Writer (content only) or Developer (everything)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preset') {
    CSRF::verifyOrDie();
    require_once __DIR__ . '/../mcp/tools-definition.php';
    $knownTools = array_keys(getMCPTools());
    $preset = (string)($_POST['preset'] ?? '');
    if ($preset === 'writer') {
        $writerBase = ['list_pages', 'list_blocks', 'search_blocks', 'read_block', 'update_block', 'publish_page', 'discard_draft',
            'get_page_meta', 'update_page_meta', 'list_posts', 'create_post', 'read_post', 'update_post', 'publish_post',
            'unpublish_post', 'list_authors', 'upload_image', 'get_usage_tips'];
        $writerPrefixes = ['list_media', 'upload_image_from_url', 'list_categories', 'create_category', 'update_media', 'list_post_revisions'];
        $selected = array_values(array_filter($knownTools, function ($t) use ($writerBase, $writerPrefixes) {
            if (in_array($t, $writerBase, true)) return true;
            foreach ($writerPrefixes as $p) { if (str_starts_with($t, $p)) return true; }
            return false;
        }));
    } elseif ($preset === 'developer') {
        $selected = $knownTools;
    } else {
        $selected = null;
        $errorMessage = 'Unknown preset.';
    }
    if ($selected !== null) {
        $configPath = __DIR__ . '/../config/config.php';
        $newConfig = require $configPath;
        $newConfig['mcp_allowed_tools'] = $selected;
        $newConfig['mcp_disabled_tools'] = array_values(array_diff($knownTools, $selected));
        $configContent = "<?php\n/**\n * Core configuration for flat MCP CMS.\n */\nreturn " . var_export($newConfig, true) . ";\n";
        if (file_put_contents($configPath, $configContent) === false) {
            $errorMessage = 'Failed to update config file';
        } else {
            $config = require $configPath;
            $successMessage = ucfirst($preset) . ' preset applied: ' . count($selected) . ' tools enabled.';
        }
    }
}

// Handle config download
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host . '/cms/mcp/index.php';

    $client = $_GET['client'] ?? 'chatgpt';

    // Claude Code format
    if ($client === 'claude') {
        $configJson = [
            'mcpServers' => [
                'cms' => [
                    'type' => 'http',
                    'url' => $baseUrl,
                    'headers' => [
                        'X-CMS-MCP-TOKEN' => $config['mcp_token'],
                    ],
                ],
            ],
        ];

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=".mcp.json"');
        echo json_encode($configJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Gemini CLI format (~/.gemini/settings.json or <project>/.gemini/settings.json)
    if ($client === 'gemini') {
        $configJson = [
            'mcpServers' => [
                'cms' => [
                    'httpUrl' => $baseUrl,
                    'headers' => [
                        'X-CMS-MCP-TOKEN' => $config['mcp_token'],
                    ],
                    'timeout' => 30000,
                ],
            ],
        ];

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="settings.json"');
        echo json_encode($configJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Antigravity format (~/.gemini/config/mcp_config.json or <workspace>/.agents/mcp_config.json).
    // Antigravity only accepts serverUrl for remote servers; url/httpUrl are silently ignored.
    if ($client === 'antigravity') {
        $configJson = [
            'mcpServers' => [
                'cms' => [
                    'serverUrl' => $baseUrl,
                    'headers' => [
                        'X-CMS-MCP-TOKEN' => $config['mcp_token'],
                    ],
                ],
            ],
        ];

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="mcp_config.json"');
        echo json_encode($configJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Generic Streamable HTTP format (Cursor, Windsurf, VS Code, Cline, ...)
    if ($client === 'generic') {
        $configJson = [
            'mcpServers' => [
                'cms' => [
                    'type' => 'streamable-http',
                    'url' => $baseUrl,
                    'headers' => [
                        'X-CMS-MCP-TOKEN' => $config['mcp_token'],
                    ],
                ],
            ],
        ];

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="mcp.json"');
        echo json_encode($configJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo 'Unknown client preset';
    exit;
}

$pageTitle = 'MCP Configuration';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900 mb-2">MCP Configuration</h1>
    <p class="text-gray-600">
        <a href="/cms/admin/" class="text-blue-600 hover:text-blue-800">&larr; Back to Dashboard</a>
    </p>
</div>

<?php if (isset($successMessage)): ?>
    <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
        <p class="text-green-700"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <p class="text-red-700"><?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-semibold text-gray-900 mb-4">MCP Endpoint Configuration</h2>

    <p class="text-gray-600 mb-2">Two ways to connect an AI client to this CMS:</p>
    <ul class="list-disc list-inside text-gray-600 text-sm mb-6 space-y-1">
        <li><strong>Sign in with your CMS account (OAuth)</strong> — for ChatGPT, Claude.ai, Claude Desktop, Gemini Spark/Enterprise and IDEs. Paste the endpoint URL; the app sends you to this site's login and consent page. Each connection acts as that CMS user with that user's role.</li>
        <li><strong>Static token</strong> — for CLIs and IDEs (Claude Code, Gemini CLI, Antigravity, Codex). Sent as <code class="bg-gray-100 px-1 rounded">X-CMS-MCP-TOKEN</code> or <code class="bg-gray-100 px-1 rounded">Authorization: Bearer</code>; acts as the site owner.</li>
    </ul>

    <?php
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host . '/cms/mcp/index.php';
    ?>

    <div class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">MCP Endpoint URL:</label>
            <input type="text" value="<?php echo htmlspecialchars($baseUrl); ?>" readonly onclick="this.select()" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 font-mono text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">MCP Token:</label>
            <input type="text" value="<?php echo htmlspecialchars(substr($config['mcp_token'], 0, 16) . '...'); ?>" readonly class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-50 font-mono text-sm">
            <p class="mt-1 text-sm text-gray-500">Token is partially hidden for security. Choose a CLI/IDE client below and download its config to get the complete token; OAuth clients (ChatGPT, Claude.ai) never need it.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">AI Client:</label>
            <select id="mcp-client" class="px-3 py-2 border border-gray-300 rounded-md bg-white text-sm">
                <option value="chatgpt">ChatGPT (web / desktop, Developer mode)</option>
                <option value="claudeai">Claude.ai / Claude Desktop</option>
                <option value="claude">Claude Code</option>
                <option value="gemini">Gemini CLI</option>
                <option value="antigravity">Antigravity</option>
                <option value="codex">Codex CLI</option>
                <option value="generic">Cursor / Windsurf / VS Code (generic MCP)</option>
            </select>
        </div>

        <div class="flex gap-3 pt-2">
            <a href="?download=1&client=claude" id="download-btn" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition">Download MCP Config</a>

            <form method="post" class="inline" onsubmit="return confirm('This will invalidate your current MCP configuration. Continue?');">
                <?php echo CSRF::inputField(); ?>
                <input type="hidden" name="action" value="regenerate_token">
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition">Regenerate Token</button>
            </form>
        </div>

        <script>
            document.getElementById('mcp-client').addEventListener('change', function() {
                const hasFile = ['claude', 'gemini', 'antigravity', 'generic'].includes(this.value);
                const btn = document.getElementById('download-btn');
                btn.href = '?download=1&client=' + this.value;
                btn.classList.toggle('hidden', !hasFile);
            });
            document.getElementById('mcp-client').dispatchEvent(new Event('change'));
        </script>
    </div>
</div>

<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-semibold text-gray-900 mb-4">Installation Instructions</h2>

    <div id="instructions-chatgpt">
        <h3 class="text-lg font-medium text-gray-800 mb-3">ChatGPT (web and desktop) — sign in with OAuth</h3>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
            <li>In ChatGPT open <strong>Settings → Connectors → Advanced</strong> and turn on <strong>Developer mode</strong> (Plus, Pro, Business, Enterprise or Education plan)</li>
            <li>Back in <strong>Connectors</strong> choose <strong>Create</strong>, name it (e.g. "<?php echo htmlspecialchars($config['site_name'] ?? 'CMS'); ?>"), paste the MCP endpoint URL above and set <strong>Authentication: OAuth</strong> (leave client id/secret empty — the app registers itself)</li>
            <li>ChatGPT opens this site's login. Sign in with your CMS account and press <strong>Allow</strong></li>
            <li>In a chat, enable the connector under the "+" menu and ask e.g. "write a draft post about …"</li>
            <li>The ChatGPT desktop app shares this connector once it is created on the web</li>
        </ol>
    </div>

    <div id="instructions-claudeai" class="hidden">
        <h3 class="text-lg font-medium text-gray-800 mb-3">Claude.ai and Claude Desktop — sign in with OAuth</h3>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
            <li>Open <strong>Settings → Connectors → Add custom connector</strong></li>
            <li>Name it and paste the MCP endpoint URL above; leave the OAuth client id and secret empty</li>
            <li>Click <strong>Connect</strong>: Claude opens this site's login and consent page — sign in and press <strong>Allow</strong></li>
            <li>Claude Desktop and mobile pick the connector up automatically</li>
            <li>Alternative (beta, organization admins): add the connector with a request header <code class="bg-gray-100 px-1 py-0.5 rounded">Authorization: Bearer &lt;static token&gt;</code> instead of OAuth</li>
        </ol>
    </div>

    <div id="instructions-codex" class="hidden">
        <h3 class="text-lg font-medium text-gray-800 mb-3">Codex CLI</h3>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
            <li>Export the static token: <code class="bg-gray-100 px-1 py-0.5 rounded">export CMS_MCP_TOKEN=&lt;token&gt;</code> (download the Claude Code config to see the full token)</li>
            <li>Add to <code class="bg-gray-100 px-1 py-0.5 rounded">~/.codex/config.toml</code>:
<pre class="bg-gray-100 rounded p-3 mt-2 text-xs overflow-x-auto">[mcp_servers.cms]
url = "<?php echo htmlspecialchars($baseUrl); ?>"
bearer_token_env_var = "CMS_MCP_TOKEN"</pre></li>
            <li>Or run <code class="bg-gray-100 px-1 py-0.5 rounded">codex mcp add cms --url <?php echo htmlspecialchars($baseUrl); ?> --bearer-token-env-var CMS_MCP_TOKEN</code></li>
        </ol>
    </div>

    <div id="instructions-claude" class="hidden">
        <h3 class="text-lg font-medium text-gray-800 mb-3">Claude Code</h3>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
            <li>Select "Claude Code" from the dropdown above and download the config</li>
            <li>Place the downloaded <code class="bg-gray-100 px-1 py-0.5 rounded">.mcp.json</code> file in your project root directory</li>
            <li>Restart Claude Code (exit and reopen)</li>
            <li>Claude Code will automatically discover the MCP server</li>
        </ol>
    </div>

    <div id="instructions-gemini" class="hidden">
        <h3 class="text-lg font-medium text-gray-800 mb-3">Gemini CLI</h3>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
            <li>Select "Gemini CLI" from the dropdown above and download <code class="bg-gray-100 px-1 py-0.5 rounded">settings.json</code></li>
            <li>Merge its <code class="bg-gray-100 px-1 py-0.5 rounded">mcpServers.cms</code> entry into <code class="bg-gray-100 px-1 py-0.5 rounded">~/.gemini/settings.json</code> (all projects) or <code class="bg-gray-100 px-1 py-0.5 rounded">&lt;project&gt;/.gemini/settings.json</code> (one project)</li>
            <li>Run <code class="bg-gray-100 px-1 py-0.5 rounded">gemini mcp list</code> — the server should show as <strong>Connected</strong></li>
            <li>Inside Gemini CLI, type <code class="bg-gray-100 px-1 py-0.5 rounded">/mcp</code> to see the CMS tools, then ask e.g. "list the pages on my site"</li>
            <li>Alternatively add it from the terminal: <code class="bg-gray-100 px-1 py-0.5 rounded">gemini mcp add --transport http cms <?php echo htmlspecialchars($baseUrl); ?> --header "X-CMS-MCP-TOKEN: &lt;token&gt;"</code></li>
        </ol>
    </div>

    <div id="instructions-antigravity" class="hidden">
        <h3 class="text-lg font-medium text-gray-800 mb-3">Antigravity</h3>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
            <li>Select "Antigravity" from the dropdown above and download <code class="bg-gray-100 px-1 py-0.5 rounded">mcp_config.json</code></li>
            <li>In Antigravity, click the menu icon at the top of the agent side panel &rarr; <strong>MCP Servers</strong> &rarr; <strong>Manage MCP Servers</strong> &rarr; <strong>View raw config</strong></li>
            <li>Merge the downloaded <code class="bg-gray-100 px-1 py-0.5 rounded">mcpServers.cms</code> entry into that file (global: <code class="bg-gray-100 px-1 py-0.5 rounded">~/.gemini/config/mcp_config.json</code>, per workspace: <code class="bg-gray-100 px-1 py-0.5 rounded">.agents/mcp_config.json</code>)</li>
            <li>Restart Antigravity; the CMS tools appear in the MCP Servers panel</li>
            <li>Note: Antigravity requires the <code class="bg-gray-100 px-1 py-0.5 rounded">serverUrl</code> key (this download uses it). Configs with <code class="bg-gray-100 px-1 py-0.5 rounded">url</code> or <code class="bg-gray-100 px-1 py-0.5 rounded">httpUrl</code> are silently ignored there</li>
        </ol>
    </div>

    <div id="instructions-generic" class="hidden">
        <h3 class="text-lg font-medium text-gray-800 mb-3">Cursor, Windsurf, VS Code, Cline and other MCP clients</h3>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
            <li>Select the generic option above and download <code class="bg-gray-100 px-1 py-0.5 rounded">mcp.json</code></li>
            <li>Add the <code class="bg-gray-100 px-1 py-0.5 rounded">mcpServers.cms</code> entry to your client's MCP config (Cursor: <code class="bg-gray-100 px-1 py-0.5 rounded">.cursor/mcp.json</code>, VS Code: <code class="bg-gray-100 px-1 py-0.5 rounded">.vscode/mcp.json</code> with <code class="bg-gray-100 px-1 py-0.5 rounded">"servers"</code> instead of <code class="bg-gray-100 px-1 py-0.5 rounded">"mcpServers"</code>)</li>
            <li>The endpoint speaks stateless MCP Streamable HTTP (JSON responses, no SSE). Some clients label this transport "http" or "streamable-http". Clients that only offer a bearer-token field can use <code class="bg-gray-100 px-1 py-0.5 rounded">Authorization: Bearer &lt;token&gt;</code> instead of the custom header.</li>
        </ol>
    </div>

    <div class="mt-4 bg-yellow-50 border-l-4 border-yellow-500 p-4">
        <p class="text-yellow-700"><strong>Security Warning:</strong> The downloaded config files contain your private MCP token, which acts as the site owner. Keep them secure and never share them publicly. OAuth connections carry the connecting user's role instead and can be revoked below.</p>
    </div>

    <script>
        document.getElementById('mcp-client').addEventListener('change', function() {
            ['chatgpt', 'claudeai', 'claude', 'gemini', 'antigravity', 'codex', 'generic'].forEach((c) => {
                document.getElementById('instructions-' + c).classList.toggle('hidden', this.value !== c);
            });
        });
    </script>
</div>

<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-semibold text-gray-900 mb-2">Connected apps</h2>
    <p class="text-gray-600 text-sm mb-4">AI clients that signed in with a CMS account through OAuth. Disconnecting revokes their tokens immediately.</p>
    <?php
    require_once __DIR__ . '/../core/OAuthServer.php';
    $oauthGrants = [];
    try { $oauthGrants = (new OAuthServer($config))->listGrants(); } catch (Throwable $e) { $oauthGrants = []; }
    ?>
    <?php if ($oauthGrants === []): ?>
        <p class="text-sm text-gray-500">No apps connected yet. Connect ChatGPT or Claude.ai by pasting the endpoint URL and choosing OAuth.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200">
            <th class="py-2 pr-4">App</th><th class="py-2 pr-4">CMS user</th><th class="py-2 pr-4">Connected</th><th class="py-2 pr-4">Expires</th><th class="py-2"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($oauthGrants as $g): ?>
            <tr class="border-b border-gray-100">
                <td class="py-2 pr-4 font-medium text-gray-900"><?php echo htmlspecialchars($g['client_name']); ?></td>
                <td class="py-2 pr-4 text-gray-700"><?php echo htmlspecialchars($g['user']); ?> <span class="text-gray-400">(<?php echo htmlspecialchars($g['role']); ?>)</span></td>
                <td class="py-2 pr-4 text-gray-600"><?php echo date('Y-m-d H:i', (int)$g['issued_at']); ?></td>
                <td class="py-2 pr-4 text-gray-600"><?php echo $g['refresh_expires_at'] ? date('Y-m-d', (int)$g['refresh_expires_at']) : ($g['access_expires_at'] ? date('Y-m-d H:i', (int)$g['access_expires_at']) : '—'); ?></td>
                <td class="py-2 text-right">
                    <form method="post" class="inline" onsubmit="return confirm('Disconnect this app? It will have to sign in again.');">
                        <?php echo CSRF::inputField(); ?>
                        <input type="hidden" name="action" value="revoke_grant">
                        <input type="hidden" name="grant_id" value="<?php echo htmlspecialchars($g['grant_id']); ?>">
                        <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-xs rounded-md hover:bg-red-700 transition">Disconnect</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <h2 class="text-xl font-semibold text-gray-900 mb-2">Tool presets</h2>
    <p class="text-gray-600 text-sm mb-4">Fewer tools = clearer choices for the model (ChatGPT in particular). Presets overwrite the checklist below.</p>
    <div class="flex flex-wrap gap-3">
        <form method="post" class="inline">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="preset"><input type="hidden" name="preset" value="writer">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">Writer — posts, media, page copy</button>
        </form>
        <form method="post" class="inline">
            <?php echo CSRF::inputField(); ?>
            <input type="hidden" name="action" value="preset"><input type="hidden" name="preset" value="developer">
            <button type="submit" class="px-4 py-2 bg-gray-700 text-white rounded-md hover:bg-gray-800 transition">Developer — everything</button>
        </form>
    </div>
</div>

<div class="bg-white rounded-lg shadow-md p-6">
    <h2 class="text-xl font-semibold text-gray-900 mb-4">MCP Tool Permissions</h2>

    <form method="post" class="space-y-4">
        <?php echo CSRF::inputField(); ?>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-3">
                Allowed MCP Tools:
            </label>
            <p class="text-sm text-gray-500 mb-4">Select which tools the AI model can access. Unchecked tools will be blocked.</p>

            <?php
            // Load MCP tools definition
            require_once __DIR__ . '/../mcp/tools-definition.php';
            $allTools = getMCPTools();
            // Effective enablement (same rule as mcp/index.php): allow-list,
            // plus tools unknown to both lists, minus the deny-list.
            $cfgAllowed = $config['mcp_allowed_tools'] ?? null;
            $cfgDisabled = is_array($config['mcp_disabled_tools'] ?? null) ? $config['mcp_disabled_tools'] : [];
            $gridKnown = array_merge(is_array($cfgAllowed) ? $cfgAllowed : [], $cfgDisabled);
            $legacyStrict = is_array($cfgAllowed) && !array_key_exists('mcp_disabled_tools', $config);
            $allowedTools = array_values(array_filter(array_keys($allTools), function ($t) use ($cfgAllowed, $cfgDisabled, $gridKnown, $legacyStrict) {
                if ($legacyStrict) return in_array($t, $cfgAllowed, true);
                if (in_array($t, $cfgDisabled, true)) return false;
                if (!is_array($cfgAllowed)) return true;
                return in_array($t, $cfgAllowed, true) || !in_array($t, $gridKnown, true);
            }));
            ?>

            <div class="border border-gray-300 rounded-md p-4 max-h-96 overflow-y-auto bg-gray-50">
                <div class="space-y-3">
                    <?php foreach ($allTools as $toolName => $description): ?>
                    <div class="flex items-start bg-white p-3 rounded border border-gray-200">
                        <div class="flex items-center h-5 mt-0.5">
                            <input
                                type="checkbox"
                                name="mcp_allowed_tools[]"
                                value="<?php echo htmlspecialchars($toolName); ?>"
                                <?php echo in_array($toolName, $allowedTools) ? 'checked' : ''; ?>
                                class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                            >
                        </div>
                        <div class="ml-3 flex-1">
                            <label class="font-medium text-gray-900 text-sm">
                                <?php echo htmlspecialchars($toolName); ?>
                            </label>
                            <p class="text-xs text-gray-600 mt-0.5">
                                <?php echo htmlspecialchars($description); ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-3 flex gap-2">
                <button type="button" onclick="document.querySelectorAll('input[name=\'mcp_allowed_tools[]\']').forEach(cb => cb.checked = true)" class="text-sm px-3 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition">
                    Select All
                </button>
                <button type="button" onclick="document.querySelectorAll('input[name=\'mcp_allowed_tools[]\']').forEach(cb => cb.checked = false)" class="text-sm px-3 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 transition">
                    Deselect All
                </button>
            </div>

            <div class="mt-3 bg-yellow-50 border-l-4 border-yellow-500 p-4">
                <p class="text-sm text-yellow-700">
                    <strong>Warning:</strong> Unchecking tools will prevent the AI model from using them. Make sure you understand what each tool does before disabling it.
                </p>
            </div>
        </div>

        <div class="pt-4">
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                Save Tool Permissions
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
