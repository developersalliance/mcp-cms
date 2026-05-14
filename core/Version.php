<?php
/**
 * Version - Gets version information from git
 *
 * Automatically retrieves current git commit hash and date
 */

class Version
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;
    }

    /**
     * Get the current git commit hash (short version)
     *
     * @return string|null Git commit hash or null if not available
     */
    public function getCommitHash(): ?string
    {
        $gitDir = $this->rootDir . '/.git';

        if (!is_dir($gitDir)) {
            return null;
        }

        // Read HEAD file to get current branch reference
        $headFile = $gitDir . '/HEAD';
        if (!file_exists($headFile)) {
            return null;
        }

        $head = trim(file_get_contents($headFile));

        // If HEAD contains a ref, read that ref file
        if (strpos($head, 'ref:') === 0) {
            $refPath = substr($head, 5); // Remove "ref: " prefix
            $refFile = $gitDir . '/' . trim($refPath);

            if (file_exists($refFile)) {
                $hash = trim(file_get_contents($refFile));
                return substr($hash, 0, 7); // Short hash (7 chars)
            }
        } else {
            // HEAD contains the hash directly (detached HEAD state)
            return substr($head, 0, 7);
        }

        return null;
    }

    /**
     * Get the current git commit date
     *
     * @return string|null Commit date or null if not available
     */
    public function getCommitDate(): ?string
    {
        if (!is_dir($this->rootDir . '/.git')) {
            return null;
        }

        // Use git command if available
        $output = [];
        $returnVar = 0;

        exec('cd ' . escapeshellarg($this->rootDir) . ' && git log -1 --format=%ci 2>/dev/null', $output, $returnVar);

        if ($returnVar === 0 && !empty($output[0])) {
            return date('Y-m-d H:i', strtotime($output[0]));
        }

        return null;
    }

    /**
     * Get version string for display
     *
     * @return string Version string (e.g., "v1.2.3-abc1234" or "dev-abc1234")
     */
    public function getVersionString(): string
    {
        $hash = $this->getCommitHash();
        $date = $this->getCommitDate();

        if ($hash) {
            $version = 'v1.0.0-' . $hash;
            if ($date) {
                $version .= ' (' . $date . ')';
            }
            return $version;
        }

        return 'development';
    }

    /**
     * Get simple version for display
     *
     * @return string Simple version (e.g., "abc1234")
     */
    public function getSimpleVersion(): string
    {
        $hash = $this->getCommitHash();
        return $hash ?? 'dev';
    }
}
