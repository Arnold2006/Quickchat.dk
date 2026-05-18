-- QuickChat.dk – Installationsscript
-- Kræver MySQL 5.7+ eller MariaDB 10.x+
-- Kør én gang: mysql -u root -p < install.sql

CREATE DATABASE IF NOT EXISTS quickchat
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE quickchat;

-- ---------------------------------------------------------------
-- Kategorier (vises som kort på forsiden)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL,
    description VARCHAR(255)  NOT NULL DEFAULT '',
    icon        VARCHAR(20)   NOT NULL DEFAULT '💬',
    sort_order  INT           NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Chatrum (tilhører en kategori)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    category_id INT           NOT NULL,
    name        VARCHAR(100)  NOT NULL,
    sort_order  INT           NOT NULL DEFAULT 0,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Site-konfiguration (nøgle/værdi)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_config (
    `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Standardkonfiguration
-- ---------------------------------------------------------------
INSERT INTO site_config (`key`, `value`) VALUES
    ('site_name',       'QuickChat.dk'),
    ('max_users',       '20'),
    ('max_messages',    '30'),
    ('user_timeout',    '90'),
    ('front_page_text', '')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- ---------------------------------------------------------------
-- Standard-kategorier (3 stk.)
-- ---------------------------------------------------------------
INSERT INTO categories (name, description, icon, sort_order) VALUES
    ('Generelt', 'Snak om alt og ingenting',          '💬', 1),
    ('Ungdom',   'Gaming, musik og ungdomsliv',        '🎮', 2),
    ('Danmark',  'Dansk kultur, nyheder og samfund',   '🇩🇰', 3);

-- ---------------------------------------------------------------
-- Standard-chatrum (3 pr. kategori)
-- ---------------------------------------------------------------
INSERT INTO rooms (category_id, name, sort_order)
SELECT c.id, r.rname, r.rord
FROM categories c
JOIN (
    SELECT 'Generelt' AS cname, 'Generel snak'  AS rname, 1 AS rord UNION ALL
    SELECT 'Generelt',           'Nyheder',               2          UNION ALL
    SELECT 'Generelt',           'Off-topic',              3          UNION ALL
    SELECT 'Ungdom',             'Gaming',                 1          UNION ALL
    SELECT 'Ungdom',             'Musik',                  2          UNION ALL
    SELECT 'Ungdom',             'Film & serier',          3          UNION ALL
    SELECT 'Danmark',            'Politik',                1          UNION ALL
    SELECT 'Danmark',            'Sport',                  2          UNION ALL
    SELECT 'Danmark',            'Vejret',                 3
) r ON c.name = r.cname;
