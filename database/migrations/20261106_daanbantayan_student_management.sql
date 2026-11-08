-- MariaDB 10.4+ or MySQL 8.0+. Back up enrollment_system before applying.
-- Adds the single supported campus and student-management metadata without
-- replacing or deleting existing student, appointment, or administrator records.
USE enrollment_system;

CREATE TABLE IF NOT EXISTS campuses (
  campus_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campus_name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (campus_id),
  UNIQUE KEY uq_campuses_name (campus_name)
) ENGINE=InnoDB;

INSERT INTO campuses (campus_name)
VALUES ('Daanbantayan Campus')
ON DUPLICATE KEY UPDATE campus_name = VALUES(campus_name);

CREATE TABLE IF NOT EXISTS admin_audit_logs (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED NULL,
  student_id BIGINT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  old_data JSON NULL,
  new_data JSON NULL,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY ix_admin_audit_student_date (student_id, date_created),
  KEY ix_admin_audit_admin_date (admin_id, date_created),
  CONSTRAINT fk_admin_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL,
  CONSTRAINT fk_admin_audit_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS migrate_daanbantayan_student_management;
DELIMITER //
CREATE PROCEDURE migrate_daanbantayan_student_management()
BEGIN
  DECLARE campus_column_exists INT DEFAULT 0;
  DECLARE middle_column_exists INT DEFAULT 0;
  DECLARE status_column_exists INT DEFAULT 0;
  DECLARE index_exists INT DEFAULT 0;
  DECLARE constraint_exists INT DEFAULT 0;
  DECLARE year_constraint_exists INT DEFAULT 0;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'The students table is missing; apply the existing enrollment schema first.';
  END IF;

  IF EXISTS (
    SELECT student_number FROM students
    WHERE student_number IS NOT NULL AND student_number NOT REGEXP '^(824[0-9]{4}|[0-9]{12})$'
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Correct student numbers to a seven-digit CTU ID beginning with 824 or a 12-digit LRN before applying this migration.';
  END IF;

  IF EXISTS (
    SELECT 1 FROM students
    WHERE year_level IS NOT NULL
      AND LOWER(TRIM(year_level)) NOT IN (
        '1st year', '2nd year', '3rd year', '4th year',
        'first year', 'second year', 'third year', 'fourth year',
        'first', 'second', 'third', 'fourth'
      )
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Correct unsupported year_level values before applying this migration.';
  END IF;

  IF EXISTS (
    SELECT student_number FROM students
    WHERE student_number IS NOT NULL
    GROUP BY student_number HAVING COUNT(*) > 1
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Resolve duplicate student numbers before applying this migration.';
  END IF;

  SELECT COUNT(*) INTO middle_column_exists
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'middle_name';
  IF middle_column_exists = 0 THEN
    ALTER TABLE students ADD COLUMN middle_name VARCHAR(100) NULL AFTER student_number;
  END IF;

  SELECT COUNT(*) INTO campus_column_exists
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'campus_id';
  IF campus_column_exists = 0 THEN
    ALTER TABLE students ADD COLUMN campus_id BIGINT UNSIGNED NULL AFTER year_level;
  END IF;

  SELECT COUNT(*) INTO status_column_exists
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'student_status';
  IF status_column_exists = 0 THEN
    ALTER TABLE students
      ADD COLUMN student_status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' AFTER campus_id;
  ELSEIF EXISTS (
    SELECT 1 FROM students
    WHERE student_status NOT IN ('Active', 'Inactive') OR student_status IS NULL
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Correct student_status values to Active or Inactive before applying this migration.';
  END IF;
  ALTER TABLE students
    MODIFY student_status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active';

  UPDATE students
  SET campus_id = (
    SELECT campus_id FROM campuses WHERE campus_name = 'Daanbantayan Campus' LIMIT 1
  );

  IF EXISTS (
    SELECT 1 FROM students AS s
    LEFT JOIN campuses AS c ON c.campus_id = s.campus_id
    WHERE c.campus_id IS NULL
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Some students reference an unknown campus; correct those records before applying this migration.';
  END IF;

  ALTER TABLE students MODIFY campus_id BIGINT UNSIGNED NOT NULL;

  SELECT COUNT(*) INTO index_exists
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
    AND INDEX_NAME = 'ix_students_campus_status';
  IF index_exists = 0 THEN
    ALTER TABLE students ADD KEY ix_students_campus_status (campus_id, student_status);
  END IF;

  SELECT COUNT(*) INTO constraint_exists
  FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
    AND CONSTRAINT_NAME = 'fk_students_campus';
  IF constraint_exists = 0 THEN
    ALTER TABLE students
      ADD CONSTRAINT fk_students_campus
      FOREIGN KEY (campus_id) REFERENCES campuses (campus_id);
  END IF;

  SELECT COUNT(*) INTO year_constraint_exists
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
    AND CONSTRAINT_NAME = 'chk_students_year_level' AND CONSTRAINT_TYPE = 'CHECK';
  IF year_constraint_exists = 0 THEN
    ALTER TABLE students
      ADD CONSTRAINT chk_students_year_level CHECK (
        year_level IS NULL OR LOWER(TRIM(year_level)) IN (
          '1st year', '2nd year', '3rd year', '4th year',
          'first year', 'second year', 'third year', 'fourth year',
          'first', 'second', 'third', 'fourth'
        )
      );
  END IF;

  SELECT COUNT(*) INTO index_exists
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'
    AND INDEX_NAME = 'uq_students_number' AND NON_UNIQUE = 0;
  IF index_exists = 0 THEN
    ALTER TABLE students ADD UNIQUE KEY uq_students_number (student_number);
  END IF;

  SELECT COUNT(*) INTO index_exists
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_email' AND NON_UNIQUE = 0;
  IF index_exists = 0 THEN
    IF EXISTS (
      SELECT email FROM users
      GROUP BY email HAVING COUNT(*) > 1
      LIMIT 1
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Resolve duplicate user email addresses before applying this migration.';
    END IF;
    ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);
  END IF;
END//
DELIMITER ;

CALL migrate_daanbantayan_student_management();
DROP PROCEDURE migrate_daanbantayan_student_management;

DROP TRIGGER IF EXISTS trg_students_daanbantayan_insert;
DROP TRIGGER IF EXISTS trg_students_daanbantayan_update;
DELIMITER //
CREATE TRIGGER trg_students_daanbantayan_insert
BEFORE INSERT ON students
FOR EACH ROW
BEGIN
  DECLARE selected_campus VARCHAR(120);
  SELECT campus_name INTO selected_campus
  FROM campuses WHERE campus_id = NEW.campus_id;
  IF selected_campus IS NULL OR selected_campus <> 'Daanbantayan Campus' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Students may only be registered at Daanbantayan Campus.';
  END IF;
END//

CREATE TRIGGER trg_students_daanbantayan_update
BEFORE UPDATE ON students
FOR EACH ROW
BEGIN
  DECLARE selected_campus VARCHAR(120);
  SELECT campus_name INTO selected_campus
  FROM campuses WHERE campus_id = NEW.campus_id;
  IF selected_campus IS NULL OR selected_campus <> 'Daanbantayan Campus' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Students may only be assigned to Daanbantayan Campus.';
  END IF;
END//
DELIMITER ;
