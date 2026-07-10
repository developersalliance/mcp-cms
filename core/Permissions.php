<?php
/**
 * Permissions - Role-based access control for the admin panel.
 *
 * Capabilities are the atomic units of authorization (e.g. "pages.delete").
 * Roles map to a set of capabilities. The mapping ships with safe defaults
 * (see DEFAULT_ROLES) and can be overridden per-install via config/roles.json,
 * edited through the admin permission grid (admin/roles.php).
 *
 * The 'owner' role always holds every capability and cannot be reduced — this
 * guarantees a site can never lock itself out of user/role management.
 */

class Permissions
{
    /**
     * Capability catalogue, grouped for the permission grid UI.
     * Order here is the order shown in the grid.
     */
    public const CATALOG = [
        'Pages' => [
            'pages.create'  => 'Create pages',
            'pages.edit'    => 'Edit pages',
            'pages.publish' => 'Publish / discard drafts',
            'pages.delete'  => 'Delete pages',
        ],
        'Blog & Collections' => [
            'blog.create'  => 'Create posts',
            'blog.edit'    => 'Edit posts',
            'blog.publish' => 'Publish / schedule posts',
            'blog.delete'  => 'Delete posts',
        ],
        'Assets' => [
            'media.manage' => 'Upload / delete media',
            'files.manage' => 'Use the file manager',
        ],
        'System' => [
            'backups.manage'  => 'View / restore backups',
            'settings.manage' => 'Change settings (general, MCP, AI)',
            'users.manage'    => 'Manage users, roles & permissions',
        ],
    ];

    /**
     * Wildcard capability granting everything. Only 'owner' holds it.
     */
    public const ALL = '*';

    /**
     * Built-in roles and their default capabilities. Used as a fallback when
     * config/roles.json is absent, and as the seed the permission grid edits.
     * 'owner' is intentionally omitted from editable capability lists here — it
     * is always treated as holding ALL (see roleCapabilities()).
     */
    public const DEFAULT_ROLES = [
        'owner' => [
            'label' => 'Owner',
            'capabilities' => [self::ALL],
        ],
        'admin' => [
            'label' => 'Administrator',
            'capabilities' => [
                'pages.create', 'pages.edit', 'pages.publish', 'pages.delete',
                'blog.create', 'blog.edit', 'blog.publish', 'blog.delete',
                'media.manage', 'files.manage',
                'backups.manage', 'settings.manage',
            ],
        ],
        'editor' => [
            'label' => 'Editor',
            'capabilities' => [
                'pages.create', 'pages.edit', 'pages.publish',
                'blog.create', 'blog.edit', 'blog.publish',
                'media.manage', 'files.manage',
            ],
        ],
        'author' => [
            'label' => 'Author',
            'capabilities' => [
                'blog.create', 'blog.edit',
                'media.manage',
            ],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'capabilities' => [],
        ],
    ];

    private string $rolesFile;

    /** @var array<string,array{label:string,capabilities:string[]}> */
    private array $roles;

    public function __construct(string $rolesFile)
    {
        $this->rolesFile = $rolesFile;
        $this->roles = $this->loadRoles();
    }

    /**
     * Flat list of every known capability key.
     *
     * @return string[]
     */
    public static function allCapabilities(): array
    {
        $caps = [];
        foreach (self::CATALOG as $group) {
            foreach ($group as $cap => $_label) {
                $caps[] = $cap;
            }
        }
        return $caps;
    }

    /**
     * @return array<string,array{label:string,capabilities:string[]}>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function roleExists(string $role): bool
    {
        return isset($this->roles[$role]);
    }

    public function roleLabel(string $role): string
    {
        return $this->roles[$role]['label'] ?? ucfirst($role);
    }

    /**
     * Effective capabilities for a role. Owner always resolves to the full
     * catalogue; unknown roles resolve to none.
     *
     * @return string[]
     */
    public function roleCapabilities(string $role): array
    {
        if ($role === 'owner') {
            return self::allCapabilities();
        }
        return $this->roles[$role]['capabilities'] ?? [];
    }

    /**
     * Does the given role hold the given capability?
     */
    public function roleCan(string $role, string $capability): bool
    {
        if ($role === 'owner') {
            return true;
        }
        $caps = $this->roles[$role]['capabilities'] ?? [];
        return in_array(self::ALL, $caps, true) || in_array($capability, $caps, true);
    }

    /**
     * Replace the editable roles (owner is always forced to ALL and cannot be
     * removed). Unknown capabilities are dropped. Persists to roles.json.
     *
     * @param array<string,string[]> $roleCaps role => list of capability keys
     * @throws Exception if the file cannot be written
     */
    public function saveGrid(array $roleCaps): void
    {
        $valid = self::allCapabilities();
        $out = [];

        // Always keep a fully-capable owner role.
        $out['owner'] = [
            'label' => $this->roles['owner']['label'] ?? 'Owner',
            'capabilities' => [self::ALL],
        ];

        foreach ($roleCaps as $role => $caps) {
            if ($role === 'owner') {
                continue; // owner is fixed
            }
            $role = preg_replace('/[^a-z0-9_-]/', '', strtolower($role));
            if ($role === '') {
                continue;
            }
            $clean = array_values(array_intersect($valid, array_map('strval', (array)$caps)));
            $out[$role] = [
                'label' => $this->roles[$role]['label'] ?? ucfirst($role),
                'capabilities' => $clean,
            ];
        }

        $this->roles = $out;
        $this->persist();
    }

    /**
     * @return array<string,array{label:string,capabilities:string[]}>
     */
    private function loadRoles(): array
    {
        $roles = self::DEFAULT_ROLES;

        if (is_file($this->rolesFile)) {
            $data = json_decode((string)file_get_contents($this->rolesFile), true);
            if (is_array($data) && isset($data['roles']) && is_array($data['roles'])) {
                // Overlay stored roles on top of defaults so newly-added built-in
                // roles keep working even against an older roles.json.
                foreach ($data['roles'] as $key => $def) {
                    if (!is_string($key) || !is_array($def)) {
                        continue;
                    }
                    $roles[$key] = [
                        'label' => isset($def['label']) ? (string)$def['label'] : (self::DEFAULT_ROLES[$key]['label'] ?? ucfirst($key)),
                        'capabilities' => isset($def['capabilities']) && is_array($def['capabilities'])
                            ? array_values(array_map('strval', $def['capabilities']))
                            : [],
                    ];
                }
            }
        }

        // Owner is non-negotiable.
        $roles['owner'] = [
            'label' => $roles['owner']['label'] ?? 'Owner',
            'capabilities' => [self::ALL],
        ];

        return $roles;
    }

    /**
     * @throws Exception if the file cannot be written
     */
    private function persist(): void
    {
        $json = json_encode(['roles' => $this->roles], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->rolesFile, $json) === false) {
            throw new Exception('Failed to write roles file');
        }
    }
}
