<?php

class AuthorManager
{
    private string $authorsFile;
    private array $authors = [];

    public function __construct(string $configDir)
    {
        $this->authorsFile = rtrim($configDir, '/') . '/authors.json';
        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->authorsFile)) {
            $data = json_decode(file_get_contents($this->authorsFile), true);
            $this->authors = $data['authors'] ?? [];
        }
    }

    private function save(): void
    {
        $json = json_encode(['authors' => $this->authors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($this->authorsFile, $json) === false) {
            throw new Exception('Failed to save authors file');
        }
    }

    public function listAuthors(): array
    {
        return $this->authors;
    }

    public function getAuthor(string $id): ?array
    {
        foreach ($this->authors as $author) {
            if ($author['id'] === $id) {
                return $author;
            }
        }
        return null;
    }

    public function createAuthor(string $id, array $data): void
    {
        $id = $this->sanitizeId($id);
        if ($this->getAuthor($id)) {
            throw new Exception("Author already exists: {$id}");
        }

        $this->authors[] = array_merge($this->getDefaultAuthor(), $data, ['id' => $id]);
        $this->save();
    }

    public function updateAuthor(string $id, array $data): void
    {
        $found = false;
        foreach ($this->authors as &$author) {
            if ($author['id'] === $id) {
                unset($data['id']);
                $author = array_merge($author, $data);
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new Exception("Author not found: {$id}");
        }
        $this->save();
    }

    public function deleteAuthor(string $id): void
    {
        $this->authors = array_values(array_filter($this->authors, fn($a) => $a['id'] !== $id));
        $this->save();
    }

    private function sanitizeId(string $id): string
    {
        require_once __DIR__ . '/Slug.php';
        return Slug::make($id, 60, 'author');
    }

    private function getDefaultAuthor(): array
    {
        return [
            'id' => '',
            'name' => '',
            'email' => '',
            'bio' => '',
            'avatar' => '',
            'role' => 'Author',
            'social' => [],
        ];
    }
}
