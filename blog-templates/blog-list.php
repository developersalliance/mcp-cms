<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($collection['label']); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <meta name="description" content="Browse all <?php echo htmlspecialchars($collection['label']); ?> posts.">
    <link rel="canonical" href="<?php echo htmlspecialchars($baseUrl . '/' . $collection['base_path'] . '/'); ?>">

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($collection['label']); ?> - <?php echo htmlspecialchars($siteName); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/assets/css/styles.css">

    <script type="application/ld+json">
    <?php echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => $collection['label'],
        'url' => $baseUrl . '/' . $collection['base_path'] . '/',
        'publisher' => ['@type' => 'Organization', 'name' => $siteName],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
    </script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #fff; color: #111827; }
        a { text-decoration: none; color: inherit; }

        .post-card { display: grid; grid-template-columns: 1fr; gap: 1.5rem; padding: 2rem 0; border-bottom: 1px solid #f3f4f6; }
        .post-card:last-child { border-bottom: none; }
        @media (min-width: 768px) {
            .post-card.has-image { grid-template-columns: 280px 1fr; }
        }
        .post-card-image { border-radius: 0.75rem; overflow: hidden; aspect-ratio: 16/10; }
        .post-card-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
        .post-card:hover .post-card-image img { transform: scale(1.03); }
        .post-card-title { font-size: 1.35rem; font-weight: 700; line-height: 1.3; color: #111827; margin-bottom: 0.5rem; letter-spacing: -0.01em; }
        .post-card-title:hover { color: #2563eb; }
        .post-card-meta { font-size: 0.85rem; color: #6b7280; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 0.75rem; }
        .post-card-excerpt { font-size: 0.95rem; color: #4b5563; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .post-card-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.75rem; }
        .post-card-tag { display: inline-block; padding: 0.2rem 0.6rem; font-size: 0.75rem; background: #f3f4f6; color: #374151; border-radius: 9999px; }
        .post-card-tag:hover { background: #e5e7eb; }
        .featured-badge { display: inline-block; padding: 0.15rem 0.5rem; font-size: 0.7rem; font-weight: 600; background: #fef3c7; color: #92400e; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.05em; }

        .pagination { display: flex; gap: 0.5rem; justify-content: center; margin-top: 3rem; flex-wrap: wrap; }
        .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; padding: 0 0.75rem; border: 1px solid #e5e7eb; border-radius: 0.5rem; font-size: 0.9rem; color: #374151; transition: all 0.15s; }
        .pagination a:hover { background: #f3f4f6; border-color: #d1d5db; }
        .pagination .active { background: #111827; color: #fff; border-color: #111827; }
        .pagination .disabled { opacity: 0.4; cursor: default; }

        .filter-tag { display: inline-block; padding: 0.4rem 1rem; font-size: 0.85rem; background: #f3f4f6; color: #374151; border-radius: 9999px; margin-right: 0.5rem; margin-bottom: 0.5rem; }
        .filter-tag:hover { background: #e5e7eb; }
        .filter-tag.active { background: #111827; color: #fff; }
    </style>
</head>
<body>

    <!-- Header -->
    <header style="border-bottom: 1px solid #e5e7eb;">
        <div style="max-width: 1000px; margin: 0 auto; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="/" style="font-weight: 700; font-size: 1.25rem;"><?php echo htmlspecialchars($siteName); ?></a>
            <nav>
                <a href="/" style="color: #6b7280; margin-left: 1.5rem; font-size: 0.95rem;">Home</a>
                <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/" style="color: #111827; font-weight: 500; margin-left: 1.5rem; font-size: 0.95rem;"><?php echo htmlspecialchars($collection['label']); ?></a>
            </nav>
        </div>
    </header>

    <main style="max-width: 1000px; margin: 0 auto; padding: 3rem 1.5rem;">

        <!-- Page Title -->
        <div style="margin-bottom: 2.5rem;">
            <h1 style="font-size: 2.5rem; font-weight: 800; letter-spacing: -0.03em; margin-bottom: 0.5rem;">
                <?php echo htmlspecialchars($collection['label']); ?>
            </h1>
<?php if ($activeFilter): ?>
            <div style="margin-top: 1rem;">
                <span style="font-size: 0.9rem; color: #6b7280;">Filtered by:</span>
                <span class="filter-tag active"><?php echo htmlspecialchars($activeFilter); ?></span>
                <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/" class="filter-tag">Clear</a>
            </div>
<?php endif; ?>
        </div>

<?php if (empty($pagedPosts)): ?>
        <p style="color: #6b7280; padding: 3rem 0; text-align: center; font-size: 1.1rem;">No posts yet.</p>
<?php else: ?>

        <!-- Posts -->
        <div>
<?php foreach ($pagedPosts as $p):
    $pAuthor = $p['_author'] ?? null;
    $pReadTime = BlogRenderer::calculateReadingTime($p['content'] ?? '');
    $hasImage = !empty($p['featured_image']);
?>
            <article class="post-card <?php echo $hasImage ? 'has-image' : ''; ?>">
<?php if ($hasImage): ?>
                <a href="/<?php echo htmlspecialchars($collection['base_path'] . '/' . $p['slug']); ?>/" class="post-card-image">
                    <img src="<?php echo htmlspecialchars($p['featured_image']); ?>"
                         alt="<?php echo htmlspecialchars($p['featured_image_alt'] ?: $p['title']); ?>"
                         loading="lazy">
                </a>
<?php endif; ?>
                <div>
                    <div class="post-card-meta">
<?php if (!empty($p['featured'])): ?>
                        <span class="featured-badge">Featured</span>
<?php endif; ?>
<?php if (!empty($p['published_at'])): ?>
                        <time datetime="<?php echo htmlspecialchars($p['published_at']); ?>">
                            <?php echo BlogRenderer::formatDate($p['published_at']); ?>
                        </time>
<?php endif; ?>
                        <span><?php echo $pReadTime; ?> min read</span>
<?php if ($pAuthor): ?>
                        <span>&middot;</span>
                        <span><?php echo htmlspecialchars($pAuthor['name']); ?></span>
<?php endif; ?>
                    </div>
                    <a href="/<?php echo htmlspecialchars($collection['base_path'] . '/' . $p['slug']); ?>/" class="post-card-title" style="display: block;">
                        <?php echo htmlspecialchars($p['title']); ?>
                    </a>
<?php if (!empty($p['excerpt'])): ?>
                    <p class="post-card-excerpt"><?php echo htmlspecialchars($p['excerpt']); ?></p>
<?php endif; ?>
<?php if (!empty($p['categories']) || !empty($p['tags'])): ?>
                    <div class="post-card-tags">
<?php foreach ($p['categories'] ?? [] as $cat): ?>
                        <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/?category=<?php echo urlencode($cat); ?>" class="post-card-tag" style="background: #eff6ff; color: #2563eb;"><?php echo htmlspecialchars($cat); ?></a>
<?php endforeach; ?>
<?php foreach ($p['tags'] ?? [] as $tag): ?>
                        <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/?tag=<?php echo urlencode($tag); ?>" class="post-card-tag"><?php echo htmlspecialchars($tag); ?></a>
<?php endforeach; ?>
                    </div>
<?php endif; ?>
                </div>
            </article>
<?php endforeach; ?>
        </div>

<?php if ($pagination->getTotalPages() > 1): ?>
        <!-- Pagination -->
        <nav class="pagination" aria-label="Pagination">
<?php if ($pagination->hasPrevious()): ?>
            <a href="?page=<?php echo $pagination->getPreviousPage(); ?>" aria-label="Previous page">Previous</a>
<?php else: ?>
            <span class="disabled">Previous</span>
<?php endif; ?>

<?php foreach ($pagination->getPageNumbers() as $pn): ?>
<?php if ($pn === $pagination->getCurrentPage()): ?>
            <span class="active"><?php echo $pn; ?></span>
<?php else: ?>
            <a href="?page=<?php echo $pn; ?>"><?php echo $pn; ?></a>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($pagination->hasNext()): ?>
            <a href="?page=<?php echo $pagination->getNextPage(); ?>" aria-label="Next page">Next</a>
<?php else: ?>
            <span class="disabled">Next</span>
<?php endif; ?>
        </nav>
<?php endif; ?>

<?php endif; ?>

    </main>

    <footer style="border-top: 1px solid #e5e7eb; padding: 2rem 1.5rem; text-align: center; color: #9ca3af; font-size: 0.875rem;">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>. All rights reserved.</p>
    </footer>

</body>
</html>
