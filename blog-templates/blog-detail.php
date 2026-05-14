<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($post['seo']['title'] ?: $post['title']); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($post['seo']['description'] ?: $post['excerpt']); ?>">
    <meta name="author" content="<?php echo htmlspecialchars($author['name'] ?? ''); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($baseUrl . '/' . $collection['base_path'] . '/' . $post['slug'] . '/'); ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo htmlspecialchars($baseUrl . '/' . $collection['base_path'] . '/' . $post['slug'] . '/'); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($post['seo']['title'] ?: $post['title']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($post['seo']['description'] ?: $post['excerpt']); ?>">
<?php if (!empty($post['featured_image'])): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($baseUrl . $post['featured_image']); ?>">
<?php endif; ?>
    <meta property="article:published_time" content="<?php echo htmlspecialchars($post['published_at'] ?? ''); ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($post['title']); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($post['seo']['description'] ?: $post['excerpt']); ?>">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:wght@400;700&display=swap" rel="stylesheet">

    <!-- Site CSS -->
    <link rel="stylesheet" href="/assets/css/styles.css">

    <!-- Schema.org -->
    <script type="application/ld+json">
    <?php echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'],
        'description' => $post['excerpt'],
        'datePublished' => $post['published_at'] ?? '',
        'dateModified' => $post['modified_at'] ?? '',
        'author' => [
            '@type' => 'Person',
            'name' => $author['name'] ?? '',
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => $siteName,
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $baseUrl . '/' . $collection['base_path'] . '/' . $post['slug'] . '/',
        ],
        'image' => !empty($post['featured_image']) ? $baseUrl . $post['featured_image'] : '',
        'wordCount' => $wordCount,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
    </script>

    <style>
        .article-content {
            font-family: 'Merriweather', Georgia, serif;
            font-size: 1.1rem;
            line-height: 1.9;
            color: #1f2937;
        }
        .article-content h2 { font-family: 'Inter', sans-serif; font-size: 1.6rem; font-weight: 700; color: #111827; margin: 2.5rem 0 1rem; }
        .article-content h3 { font-family: 'Inter', sans-serif; font-size: 1.3rem; font-weight: 600; color: #111827; margin: 2rem 0 0.75rem; }
        .article-content p { margin-bottom: 1.5rem; }
        .article-content ul, .article-content ol { margin-bottom: 1.5rem; padding-left: 1.5rem; }
        .article-content li { margin-bottom: 0.5rem; }
        .article-content blockquote { border-left: 4px solid #e5e7eb; padding: 1rem 1.5rem; margin: 2rem 0; font-style: italic; color: #4b5563; background: #f9fafb; border-radius: 0 0.5rem 0.5rem 0; }
        .article-content img { max-width: 100%; height: auto; border-radius: 0.75rem; margin: 2rem 0; }
        .article-content a { color: #2563eb; text-decoration: underline; text-underline-offset: 2px; }
        .article-content pre { background: #1f2937; color: #e5e7eb; padding: 1.25rem; border-radius: 0.75rem; overflow-x: auto; margin: 1.5rem 0; font-size: 0.9rem; }
        .article-content code { font-size: 0.9em; background: #f3f4f6; padding: 0.15rem 0.4rem; border-radius: 0.25rem; }
        .article-content pre code { background: none; padding: 0; }
    </style>
</head>
<body style="font-family: 'Inter', system-ui, sans-serif; background: #fff; color: #111827; margin: 0;">

    <!-- Header -->
    <header style="border-bottom: 1px solid #e5e7eb; background: #fff;">
        <div style="max-width: 1200px; margin: 0 auto; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="/" style="font-weight: 700; font-size: 1.25rem; color: #111827; text-decoration: none;"><?php echo htmlspecialchars($siteName); ?></a>
            <nav>
                <a href="/" style="color: #6b7280; text-decoration: none; margin-left: 1.5rem; font-size: 0.95rem;">Home</a>
                <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/" style="color: #6b7280; text-decoration: none; margin-left: 1.5rem; font-size: 0.95rem;"><?php echo htmlspecialchars($collection['label']); ?></a>
            </nav>
        </div>
    </header>

    <main style="max-width: 800px; margin: 0 auto; padding: 3rem 1.5rem;">

        <!-- Breadcrumb -->
        <nav style="margin-bottom: 2rem; font-size: 0.875rem; color: #6b7280;">
            <a href="/" style="color: #6b7280; text-decoration: none;">Home</a>
            <span style="margin: 0 0.5rem;">/</span>
            <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/" style="color: #6b7280; text-decoration: none;"><?php echo htmlspecialchars($collection['label']); ?></a>
            <span style="margin: 0 0.5rem;">/</span>
            <span style="color: #111827;"><?php echo htmlspecialchars($post['title']); ?></span>
        </nav>

<?php if (!empty($post['featured_image'])): ?>
        <img src="<?php echo htmlspecialchars($post['featured_image']); ?>"
             alt="<?php echo htmlspecialchars($post['featured_image_alt'] ?: $post['title']); ?>"
             style="width: 100%; height: auto; border-radius: 1rem; margin-bottom: 2rem; aspect-ratio: 16/9; object-fit: cover;">
<?php endif; ?>

        <!-- Article Header -->
        <header style="margin-bottom: 2.5rem;">
            <h1 style="font-size: 2.5rem; font-weight: 800; line-height: 1.2; margin: 0 0 1rem; color: #111827; letter-spacing: -0.02em;">
                <?php echo htmlspecialchars($post['title']); ?>
            </h1>

            <div style="display: flex; align-items: center; gap: 1rem; color: #6b7280; font-size: 0.9rem; flex-wrap: wrap;">
<?php if ($author): ?>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
<?php if (!empty($author['avatar'])): ?>
                    <img src="<?php echo htmlspecialchars($author['avatar']); ?>" alt="<?php echo htmlspecialchars($author['name']); ?>"
                         style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
<?php else: ?>
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.8rem; color: #6b7280;">
                        <?php echo strtoupper(substr($author['name'], 0, 1)); ?>
                    </div>
<?php endif; ?>
                    <span style="font-weight: 500; color: #374151;"><?php echo htmlspecialchars($author['name']); ?></span>
                </div>
                <span>&middot;</span>
<?php endif; ?>
<?php if (!empty($post['published_at'])): ?>
                <time datetime="<?php echo htmlspecialchars($post['published_at']); ?>">
                    <?php echo BlogRenderer::formatDate($post['published_at']); ?>
                </time>
                <span>&middot;</span>
<?php endif; ?>
                <span><?php echo $readingTime; ?> min read</span>
            </div>

<?php if (!empty($post['categories']) || !empty($post['tags'])): ?>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 1rem;">
<?php foreach ($post['categories'] ?? [] as $cat): ?>
                <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/?category=<?php echo urlencode($cat); ?>"
                   style="display: inline-block; padding: 0.25rem 0.75rem; font-size: 0.8rem; background: #eff6ff; color: #2563eb; border-radius: 9999px; text-decoration: none; font-weight: 500;">
                    <?php echo htmlspecialchars($cat); ?>
                </a>
<?php endforeach; ?>
<?php foreach ($post['tags'] ?? [] as $tag): ?>
                <a href="/<?php echo htmlspecialchars($collection['base_path']); ?>/?tag=<?php echo urlencode($tag); ?>"
                   style="display: inline-block; padding: 0.25rem 0.75rem; font-size: 0.8rem; background: #f3f4f6; color: #374151; border-radius: 9999px; text-decoration: none;">
                    <?php echo htmlspecialchars($tag); ?>
                </a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </header>

        <!-- Article Content -->
        <article class="article-content">
            <?php echo $post['content']; ?>
        </article>

<?php if ($author && (!empty($author['bio']) || !empty($author['social']))): ?>
        <!-- Author Box -->
        <div style="margin-top: 3rem; padding: 2rem; background: #f9fafb; border-radius: 1rem; border: 1px solid #e5e7eb;">
            <div style="display: flex; gap: 1.25rem; align-items: flex-start;">
<?php if (!empty($author['avatar'])): ?>
                <img src="<?php echo htmlspecialchars($author['avatar']); ?>" alt="<?php echo htmlspecialchars($author['name']); ?>"
                     style="width: 64px; height: 64px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
<?php else: ?>
                <div style="width: 64px; height: 64px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.5rem; color: #6b7280; flex-shrink: 0;">
                    <?php echo strtoupper(substr($author['name'], 0, 1)); ?>
                </div>
<?php endif; ?>
                <div>
                    <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($author['name']); ?></div>
<?php if (!empty($author['role'])): ?>
                    <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($author['role']); ?></div>
<?php endif; ?>
<?php if (!empty($author['bio'])): ?>
                    <p style="font-size: 0.95rem; color: #4b5563; margin: 0 0 0.75rem; line-height: 1.6;"><?php echo htmlspecialchars($author['bio']); ?></p>
<?php endif; ?>
<?php if (!empty($author['social'])): ?>
                    <div style="display: flex; gap: 1rem; font-size: 0.875rem;">
<?php foreach ($author['social'] as $platform => $handle): ?>
                        <span style="color: #6b7280;"><?php echo htmlspecialchars(ucfirst($platform)); ?>: <?php echo htmlspecialchars($handle); ?></span>
<?php endforeach; ?>
                    </div>
<?php endif; ?>
                </div>
            </div>
        </div>
<?php endif; ?>

    </main>

    <!-- Footer -->
    <footer style="border-top: 1px solid #e5e7eb; padding: 2rem 1.5rem; text-align: center; color: #9ca3af; font-size: 0.875rem;">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>. All rights reserved.</p>
    </footer>

</body>
</html>
