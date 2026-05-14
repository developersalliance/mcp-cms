<?php

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/AIClient.php';

$defaultModels = [
    'anthropic' => 'claude-sonnet-4-6',
    'openai'    => 'gpt-5',
    'gemini'    => 'gemini-2.0-pro',
];

// AJAX: test connection
if (($_GET['action'] ?? '') === 'test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    CSRF::verifyOrDie();
    $provider = $_POST['ai_provider'] ?? '';
    $apiKey = trim((string)($_POST['ai_api_key'] ?? ''));
    $model = trim((string)($_POST['ai_model'] ?? ''));
    // Sentinel "__keep__" or empty means "use the currently saved key for this provider".
    if (($apiKey === '' || $apiKey === '__keep__') && ($config['ai_provider'] ?? '') === $provider && !empty($config['ai_api_key'])) {
        $apiKey = $config['ai_api_key'];
    }
    if ($model === '' && ($config['ai_provider'] ?? '') === $provider && !empty($config['ai_model'])) {
        $model = $config['ai_model'];
    }
    if (!in_array($provider, ['anthropic', 'openai', 'gemini'], true) || $apiKey === '' || $apiKey === '__keep__' || $model === '') {
        echo json_encode(['success' => false, 'error' => 'Missing provider, key, or model.']);
        exit;
    }
    $client = new AIClient($provider, $apiKey, $model);
    echo json_encode($client->testConnection());
    exit;
}

