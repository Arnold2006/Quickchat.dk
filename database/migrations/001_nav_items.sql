-- Migration 001: Navigation menu items table
CREATE TABLE IF NOT EXISTS nav_items (
    id         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(100)  NOT NULL,
    url        VARCHAR(500)  NOT NULL,
    sort_order INT           NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nav_items (label, url, sort_order)
SELECT '✉️ Skriv til Admin', 'contact.php', 1
WHERE NOT EXISTS (SELECT 1 FROM nav_items LIMIT 1);
