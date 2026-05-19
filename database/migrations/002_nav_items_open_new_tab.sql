-- Migration 002: Add open_new_tab column to nav_items
ALTER TABLE nav_items
    ADD COLUMN open_new_tab TINYINT(1) NOT NULL DEFAULT 0 AFTER url;
