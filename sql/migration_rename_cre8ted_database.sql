-- Optional: rename database from legacy cre8ted_inventory to cre8ted_inventory
-- Only run if you already have data under the old name and want to keep it.
--
-- Option A (recommended for dev): recreate from schema.sql
--   Get-Content ".\sql\schema.sql" | mysql -u root -p
--
-- Option B (keep existing data):
--   CREATE DATABASE cre8ted_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   mysqldump -u root cre8ted_inventory | mysql -u root cre8ted_inventory
--   Then update php/api/config.php DB_NAME to cre8ted_inventory and drop old DB if desired.

CREATE DATABASE IF NOT EXISTS cre8ted_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
