USE enrollment_system;

DROP PROCEDURE IF EXISTS migrate_student_registration_schema;
DELIMITER //
CREATE PROCEDURE migrate_student_registration_schema()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM students
    WHERE student_number IS NOT NULL
      AND student_number NOT REGEXP '^(824[0-9]{4}|[0-9]{12})$'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Existing student_number values must be a seven-digit CTU ID beginning with 824 or a 12-digit LRN before this migration.';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM students
    WHERE year_level IS NOT NULL AND CHAR_LENGTH(year_level) > 40
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Existing year_level values longer than 40 characters must be corrected before this migration.';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM students
    WHERE academic_status NOT IN (
      'applicant', 'active', 'inactive', 'graduated', 'dropped',
      'suspended', 'enrolling', 'enrolled'
    )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Existing academic_status values outside the canonical enum must be corrected before this migration.';
  END IF;

  ALTER TABLE students
    MODIFY student_number VARCHAR(12) NULL,
    MODIFY year_level VARCHAR(40) NULL,
    MODIFY academic_status ENUM(
      'applicant', 'active', 'inactive', 'graduated', 'dropped',
      'suspended', 'enrolling', 'enrolled'
    ) NOT NULL DEFAULT 'applicant',
    AUTO_INCREMENT = 8240000;
END//
DELIMITER ;

CALL migrate_student_registration_schema();
DROP PROCEDURE migrate_student_registration_schema;
