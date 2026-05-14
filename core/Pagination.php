<?php
/**
 * Pagination - Helper class for generating pagination
 */

class Pagination
{
    private int $totalItems;
    private int $itemsPerPage;
    private int $currentPage;
    private int $totalPages;

    public function __construct(int $totalItems, int $itemsPerPage = 10, int $currentPage = 1)
    {
        $this->totalItems = max(0, $totalItems);
        $this->itemsPerPage = max(1, $itemsPerPage);
        $this->currentPage = max(1, $currentPage);
        $this->totalPages = $this->itemsPerPage > 0 ? (int)ceil($this->totalItems / $this->itemsPerPage) : 1;

        // Ensure current page doesn't exceed total pages
        if ($this->currentPage > $this->totalPages && $this->totalPages > 0) {
            $this->currentPage = $this->totalPages;
        }
    }

    /**
     * Get offset for database/array queries
     */
    public function getOffset(): int
    {
        return ($this->currentPage - 1) * $this->itemsPerPage;
    }

    /**
     * Get limit for database/array queries
     */
    public function getLimit(): int
    {
        return $this->itemsPerPage;
    }

    /**
     * Get total number of pages
     */
    public function getTotalPages(): int
    {
        return $this->totalPages;
    }

    /**
     * Get current page number
     */
    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Check if there is a previous page
     */
    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Check if there is a next page
     */
    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    /**
     * Get previous page number
     */
    public function getPreviousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    /**
     * Get next page number
     */
    public function getNextPage(): int
    {
        return min($this->totalPages, $this->currentPage + 1);
    }

    /**
     * Get array of page numbers to display
     * @param int $maxPages Maximum number of page links to show
     */
    public function getPageNumbers(int $maxPages = 7): array
    {
        if ($this->totalPages <= $maxPages) {
            return range(1, $this->totalPages);
        }

        $pages = [];
        $halfMax = (int)floor($maxPages / 2);

        // Calculate start and end
        $start = max(1, $this->currentPage - $halfMax);
        $end = min($this->totalPages, $this->currentPage + $halfMax);

        // Adjust if we're near the beginning or end
        if ($this->currentPage <= $halfMax) {
            $end = min($maxPages, $this->totalPages);
        } elseif ($this->currentPage >= $this->totalPages - $halfMax) {
            $start = max(1, $this->totalPages - $maxPages + 1);
        }

        return range($start, $end);
    }

    /**
     * Generate HTML pagination navigation
     */
    public function generateHTML(string $basePath = '', string $class = 'pagination'): string
    {
        if ($this->totalPages <= 1) {
            return '';
        }

        $html = '<nav class="' . htmlspecialchars($class) . '" aria-label="Pagination">';
        $html .= '<ul class="pagination-list">';

        // Previous button
        if ($this->hasPrevious()) {
            $prevUrl = $this->getPageUrl($basePath, $this->getPreviousPage());
            $html .= '<li><a href="' . htmlspecialchars($prevUrl) . '" class="pagination-previous" aria-label="Previous page">Previous</a></li>';
        } else {
            $html .= '<li><span class="pagination-previous disabled" aria-disabled="true">Previous</span></li>';
        }

        // Page numbers
        $pageNumbers = $this->getPageNumbers();

        // Show first page if not in range
        if ($pageNumbers[0] > 1) {
            $url = $this->getPageUrl($basePath, 1);
            $html .= '<li><a href="' . htmlspecialchars($url) . '" class="pagination-link">1</a></li>';
            if ($pageNumbers[0] > 2) {
                $html .= '<li><span class="pagination-ellipsis">...</span></li>';
            }
        }

        foreach ($pageNumbers as $page) {
            $url = $this->getPageUrl($basePath, $page);
            $activeClass = $page === $this->currentPage ? ' active' : '';
            $ariaCurrent = $page === $this->currentPage ? ' aria-current="page"' : '';

            if ($page === $this->currentPage) {
                $html .= '<li><span class="pagination-link' . $activeClass . '"' . $ariaCurrent . '>' . $page . '</span></li>';
            } else {
                $html .= '<li><a href="' . htmlspecialchars($url) . '" class="pagination-link' . $activeClass . '">' . $page . '</a></li>';
            }
        }

        // Show last page if not in range
        if ($pageNumbers[count($pageNumbers) - 1] < $this->totalPages) {
            if ($pageNumbers[count($pageNumbers) - 1] < $this->totalPages - 1) {
                $html .= '<li><span class="pagination-ellipsis">...</span></li>';
            }
            $url = $this->getPageUrl($basePath, $this->totalPages);
            $html .= '<li><a href="' . htmlspecialchars($url) . '" class="pagination-link">' . $this->totalPages . '</a></li>';
        }

        // Next button
        if ($this->hasNext()) {
            $nextUrl = $this->getPageUrl($basePath, $this->getNextPage());
            $html .= '<li><a href="' . htmlspecialchars($nextUrl) . '" class="pagination-next" aria-label="Next page">Next</a></li>';
        } else {
            $html .= '<li><span class="pagination-next disabled" aria-disabled="true">Next</span></li>';
        }

        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Generate URL for a specific page
     */
    private function getPageUrl(string $basePath, int $page): string
    {
        $basePath = rtrim($basePath, '/');

        if ($page === 1) {
            return $basePath . '/';
        }

        return $basePath . '/page/' . $page . '/';
    }

    /**
     * Get info about current pagination state
     */
    public function getInfo(): array
    {
        return [
            'current_page' => $this->currentPage,
            'total_pages' => $this->totalPages,
            'total_items' => $this->totalItems,
            'items_per_page' => $this->itemsPerPage,
            'offset' => $this->getOffset(),
            'limit' => $this->getLimit(),
            'has_previous' => $this->hasPrevious(),
            'has_next' => $this->hasNext(),
        ];
    }
}
