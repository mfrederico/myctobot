-- Fix directivelogs.directive_id column type
-- The column was INT but code passes VARCHAR(32) UUID strings
-- Migration: 2026_01_12_fix_directivelogs_directive_id.sql

-- Drop the foreign key first (if it exists)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'directivelogs'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

-- Drop FK if exists
DROP PROCEDURE IF EXISTS drop_fk_if_exists;
DELIMITER //
CREATE PROCEDURE drop_fk_if_exists()
BEGIN
    DECLARE fk_name VARCHAR(255);

    SELECT CONSTRAINT_NAME INTO fk_name
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'directivelogs'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    LIMIT 1;

    IF fk_name IS NOT NULL THEN
        SET @sql = CONCAT('ALTER TABLE directivelogs DROP FOREIGN KEY ', fk_name);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL drop_fk_if_exists();
DROP PROCEDURE IF EXISTS drop_fk_if_exists;

-- Drop the index on directive_id if it exists
DROP INDEX IF EXISTS idx_directive ON directivelogs;

-- Change the column type from INT to VARCHAR(32)
ALTER TABLE directivelogs MODIFY COLUMN directive_id VARCHAR(32) NOT NULL;

-- Re-add the index
CREATE INDEX idx_directive ON directivelogs(directive_id);

-- Note: We're removing the FK constraint since directive_id now references
-- ceodirectives.directive_id (VARCHAR) not ceodirectives.id (INT)
-- The FK would need to reference the directive_id column which has a UNIQUE constraint
