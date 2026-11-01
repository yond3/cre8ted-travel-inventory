-- Phase 4 additive upgrade. Run ONCE with writers paused and a verified backup.
-- Existing items remain uncategorized. Never import schema.sql as an upgrade.
CREATE TABLE inventory_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    normalized_name VARCHAR(80) NOT NULL,
    parent_id INT NULL,
    parent_scope INT GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED,
    active TINYINT(1) NOT NULL DEFAULT 1,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY category_sibling_name (parent_scope, normalized_name),
    KEY category_parent_active (parent_id, active),
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES inventory_categories(id) ON DELETE RESTRICT,
    CONSTRAINT category_active CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO inventory_categories (id,name,normalized_name) VALUES
 (1,'Electronic Equipment','electronic equipment'),
 (2,'Office Supplies / Stationery','office supplies / stationery'),
 (3,'Furniture','furniture'), (4,'Cleaning Supplies','cleaning supplies'), (5,'Other','other');
INSERT INTO inventory_categories (name,normalized_name,parent_id) VALUES
 ('Computers','computers',1), ('Networking Equipment','networking equipment',1),
 ('Peripherals','peripherals',1), ('Accessories','accessories',1),
 ('Paper Products','paper products',2), ('Writing Materials','writing materials',2),
 ('Filing Supplies','filing supplies',2), ('Chemicals','chemicals',4),
 ('Cleaning Tools','cleaning tools',4), ('Sanitation Supplies','sanitation supplies',4);
ALTER TABLE items
 ADD COLUMN category_id INT NULL,
 ADD COLUMN category_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ADD KEY item_category (category_id),
 ADD CONSTRAINT fk_item_category FOREIGN KEY (category_id) REFERENCES inventory_categories(id) ON DELETE RESTRICT;
