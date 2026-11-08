-- LOCAL TESTING ONLY. This intentionally clears local eligibility blockers,
-- including balances, holds, unresolved requirements, and incomplete grades.
-- Do not run this against a production or shared database.
USE enrollment_system;

DROP PROCEDURE IF EXISTS dev_make_student_eligible;
DELIMITER //
CREATE PROCEDURE dev_make_student_eligible(IN p_student_number VARCHAR(12))
BEGIN
  DECLARE v_student_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_locked_student_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_required_documents INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_locked_student_id = NULL;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF p_student_number IS NULL OR p_student_number NOT REGEXP '^(824[0-9]{4}|[0-9]{12})$' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Enter a seven-digit CTU student number beginning with 824 or a 12-digit LRN.';
  END IF;

  START TRANSACTION;

  SELECT MAX(id)
    INTO v_student_id
    FROM students
    WHERE student_number = p_student_number;

  IF v_student_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'The requested student number was not found.';
  END IF;

  SELECT COUNT(*)
    INTO v_required_documents
    FROM enrollment_document_types
    WHERE is_active = 1 AND is_required = 1;

  IF v_required_documents = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No active required enrollment document types are configured.';
  END IF;

  SELECT id
    INTO v_locked_student_id
    FROM students
    WHERE id = v_student_id
    FOR UPDATE;

  IF v_locked_student_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'The student record changed while the local testing update was starting.';
  END IF;

  UPDATE students
    SET year_level = 'First Year',
        academic_status = 'active'
    WHERE id = v_student_id;

  UPDATE grades
    SET grade = 100.00,
        grade_status = 'complete'
    WHERE student_id = v_student_id;

  INSERT INTO student_enrollment_subjects (student_id, subject, is_required)
    VALUES (v_student_id, 'LOCAL TEST REQUIRED SUBJECT', 1)
    ON DUPLICATE KEY UPDATE is_required = 1;

  INSERT INTO grades (student_id, subject, grade, grade_status)
    SELECT v_student_id, 'LOCAL TEST REQUIRED SUBJECT', 100.00, 'complete'
    WHERE NOT EXISTS (
      SELECT 1
      FROM grades
      WHERE student_id = v_student_id
        AND subject = 'LOCAL TEST REQUIRED SUBJECT'
    );

  UPDATE financial_records
    SET balance = 0.00,
        payment_status = 'paid',
        description = COALESCE(description, 'Local testing eligibility record')
    WHERE student_id = v_student_id;

  INSERT INTO financial_records (student_id, balance, payment_status, description)
    SELECT v_student_id, 0.00, 'paid', 'Local testing eligibility record'
    WHERE NOT EXISTS (
      SELECT 1 FROM financial_records WHERE student_id = v_student_id
    );

  UPDATE enrollment_holds
    SET status = 'released'
    WHERE student_id = v_student_id AND status = 'active';

  UPDATE student_enrollment_requirements
    SET status = 'resolved'
    WHERE student_id = v_student_id
      AND blocks_enrollment = 1
      AND status <> 'resolved';

  INSERT INTO student_enrollment_documents (student_id, document_type_id, status)
    SELECT v_student_id, id, 'verified'
    FROM enrollment_document_types
    WHERE is_active = 1 AND is_required = 1
    ON DUPLICATE KEY UPDATE status = 'verified';

  COMMIT;
  SELECT CONCAT('Student ', p_student_number, ' is eligible for local testing.') AS result;
END//
DELIMITER ;

-- Change the student number before running if testing another local account.
CALL dev_make_student_eligible('8240000');
DROP PROCEDURE dev_make_student_eligible;
