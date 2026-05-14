<?php
/**
 * Blog Post Preview — admin-only.
 *
 * Delegates to BlogRenderer::renderPreview() so the preview goes through
 * the exact same template + meta merge pipeline as the live render, but
 * with the published-status gate removed so drafts/scheduled posts are
 * viewable.
 *
 * Auth-guarded — draft bodies are unpublished work and must not be
 * reachable anonymously.
 */

require_once __DIR__ . '/includes/auth-guard.php';
require_once __DIR__ . '/../core/BlogRenderer.php';

$collectionId = $_GET['collection'] ?? 'blog';
$slug         = $_GET['slug'] ?? '';

if ($slug === '') {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><title>Bad request</title><h1>Missing slug</h1>';
    exit;
}

// Tag the response so we know this is the admin preview path if/when we
// want a visible "Preview" banner in templates.
header('X-Preview: 1');

BlogRenderer::renderPreview($collectionId, $slug);
