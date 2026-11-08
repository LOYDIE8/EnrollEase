-- Allow either a seven-digit CTU student ID or a 12-digit LRN.
USE enrollment_system;

DROP PROCEDURE IF EXISTS migrate_lrn_student_numbers;
DELIMITER //
CREATE PROCEDURE migrate_lrn_student_numbers()
BEGIN
  DECLARE number_check_exists INT DEFAULT 0;
  DECLARE registry_table_exists INT DEFAULT 0;

  SELECT COUNT(*) INTO registry_table_exists
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'enrolled_student_registry';
  IF registry_table_exists = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Apply the enrolled student registry migration before this migration.';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM students
    WHERE student_number IS NOT NULL
      AND student_number NOT REGEXP '^(824[0-9]{4}|[0-9]{12})$'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Correct student numbers to a 7-digit CTU ID beginning with 824 or a 12-digit LRN before applying this migration.';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM enrolled_student_registry
    WHERE student_number NOT REGEXP '^(824[0-9]{4}|[0-9]{12})$'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Correct registry student numbers to a 7-digit CTU ID beginning with 824 or a 12-digit LRN before applying this migration.';
  END IF;

  SELECT COUNT(*) INTO number_check_exists
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'students'
    AND CONSTRAINT_NAME = 'chk_students_number_format'
    AND CONSTRAINT_TYPE = 'CHECK';

  IF number_check_exists > 0 THEN
    ALTER TABLE students DROP CHECK chk_students_number_format;
  END IF;

  ALTER TABLE students
    MODIFY student_number VARCHAR(12) NULL,
    ADD CONSTRAINT chk_students_number_format
      CHECK (student_number IS NULL OR student_number REGEXP '^(824[0-9]{4}|[0-9]{12})$');

  ALTER TABLE enrolled_student_registry
    MODIFY student_number VARCHAR(12) NOT NULL;
END//
DELIMITER ;

CALL migrate_lrn_student_numbers();
DROP PROCEDURE migrate_lrn_student_numbers;