$errorMessage = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') !== 'test') {
    CSRF::verifyOrDie();
    try {
        $configPath = __DIR__ . '/../config/config.php';
        if (!is_writable($configPath)) {
            throw new Exception('config/config.php is not writable by PHP. Run on the server: chown www-data:www-data ' . realpath($configPath));
        }
        $currentConfig = require $configPath;

        $provider = $_POST['ai_provider'] ?? '';
        if (!in_array($provider, ['', 'anthropic', 'openai', 'gemini'], true)) {
            throw new Exception('Invalid provider');
        }

        $apiKey = trim((string)($_POST['ai_api_key'] ?? ''));
        $model = trim((string)($_POST['ai_model'] ?? ''));

        if ($provider === '') {
            $apiKey = '';
            $model = '';
        } else {
            // Preserve existing key if the input is the placeholder (empty after password field clear)
            if ($apiKey === '' && !empty($currentConfig['ai_api_key']) && ($currentConfig['ai_provider'] ?? '') === $provider) {
                $apiKey = $currentConfig['ai_api_key'];
            }
            if ($apiKey === '') {
                throw new Exception('API key required when a provider is selected');
            }
            if ($model === '') {
                $model = $defaultModels[$provider];
            }
            if (!preg_match('/^[A-Za-z0-9._\-]+$/', $model)) {
                throw new Exception('Invalid model identifier');
            }
        }

        $currentConfig['ai_provider'] = $provider;
        $currentConfig['ai_api_key'] = $apiKey;
        $currentConfig['ai_model'] = $model;

        $configContent = "<?php\n/**\n * Core configuration for flat MCP CMS.\n */\nreturn " . var_export($currentConfig, true) . ";\n";
        if (file_put_contents($configPath, $configContent) === false) {
            throw new Exception('Failed to write config');
        }

        header('Location: /cms/admin/ai-settings.php?saved=1');
        exit;
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

if (isset($_GET['saved'])) {
    $successMessage = 'AI settings saved.';
}

$currentProvider = $config['ai_provider'] ?? '';
$currentModel = $config['ai_model'] ?? '';
$hasKey = !empty($config['ai_api_key']);
// On first visit (nothing configured yet), default the radio to anthropic so
// the API Key + Model fields are visible immediately instead of hidden under
// the "Disabled" state. Saving still requires the user to click Save.
$initialProvider = $currentProvider !== '' ? $currentProvider : 'anthropic';
$initialModel = $currentModel !== '' ? $currentModel : $defaultModels[$initialProvider];

$pageTitle = 'AI Settings';
$activePage = 'settings';

require __DIR__ . '/includes/header.php';
?>

<h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">AI Assistant</h1>
<p class="text-sm text-gray-600 dark:text-gray-400 mb-6">Pick a provider, paste an API key, choose a model. This powers the “Edit with AI” chat on pages and posts.<br>Get keys at <a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-accent-600 hover:underline">console.anthropic.com</a> (Claude) · <a href="https://platform.openai.com/api-keys" target="_blank" class="text-accent-600 hover:underline">platform.openai.com</a> (OpenAI) · <a href="https://aistudio.google.com/apikey" target="_blank" class="text-accent-600 hover:underline">aistudio.google.com</a> (Gemini).</p>

<?php if ($successMessage): ?>
    <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
        <p class="text-green-700"><?php echo htmlspecialchars($successMessage); ?></p>
    </div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <p class="text-red-700"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></p>
    </div>
<?php endif; ?>

<script>
  /* Hoisted out of the x-data attribute below because the JSON literals
   * for default-models would otherwise contain raw " characters inside
   * the double-quoted x-data="..." attribute and silently break Alpine
   * parsing — same bug we fixed in blog-edit.php earlier. */
  window.AI_DEFAULTS = <?php echo json_encode($defaultModels); ?>;
  window.AI_INITIAL_PROVIDER = <?php echo json_encode($initialProvider); ?>;
  window.AI_INITIAL_MODEL = <?php echo json_encode($initialModel); ?>;
  window.AI_CSRF = <?php echo json_encode(CSRF::getToken() ?? CSRF::generateToken()); ?>;
</script>
<div class="bg-white dark:bg-dark-100 rounded-lg shadow-md p-6"
     x-data="aiSettings()">
    <form method="post" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRF::getToken() ?? CSRF::generateToken()); ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Provider</label>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <?php foreach ([['', 'Disabled'], ['anthropic', 'Claude'], ['openai', 'OpenAI'], ['gemini', 'Gemini']] as $opt): list($v, $l) = $opt; ?>
                    <label class="flex items-center justify-center px-3 py-2 border rounded-md cursor-pointer text-sm transition select-none"
                           :class="provider === '<?php echo $v; ?>' ? 'bg-accent-50 border-accent-500 text-accent-700 dark:bg-accent-900/20' : 'border-gray-300 dark:border-dark-200 text-gray-700 dark:text-gray-300 hover:border-accent-400'">
                        <input type="radio" name="ai_provider" value="<?php echo $v; ?>" class="sr-only" x-model="provider" @change="setProvider('<?php echo $v; ?>')">
                        <span><?php echo $l; ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div x-show="provider !== ''" x-cloak class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    API Key
                    <?php if ($hasKey): ?>
                        <span class="text-xs font-normal text-gray-500">(leave blank to keep current key)</span>
                    <?php endif; ?>
                </label>
                <input type="password" name="ai_api_key" x-model="apiKey" autocomplete="off"
                       class="w-full px-4 py-2 border border-gray-300 dark:border-dark-200 dark:bg-dark-50 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-accent-500 font-mono text-sm"
                       placeholder="<?php echo $hasKey ? '••••••••' : 'sk-..., AIza..., etc.'; ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Model</label>
                <input type="text" name="ai_model" x-model="model"
                       class="w-full px-4 py-2 border border-gray-300 dark:border-dark-200 dark:bg-dark-50 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-accent-500 font-mono text-sm">
            </div>

            <div class="flex items-center gap-3">
                <button type="button" @click="test()" :disabled="testState === 'loading' || !provider || !model"
                        class="px-4 py-2 bg-gray-100 dark:bg-dark-200 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200 disabled:opacity-50 text-sm">
                    <span x-show="testState !== 'loading'">Test connection</span>
                    <span x-show="testState === 'loading'">Testing…</span>
                </button>
                <span x-show="testState === 'ok'" class="text-sm text-green-700" x-text="testMessage"></span>
                <span x-show="testState === 'fail'" class="text-sm text-red-700" x-text="testMessage"></span>
            </div>
        </div>

        <div class="pt-4 border-t border-gray-200 dark:border-dark-200">
            <button type="submit" class="px-6 py-2 bg-accent-600 text-white font-semibold rounded-md hover:bg-accent-700 transition">
                Save
            </button>
        </div>
    </form>
</div>

<script>
function aiSettings() {
    return {
        provider: window.AI_INITIAL_PROVIDER || '',
        model: window.AI_INITIAL_MODEL || '',
        apiKey: '',
        testState: 'idle',
        testMessage: '',
        defaults: window.AI_DEFAULTS || {},
        setProvider(p) {
            this.provider = p;
            if (p && !this.model) this.model = this.defaults[p] || '';
            if (!p) this.model = '';
        },
        async test() {
            this.testState = 'loading';
            this.testMessage = '';
            const fd = new FormData();
            fd.append('csrf_token', window.AI_CSRF);
            fd.append('ai_provider', this.provider);
            fd.append('ai_api_key', this.apiKey || '__keep__');
            fd.append('ai_model', this.model);
            try {
                const r = await fetch('/cms/admin/ai-settings.php?action=test', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.success) { this.testState = 'ok'; this.testMessage = 'Connected. Reply: ' + j.reply; }
                else { this.testState = 'fail'; this.testMessage = j.error || 'Failed'; }
            } catch (e) {
                this.testState = 'fail';
                this.testMessage = e.message;
            }
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
