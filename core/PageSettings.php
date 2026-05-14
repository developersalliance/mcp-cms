<?php
/**
 * PageSettings - Manages per-page settings storage
 */

class PageSettings
{
    private string $settingsDir;

    public function __construct(string $settingsDir)
    {
        $this->settingsDir = $settingsDir;

        // Ensure settings directory exists
        if (!is_dir($settingsDir)) {
            mkdir($settingsDir, 0755, true);
        }
    }

    /**
     * Get settings for a page
     */
    public function getSettings(string $pageId): array
    {
        $settingsFile = $this->getSettingsPath($pageId);

        if (!file_exists($settingsFile)) {
            return $this->getDefaultSettings();
        }

        $json = file_get_contents($settingsFile);
        $settings = json_decode($json, true);

        if (!is_array($settings)) {
            return $this->getDefaultSettings();
        }

        // Merge with defaults to ensure all keys exist
        return array_merge($this->getDefaultSettings(), $settings);
    }

    /**
     * Save settings for a page
     */
    public function saveSettings(string $pageId, array $settings): void
    {
        $settingsFile = $this->getSettingsPath($pageId);

        // Ensure parent directory exists
        $dir = dirname($settingsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Get custom CSS input (supports both old and new format)
        $customCSS = $settings['custom_css'] ?? '';

        // Parse the combined CSS input
        $parsed = $this->parseCombinedCSS($customCSS);

        // Sanitize settings
        $sanitized = [
            'custom_css' => $this->sanitizeCSS($customCSS),
            'custom_styles' => $this->sanitizeCSS($parsed['inline_styles']),
            'custom_stylesheets' => $this->sanitizeStylesheets($parsed['stylesheets']),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Preserve created_at if exists
        $existing = $this->getSettings($pageId);
        if (isset($existing['created_at'])) {
            $sanitized['created_at'] = $existing['created_at'];
        } else {
            $sanitized['created_at'] = date('Y-m-d H:i:s');
        }

        $json = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($settingsFile, $json);
    }

    /**
     * Delete settings for a page
     */
    public function deleteSettings(string $pageId): void
    {
        $settingsFile = $this->getSettingsPath($pageId);

        if (file_exists($settingsFile)) {
            unlink($settingsFile);
        }
    }

    /**
     * Check if page has settings
     */
    public function hasSettings(string $pageId): bool
    {
        return file_exists($this->getSettingsPath($pageId));
    }

    /**
     * Copy settings from source to target page
     */
    public function copySettings(string $sourcePageId, string $targetPageId): void
    {
        if (!$this->hasSettings($sourcePageId)) {
            return;
        }

        $sourceSettings = $this->getSettings($sourcePageId);

        // Reset timestamps for the copy
        $sourceSettings['created_at'] = date('Y-m-d H:i:s');
        $sourceSettings['updated_at'] = date('Y-m-d H:i:s');

        $this->saveSettings($targetPageId, $sourceSettings);
    }

    /**
     * Parse combined CSS input (URLs, <link> tags, <style> tags)
     */
    public function parseCombinedCSS(string $input): array
    {
        $stylesheets = [];
        $inlineStyles = '';

        // Extract <link> tags and their hrefs
        preg_match_all('/<link[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $input, $linkMatches);
        if (!empty($linkMatches[1])) {
            $stylesheets = array_merge($stylesheets, $linkMatches[1]);
        }

        // Extract <style> tag contents
        preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $input, $styleMatches);
        if (!empty($styleMatches[1])) {
            $inlineStyles .= implode("\n\n", $styleMatches[1]);
        }

        // Extract plain URLs (lines that start with http:// or https:// or /)
        $lines = explode("\n", $input);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip if it's part of a tag
            if (strpos($line, '<') !== false || strpos($line, '>') !== false) {
                continue;
            }
            // Check if it looks like a URL
            if (preg_match('#^(https?://|/)[\w\-\./:]+\.css(\?.*)?$#i', $line)) {
                $stylesheets[] = $line;
            }
        }

        // Remove duplicate stylesheets
        $stylesheets = array_unique($stylesheets);

        return [
            'stylesheets' => array_values($stylesheets),
            'inline_styles' => trim($inlineStyles)
        ];
    }

    /**
     * Get default settings structure
     */
    private function getDefaultSettings(): array
    {
        return [
            'custom_css' => '',
            'custom_styles' => '',
            'custom_stylesheets' => [],
            'created_at' => null,
            'updated_at' => null
        ];
    }

    /**
     * Get settings file path for a page
     */
    private function getSettingsPath(string $pageId): string
    {
        $pageId = $pageId === '' ? 'index' : $pageId;
        $safeName = str_replace(['/', '\\', '..'], '-', $pageId);
        return $this->settingsDir . '/pages/' . $safeName . '.json';
    }

    /**
     * Sanitize CSS content
     */
    private function sanitizeCSS(string $css): string
    {
        // Remove any potential script tags or javascript: URLs
        $css = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $css);
        $css = preg_replace('/javascript:/i', '', $css);
        $css = preg_replace('/on\w+\s*=/i', '', $css);

        return trim($css);
    }

    /**
     * Sanitize stylesheet URLs
     */
    private function sanitizeStylesheets($stylesheets): array
    {
        if (is_string($stylesheets)) {
            // Split by newlines if it's a string
            $stylesheets = array_filter(array_map('trim', explode("\n", $stylesheets)));
        }

        if (!is_array($stylesheets)) {
            return [];
        }

        $sanitized = [];
        foreach ($stylesheets as $url) {
            $url = trim($url);

            // Basic URL validation
            if (empty($url)) {
                continue;
            }

            // Remove javascript: URLs
            if (stripos($url, 'javascript:') !== false) {
                continue;
            }

            $sanitized[] = $url;
        }

        return $sanitized;
    }
}
