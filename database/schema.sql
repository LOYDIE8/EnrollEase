-- EnrollEase enrollment appointment system
-- MySQL 8.0+ / InnoDB. Apply through a reviewed migration in production.
-- Enforce role, department, and record-level authorization in the PHP API;
-- frontend filtering is for presentation only and is not a security boundary.

CREATE DATABASE IF NOT EXISTS enrollment_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE enrollment_system;

CREATE TABLE roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_key VARCHAR(40) NOT NULL,
  role_name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_key (role_key)
) ENGINE=InnoDB;

CREATE TABLE departments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_code VARCHAR(40) NOT NULL,
  department_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_code (department_code),
  UNIQUE KEY uq_departments_name (department_name)
) ENGINE=InnoDB;

CREATE TABLE campuses (
  campus_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campus_name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (campus_id),
  UNIQUE KEY uq_campuses_name (campus_name)
) ENGINE=InnoDB;

INSERT INTO campuses (campus_name) VALUES ('Daanbantayan Campus');

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(32) NULL,
  account_type ENUM('student', 'admin') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_type_active (account_type, is_active)
) ENGINE=InnoDB;

CREATE TABLE students (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  student_number VARCHAR(12) NULL,
  middle_name VARCHAR(100) NULL,
  program VARCHAR(150) NOT NULL,
  year_level VARCHAR(40) NULL,
  campus_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  student_status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  department_id BIGINT UNSIGNED NOT NULL,
  academic_status VARCHAR(50) NOT NULL DEFAULT 'Regular',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_user (user_id),
  UNIQUE KEY uq_students_number (student_number),
  KEY ix_students_department_status (department_id, academic_status),
  KEY ix_students_campus_status (campus_id, student_status),
  CONSTRAINT chk_students_number_format CHECK (student_number IS NULL OR student_number REGEXP '^(824[0-9]{4}|[0-9]{12})$'),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_students_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_students_campus FOREIGN KEY (campus_id) REFERENCES campuses (campus_id),
  CONSTRAINT chk_students_year_level CHECK (
    year_level IS NULL OR LOWER(TRIM(year_level)) IN (
      '1st year', '2nd year', '3rd year', '4th year',
      'first year', 'second year', 'third year', 'fourth year',
      'first', 'second', 'third', 'fourth'
    )
  )
) ENGINE=InnoDB;

ALTER TABLE students AUTO_INCREMENT = 8240000;

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

CREATE TABLE grades (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(150) NOT NULL,
  grade DECIMAL(5,2) NULL,
  grade_status ENUM('complete', 'missing', 'incomplete', 'deficient', 'failed', 'pending') NOT NULL DEFAULT 'pending',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_grades_student_status (student_id, grade_status),
  CONSTRAINT fk_grades_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_enrollment_subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(150) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_enrollment_subject (student_id, subject),
  CONSTRAINT fk_student_enrollment_subjects_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE financial_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('paid', 'unpaid', 'pending') NOT NULL DEFAULT 'pending',
  description VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_financial_student_status (student_id, payment_status),
  CONSTRAINT fk_financial_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE enrollment_holds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  status ENUM('active', 'released') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_enrollment_holds_student_status (student_id, status),
  CONSTRAINT fk_enrollment_holds_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE enrollment_document_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_name VARCHAR(180) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment_document_name (document_name)
) ENGINE=InnoDB;

CREATE TABLE student_enrollment_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  document_type_id BIGINT UNSIGNED NOT NULL,
  status ENUM('missing', 'submitted', 'verified', 'rejected') NOT NULL DEFAULT 'missing',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_enrollment_document (student_id, document_type_id),
  CONSTRAINT fk_student_enrollment_documents_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_student_enrollment_documents_type FOREIGN KEY (document_type_id) REFERENCES enrollment_document_types (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_enrollment_requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  requirement_name VARCHAR(180) NOT NULL,
  status ENUM('unresolved', 'in_progress', 'resolved') NOT NULL DEFAULT 'unresolved',
  blocks_enrollment TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_student_enrollment_requirements (student_id, blocks_enrollment, status),
  CONSTRAINT fk_student_enrollment_requirements_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE enrollment_eligibility_rules (
  rule_key VARCHAR(60) NOT NULL,
  rule_name VARCHAR(180) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  rule_value JSON NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rule_key)
) ENGINE=InnoDB;

CREATE TABLE enrollment_appointment_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slot_date DATE NOT NULL,
  slot_time TIME NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 150,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment_slot_date_time (slot_date, slot_time),
  KEY ix_enrollment_slots_available (is_open, slot_date, slot_time),
  CONSTRAINT chk_enrollment_slot_capacity CHECK (capacity BETWEEN 1 AND 150)
) ENGINE=InnoDB;

CREATE TABLE enrollment_schedules (
  schedule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  year_level VARCHAR(50) NOT NULL,
  week_number TINYINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (schedule_id),
  UNIQUE KEY uq_enrollment_schedule_year_week (year_level, week_number),
  KEY ix_enrollment_schedule_dates (status, start_date, end_date),
  CONSTRAINT chk_enrollment_schedule_week CHECK (
    (year_level = 'First Year' AND week_number = 1) OR
    (year_level = 'Second Year' AND week_number = 2) OR
    (year_level = 'Third Year' AND week_number = 3) OR
    (year_level = 'Fourth Year' AND week_number = 4) OR
    (year_level = 'Late Enrollment' AND week_number = 5)
  ),
  CONSTRAINT chk_enrollment_schedule_dates CHECK (start_date <= end_date)
) ENGINE=InnoDB;

CREATE TABLE enrollment_daily_capacity (
  appointment_date DATE NOT NULL,
  daily_capacity SMALLINT UNSIGNED NOT NULL DEFAULT 150,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (appointment_date),
  CONSTRAINT chk_enrollment_daily_capacity CHECK (daily_capacity = 150)
) ENGINE=InnoDB;

CREATE TABLE enrollment_schedule_days (
  schedule_day_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id BIGINT UNSIGNED NOT NULL,
  appointment_date DATE NOT NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (schedule_day_id),
  UNIQUE KEY uq_enrollment_schedule_day (schedule_id, appointment_date),
  KEY ix_enrollment_schedule_day_date (appointment_date, is_open),
  CONSTRAINT fk_enrollment_schedule_day_schedule FOREIGN KEY (schedule_id)
    REFERENCES enrollment_schedules (schedule_id) ON DELETE CASCADE,
  CONSTRAINT fk_enrollment_schedule_day_capacity FOREIGN KEY (appointment_date)
    REFERENCES enrollment_daily_capacity (appointment_date)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id BIGINT UNSIGNED NOT NULL,
  permission_key VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_permissions (role_id, permission_key),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  department_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  deactivated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_user (user_id),
  KEY ix_admins_department_active (department_id, is_active),
  CONSTRAINT fk_admins_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles (id),
  CONSTRAINT fk_admins_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_admins_created_by FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value JSON NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_system_settings_admin FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE enrolled_student_registry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_number VARCHAR(12) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  program VARCHAR(150) NOT NULL,
  year_level VARCHAR(40) NOT NULL,
  academic_status VARCHAR(40) NOT NULL DEFAULT 'Regular',
  campus_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_registry_student_number (student_number)
) ENGINE=InnoDB;

CREATE TABLE appointment_status (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status_code VARCHAR(32) NOT NULL,
  status_name VARCHAR(60) NOT NULL,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appointment_status_code (status_code)
) ENGINE=InnoDB;

CREATE TABLE appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  reference_number VARCHAR(40) NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  department_id BIGINT UNSIGNED NOT NULL,
  appointment_status_id SMALLINT UNSIGNED NOT NULL,
  appointment_type ENUM('Enrollment Appointment') NOT NULL DEFAULT 'Enrollment Appointment',
  scheduled_at DATETIME NOT NULL,
  student_note TEXT NULL,
  admin_note TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appointments_public_id (public_id),
  UNIQUE KEY uq_appointments_reference_number (reference_number),
  KEY ix_appointments_type_schedule (appointment_type, scheduled_at),
  KEY ix_appointments_department_schedule (department_id, scheduled_at),
  KEY ix_appointments_student_schedule (student_id, scheduled_at),
  KEY ix_appointments_status_schedule (appointment_status_id, scheduled_at),
  CONSTRAINT fk_appointments_student FOREIGN KEY (student_id) REFERENCES students (id),
  CONSTRAINT fk_appointments_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_appointments_status FOREIGN KEY (appointment_status_id) REFERENCES appointment_status (id),
  CONSTRAINT fk_appointments_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_appointments_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE appointment_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  from_status_id SMALLINT UNSIGNED NULL,
  to_status_id SMALLINT UNSIGNED NOT NULL,
  changed_by BIGINT UNSIGNED NOT NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_appointment_history_appointment (appointment_id, created_at),
  CONSTRAINT fk_appointment_history_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE,
  CONSTRAINT fk_appointment_history_from_status FOREIGN KEY (from_status_id) REFERENCES appointment_status (id),
  CONSTRAINT fk_appointment_history_to_status FOREIGN KEY (to_status_id) REFERENCES appointment_status (id),
  CONSTRAINT fk_appointment_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE enrollment_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,
  academic_year VARCHAR(9) NOT NULL,
  semester ENUM('first', 'second', 'summer') NOT NULL,
  enrollment_status ENUM('in_progress', 'requirements_pending', 'completed', 'rejected') NOT NULL DEFAULT 'in_progress',
  registrar_admin_id BIGINT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment_student_term (student_id, academic_year, semester),
  KEY ix_enrollment_status_term (enrollment_status, academic_year, semester),
  CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES students (id),
  CONSTRAINT fk_enrollment_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
  CONSTRAINT fk_enrollment_registrar FOREIGN KEY (registrar_admin_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- LEGACY TABLE: requirements is legacy and unused by the enrollment booking gate.
CREATE TABLE requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_id BIGINT UNSIGNED NOT NULL,
  requirement_code VARCHAR(60) NOT NULL,
  requirement_name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requirements_department_code (department_id, requirement_code),
  CONSTRAINT fk_requirements_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB;

-- LEGACY TABLE: clearance_records is legacy and unused by the enrollment booking gate.
CREATE TABLE clearance_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  requirement_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,
  clearance_status ENUM('pending', 'cleared', 'not_cleared', 'referred') NOT NULL DEFAULT 'pending',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clearance_student_requirement (student_id, requirement_id),
  KEY ix_clearances_requirement_status (requirement_id, clearance_status),
  CONSTRAINT fk_clearances_student FOREIGN KEY (student_id) REFERENCES students (id),
  CONSTRAINT fk_clearances_requirement FOREIGN KEY (requirement_id) REFERENCES requirements (id),
  CONSTRAINT fk_clearances_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
  CONSTRAINT fk_clearances_reviewer FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- LEGACY TABLE: payments is legacy and unused by the enrollment booking gate.
CREATE TABLE payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,
  receipt_number VARCHAR(80) NULL,
  amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'PHP',
  payment_status ENUM('pending', 'paid', 'unpaid', 'refunded') NOT NULL DEFAULT 'pending',
  payment_method VARCHAR(60) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  paid_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_receipt (receipt_number),
  KEY ix_payments_student_status (student_id, payment_status),
  CONSTRAINT fk_payments_student FOREIGN KEY (student_id) REFERENCES students (id),
  CONSTRAINT fk_payments_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_admin FOREIGN KEY (recorded_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,
  notification_type VARCHAR(60) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  delivery_channel ENUM('in_app', 'email') NOT NULL DEFAULT 'in_app',
  delivery_status ENUM('queued', 'sent', 'failed', 'read') NOT NULL DEFAULT 'queued',
  read_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notifications_user_created (user_id, created_at),
  KEY ix_notifications_delivery (delivery_channel, delivery_status, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE auth_rate_limits (
  scope VARCHAR(40) NOT NULL,
  subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope, subject_hash),
  KEY ix_auth_rate_limits_locked_until (locked_until),
  KEY ix_auth_rate_limits_window_started (window_started_at)
) ENGINE=InnoDB;

CREATE TABLE announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  published_at DATETIME NULL,
  expires_at DATETIME NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_announcements_publication (is_published, published_at, expires_at),
  CONSTRAINT fk_announcements_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT fk_announcements_admin FOREIGN KEY (created_by) REFERENCES admins (id)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_role_id BIGINT UNSIGNED NULL,
  department_id BIGINT UNSIGNED NULL,
  action_key VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_activity_department_created (department_id, created_at),
  KEY ix_activity_actor_created (actor_user_id, created_at),
  KEY ix_activity_entity (entity_type, entity_id),
  CONSTRAINT fk_activity_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_activity_role FOREIGN KEY (actor_role_id) REFERENCES roles (id) ON DELETE SET NULL,
  CONSTRAINT fk_activity_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE admin_audit_logs (
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

INSERT INTO roles (role_key, role_name, description) VALUES
  ('SHARED_ADMIN', 'System Administrator', 'Single shared administrator account with full system access'),
  ('MIS', 'Main Admin', 'Full system access and cross-department administration'),
  ('SSG_ADMIN', 'SSG Admin', 'SSG appointments, assigned student information, and announcements'),
  ('CODETECH_ADMIN', 'CodeTech Admin', 'CodeTech appointments, students, and document verification'),
  ('COED_ADMIN', 'COED Admin', 'COED appointments, students, and document verification'),
  ('HM_ADMIN', 'HM Admin', 'HM appointments, students, and document verification'),
  ('BIT_ADMIN', 'BIT Admin', 'BIT appointments, students, and document verification'),
  ('REGISTRAR_ADMIN', 'Registrar Admin', 'Enrollment records, requirements, and student documents'),
  ('CLINIC_ADMIN', 'Clinic Admin', 'Medical clearance records only'),
  ('CASHIER_ADMIN', 'Cashier Admin', 'Payment records and payment history only'),
  ('GUIDANCE_ADMIN', 'Guidance Admin', 'Guidance clearance records only');

INSERT INTO departments (department_code, department_name) VALUES
  ('SSG', 'SSG'),
  ('CodeTech', 'CodeTech'),
  ('COED', 'COED'),
  ('HM', 'HM'),
  ('BIT', 'BIT'),
  ('Registrar', 'Registrar'),
  ('Clinic', 'Clinic'),
  ('Cashier', 'Cashier'),
  ('Guidance', 'Guidance');

INSERT INTO appointment_status (status_code, status_name, is_terminal, sort_order) VALUES
  ('submitted', 'Submitted', 0, 1),
  ('pending', 'Pending', 0, 2),
  ('approved', 'Approved', 0, 3),
  ('confirmed', 'Confirmed', 0, 4),
  ('rescheduled', 'Rescheduled', 0, 5),
  ('rejected', 'Rejected', 1, 6),
  ('completed', 'Completed', 1, 7),
  ('cancelled', 'Cancelled', 1, 8);

INSERT INTO enrollment_eligibility_rules (rule_key, rule_name, is_enabled, rule_value) VALUES
  ('grades', 'All grades completed and no academic deficiencies', 1, NULL),
  ('financial', 'No outstanding financial obligation', 1, NULL),
  ('holds', 'No active enrollment hold', 1, NULL),
  ('documents', 'All required enrollment documents verified', 1, NULL),
  ('student_status', 'Student has an active enrollment status', 1, JSON_ARRAY('active')),
  ('other_requirements', 'No unresolved enrollment-blocking requirement', 1, NULL);

INSERT INTO enrollment_document_types (document_name, is_required) VALUES
  ('Previous semester grade report', 1),
  ('Student identification', 1);

INSERT INTO enrollment_schedules (year_level, week_number, start_date, end_date, status) VALUES
  ('First Year', 1, '2026-11-09', '2026-11-13', 'Active'),
  ('Second Year', 2, '2026-11-16', '2026-11-20', 'Active'),
  ('Third Year', 3, '2026-11-23', '2026-11-27', 'Active'),
  ('Fourth Year', 4, '2026-11-30', '2026-12-04', 'Active'),
  ('Late Enrollment', 5, '2026-12-07', '2026-12-11', 'Active');

INSERT INTO enrollment_daily_capacity (appointment_date)
SELECT DISTINCT DATE_ADD(schedules.start_date, INTERVAL offsets.day_offset DAY)
FROM enrollment_schedules AS schedules
CROSS JOIN (
  SELECT 0 AS day_offset UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
  SELECT 3 UNION ALL SELECT 4
) AS offsets
WHERE schedules.status = 'Active';

INSERT INTO enrollment_schedule_days (schedule_id, appointment_date)
SELECT schedules.schedule_id, DATE_ADD(schedules.start_date, INTERVAL offsets.day_offset DAY)
FROM enrollment_schedules AS schedules
CROSS JOIN (
  SELECT 0 AS day_offset UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
  SELECT 3 UNION ALL SELECT 4
) AS offsets
WHERE schedules.status = 'Active';

INSERT INTO enrollment_appointment_slots (slot_date, slot_time, capacity)
SELECT days.appointment_date, times.slot_time, 150
FROM enrollment_schedule_days AS days
CROSS JOIN (
  SELECT '08:00:00' AS slot_time UNION ALL SELECT '09:00:00' UNION ALL
  SELECT '10:00:00' UNION ALL SELECT '13:00:00' UNION ALL
  SELECT '14:00:00'
) AS times
WHERE days.is_open = 1;

INSERT INTO role_permissions (role_id, permission_key)
SELECT r.id, p.permission_key
FROM roles AS r
JOIN (
  SELECT 'SHARED_ADMIN' AS role_key, 'system.*' AS permission_key UNION ALL
  SELECT 'SHARED_ADMIN', 'appointments.*' UNION ALL
  SELECT 'SHARED_ADMIN', 'students.*' UNION ALL
  SELECT 'SHARED_ADMIN', 'admins.*' UNION ALL
  SELECT 'SHARED_ADMIN', 'roles.assign' UNION ALL
  SELECT 'SHARED_ADMIN', 'enrollment_records.*' UNION ALL
  SELECT 'SHARED_ADMIN', 'clearances.*' UNION ALL
  SELECT 'SHARED_ADMIN', 'payments.*' UNION ALL
  SELECT 'SHARED_ADMIN', 'reports.read' UNION ALL
  SELECT 'SHARED_ADMIN', 'activity_logs.read' UNION ALL
  SELECT 'SHARED_ADMIN', 'announcements.*' UNION ALL
  SELECT 'MIS' AS role_key, 'system.*' AS permission_key UNION ALL
  SELECT 'MIS', 'appointments.*' UNION ALL
  SELECT 'MIS', 'students.*' UNION ALL
  SELECT 'MIS', 'admins.*' UNION ALL
  SELECT 'MIS', 'roles.assign' UNION ALL
  SELECT 'MIS', 'enrollment_records.*' UNION ALL
  SELECT 'MIS', 'clearances.*' UNION ALL
  SELECT 'MIS', 'payments.*' UNION ALL
  SELECT 'MIS', 'reports.read' UNION ALL
  SELECT 'MIS', 'activity_logs.read' UNION ALL
  SELECT 'MIS', 'announcements.*' UNION ALL
  SELECT 'SSG_ADMIN', 'appointments.read.assigned' UNION ALL
  SELECT 'SSG_ADMIN', 'appointments.update.assigned' UNION ALL
  SELECT 'SSG_ADMIN', 'students.read.assigned' UNION ALL
  SELECT 'SSG_ADMIN', 'announcements.manage' UNION ALL
  SELECT 'CODETECH_ADMIN', 'appointments.read.assigned' UNION ALL
  SELECT 'CODETECH_ADMIN', 'appointments.update.assigned' UNION ALL
  SELECT 'CODETECH_ADMIN', 'students.read.assigned' UNION ALL
  SELECT 'CODETECH_ADMIN', 'documents.verify.assigned' UNION ALL
  SELECT 'COED_ADMIN', 'appointments.read.assigned' UNION ALL
  SELECT 'COED_ADMIN', 'appointments.update.assigned' UNION ALL
  SELECT 'COED_ADMIN', 'students.read.assigned' UNION ALL
  SELECT 'COED_ADMIN', 'documents.verify.assigned' UNION ALL
  SELECT 'HM_ADMIN', 'appointments.read.assigned' UNION ALL
  SELECT 'HM_ADMIN', 'appointments.update.assigned' UNION ALL
  SELECT 'HM_ADMIN', 'students.read.assigned' UNION ALL
  SELECT 'HM_ADMIN', 'documents.verify.assigned' UNION ALL
  SELECT 'BIT_ADMIN', 'appointments.read.assigned' UNION ALL
  SELECT 'BIT_ADMIN', 'appointments.update.assigned' UNION ALL
  SELECT 'BIT_ADMIN', 'students.read.assigned' UNION ALL
  SELECT 'BIT_ADMIN', 'documents.verify.assigned' UNION ALL
  SELECT 'REGISTRAR_ADMIN', 'students.read' UNION ALL
  SELECT 'REGISTRAR_ADMIN', 'enrollment_records.read' UNION ALL
  SELECT 'REGISTRAR_ADMIN', 'enrollment_records.update' UNION ALL
  SELECT 'REGISTRAR_ADMIN', 'documents.review' UNION ALL
  SELECT 'CLINIC_ADMIN', 'clearances.medical.read' UNION ALL
  SELECT 'CLINIC_ADMIN', 'clearances.medical.update' UNION ALL
  SELECT 'CASHIER_ADMIN', 'payments.read' UNION ALL
  SELECT 'CASHIER_ADMIN', 'payments.update' UNION ALL
  SELECT 'GUIDANCE_ADMIN', 'clearances.guidance.read' UNION ALL
  SELECT 'GUIDANCE_ADMIN', 'clearances.guidance.update'
) AS p ON p.role_key = r.role_key;

INSERT INTO requirements (department_id, requirement_code, requirement_name, description, sort_order)
SELECT d.id, r.requirement_code, r.requirement_name, r.description, r.sort_order
FROM departments AS d
JOIN (
  SELECT 'Registrar' AS department_name, 'ENROLLMENT_RECORD' AS requirement_code, 'Enrollment record review' AS requirement_name, 'Verify enrollment record and confirm completed requirements.' AS description, 1 AS sort_order UNION ALL
  SELECT 'Clinic', 'MEDICAL_CLEARANCE', 'Medical clearance', 'Record medical clearance as cleared, pending, or not cleared.', 1 UNION ALL
  SELECT 'Guidance', 'GUIDANCE_CLEARANCE', 'Guidance clearance', 'Record guidance clearance as cleared, pending, or referred.', 1 UNION ALL
  SELECT 'Cashier', 'PAYMENT_REQUIREMENT', 'Enrollment payment', 'Record payment as paid, pending, or unpaid.', 1 UNION ALL
  SELECT 'CodeTech', 'DOCUMENT_VERIFICATION', 'Required documents', 'Verify required enrollment documents for CodeTech.', 1 UNION ALL
  SELECT 'COED', 'DOCUMENT_VERIFICATION', 'Required documents', 'Verify required enrollment documents for COED.', 1 UNION ALL
  SELECT 'HM', 'DOCUMENT_VERIFICATION', 'Required documents', 'Verify required enrollment documents for HM.', 1 UNION ALL
  SELECT 'BIT', 'DOCUMENT_VERIFICATION', 'Required documents', 'Verify required enrollment documents for BIT.', 1
) AS r ON r.department_name = d.department_name;

-- SMTP/PHPMailer integration: enqueue email notifications in notifications,
-- then let a PHP worker send queued rows and update delivery_status/sent_at.
-- Keep SMTP usernames/passwords in server-side environment or secret storage;
-- do not store them in system_settings or expose them to the frontend.

-- System settings defaults
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('require_registry_match', 'false')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- Default administrator account for local development:
-- Username: admin
-- Email: admin@example.com
-- Password: Admin-Enrollment-2026! (also accepts Admin123!)
INSERT INTO users (username, email, password_hash, first_name, last_name, account_type)
SELECT 'admin', 'admin@example.com', '$2y$10$tekhq2rjhoLm/rgOOX7Msui6bAVvDjo67Ojy1db2c12zFHY2XoK3S', 'System', 'Admin', 'admin'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin' OR email = 'admin@example.com');

INSERT INTO admins (user_id, role_id, department_id, is_active)
SELECT u.id, r.id, NULL, 1
FROM users u
JOIN roles r ON r.role_key = 'SHARED_ADMIN'
WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM admins WHERE user_id = u.id);

-- Official pre-registered students registry (mock MIS data)
INSERT INTO enrolled_student_registry (student_number, last_name, first_name, middle_name, program, year_level, academic_status, campus_id)
VALUES
  ('8240871', 'ABADILLA', 'CLINT JOHN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8261058', 'ABADILLA', 'CRYSLER JAY', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260513', 'ABINA', 'JOHN LLOYD', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8231600', 'AGUILAR', 'KRISTINA CASSANDRA', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8261200', 'AGUIPO', 'COLYN', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8260236', 'ALAJID', 'WHELLA MAE MARIE', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8250473', 'ALBURO', 'ROBERTSON', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8260631', 'ALMACIN', 'ARDRED', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8260736', 'ALOLOD', 'CHARMINE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8262070', 'ALQUILITA', 'JAMEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8261831', 'ALTAGRACIA', 'MONICA', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8240537', 'AMODIA', 'HONEYLYN', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '3rd Year', 'Regular', 1),
  ('8250529', 'AMODIA', 'KYLA', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '2nd Year', 'Regular', 1),
  ('8260560', 'AMODIA', 'SHIENA MAE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260930', 'ANIÑON', 'RIZ', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8230817', 'ANTOLIJAO', 'SHEILA MAE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '4th Year', 'Regular', 1),
  ('8240745', 'APURA', 'JILLIAN GRACE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8261328', 'ARCENAL', 'JOHN MICHAEL', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8250260', 'ARCENAL', 'MEA KYLA', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8250740', 'ARCENAL', 'SHAIRA MAE', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8260650', 'ARCENAL', 'SHEENA MAYE', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8241575', 'ARENAS', 'AIZEL FAITH', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8261997', 'AREVALO', 'EJ', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8251228', 'ARGALLON', 'RICO', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8241011', 'ARMEAN', 'LEIKA', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8261216', 'ARRABIS', 'KATRECE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8251277', 'ARRABIS', 'KYLE CHRISTIAN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8252960', 'ARRANCHADO', 'CARL JOSHUA', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8230056', 'ARREGLADO', 'IVAN KET', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '4th Year', 'Regular', 1),
  ('8261223', 'ARREGLO', 'DENVER', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8252924', 'ARREGLO', 'DONYVIE', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8260818', 'ARRIESGADO', 'ELLEN GAY', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8250611', 'ARRIESGADO', 'ELYKA', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8260980', 'ARRIESGADO', 'JUN LLOYD', NULL, 'BACHELOR OF SCIENCE IN FISHERIES', '1st Year', 'Regular', 1),
  ('8261316', 'ARRIESGADO', 'MARIA ZAIDE', NULL, 'BACHELOR OF SCIENCE IN FISHERIES', '1st Year', 'Regular', 1),
  ('8241211', 'ARROGANTE', 'JHON CLEFFORD', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8241083', 'ARROJADO', 'EARL ANDRIE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8242449', 'ARSOLON', 'JASON', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8261358', 'ASPERA', 'JINKY', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8261355', 'ATIENZA', 'RIAN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8230175', 'AUMAN', 'ALMERA', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '4th Year', 'Regular', 1),
  ('8250693', 'AUMAN', 'ARVIE', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '2nd Year', 'Regular', 1),
  ('8250654', 'AUMAN', 'IRENE', NULL, 'BACHELOR OF SCIENCE IN FISHERIES', '2nd Year', 'Regular', 1),
  ('8260898', 'AUMAN', 'VANESSA', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '1st Year', 'Regular', 1),
  ('8250584', 'AYING', 'MARK VINCENT', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8241010', 'BACOLOD', 'JENALYN', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '3rd Year', 'Regular', 1),
  ('8260622', 'BACOLOD', 'JOHN MICHAEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8260704', 'BAHIAN', 'JC MAE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8250944', 'BALANSAG', 'ANGELOFEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250634', 'BAQUIR', 'KRISTINE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8251491', 'BARIL', 'HEROLD JAMES', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8251071', 'BARING', 'HYDIE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8262045', 'BARING', 'ZYRA', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240489', 'BATERZAL', 'JHOVANA', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '3rd Year', 'Regular', 1),
  ('8251286', 'BATIANCILA', 'JOURZEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8251280', 'BATIANCILA', 'NECOLE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8240109', 'BATIANCILA', 'PRINCESS ANN', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8250853', 'BATUIGAS', 'CHARLOTTE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8240849', 'BATUIGAS', 'JUCILLE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8210780', 'BAUNO', 'JOHN FERNAND', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8260955', 'BEDRAN', 'EJ', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8251175', 'BEJASA', 'ECAMELL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8260964', 'BELORIA', 'PRINCE DOMINIC', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '1st Year', 'Regular', 1),
  ('8260966', 'BENARO', 'GLYZA MAE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8261185', 'BENDECIO', 'JEROME', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8240899', 'BERACES', 'DONNABEL', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8262361', 'BERACES', 'SHANE SCARLET', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8250446', 'BERYAWEL', 'IVELYN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8230859', 'BIHAG', 'SHEENA ANN', NULL, 'BACHELOR OF SCIENCE IN FISHERIES', '4th Year', 'Regular', 1),
  ('8261681', 'BOHOL', 'ANGEL ROSE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8261264', 'BOHOL', 'LORETO', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260023', 'BOHOL', 'MERRY ROSE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8240542', 'BOHOL', 'SIMPLICIO III', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '3rd Year', 'Regular', 1),
  ('8250316', 'BRIONES', 'CATHERINE', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '2nd Year', 'Regular', 1),
  ('8260479', 'BRIONES', 'JAKE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260556', 'BROÑOLA', 'NHINA RHIAN', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '1st Year', 'Regular', 1),
  ('8250953', 'BRUCES', 'JOHN SEVE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8230005', 'BRUSAS', 'ELLA MAE', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '4th Year', 'Regular', 1),
  ('8260532', 'BUAN', 'JARENT', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8230622', 'BUANGJUG', 'HAZEL MAE', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '4th Year', 'Regular', 1),
  ('8231382', 'BUAYABAN', 'DANNY KHIEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8260668', 'BUENO', 'NIÑA KATRICE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8240514', 'BUGTONG', 'MARIA FATIMA', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8261456', 'BULOTANO', 'MICHAEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240353', 'CABANES', 'CHRISTIAN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8260536', 'CAMAY', 'NICOLE', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '1st Year', 'Regular', 1),
  ('8241296', 'CAMPUS', 'MAIROSE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8251183', 'CAMPUS', 'ROMY', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8260943', 'CANALES', 'EJ CLENT', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8251107', 'CANALES', 'KLEINT JHON', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250103', 'CANALES', 'MICOLE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8260445', 'CANALES', 'RODEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8261903', 'CANALES', 'SHAINA LHEZ', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8260174', 'CAÑETE', 'PEARLYN', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8250558', 'CANTELA', 'RYZELL', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8231332', 'CAPISNON', 'AUGUSTO', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8241224', 'CAPISNON', 'GENALYN', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8250160', 'CAPISNON', 'JONATHAN', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '2nd Year', 'Regular', 1),
  ('8261041', 'CARABIO', 'OLIVER JR.', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8260584', 'CARABIO', 'VINCENT', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8251013', 'CARBONQUILLO', 'JANE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8241069', 'CASIA', 'ERWIN', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8241080', 'CASIDA', 'JAMES ANTHONY', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8260308', 'CASIPIT', 'KATE ASHLEY', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8262053', 'CASQUEJO', 'JOHN GILBERT', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8261229', 'CATALONIA', 'RONALD', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8260977', 'CATAMPO', 'ARGIE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8251004', 'CAVALIDA', 'DANIEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250669', 'CERNAL', 'FEBE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8251283', 'CHAVEZ', 'KISHELLE ROSE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250578', 'CLAMARES', 'MIA', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250143', 'COBOSA', 'JOHANNAH PEARL', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '2nd Year', 'Regular', 1),
  ('8261221', 'COLINA', 'CHRISTIAN', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240674', 'COMBATE', 'KHERE', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '3rd Year', 'Regular', 1),
  ('8261943', 'COMETA', 'NEPHIGENE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240808', 'COMPUESTO', 'EDEN ROSE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8231339', 'CONCHAS', 'GLENN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8250979', 'CONDE', 'CHRISTIAN', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8250857', 'CONDE', 'EDEN MARK', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8231383', 'CONDE', 'MARIE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8260609', 'CONDE', 'SHILOH', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8260396', 'CONDES', 'RYZA', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '1st Year', 'Regular', 1),
  ('8251069', 'CONDINO', 'CARL ESTELL', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8240916', 'CONDINO', 'RAZEL', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8250978', 'CONDRILLON', 'JACK JAY', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250789', 'COS', 'ARJIE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8251076', 'COYOCA', 'CEDRIX MAR', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250228', 'COYOCA', 'CRIS ANNE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8251132', 'COYOCA', 'JAZTINE JED', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250829', 'COYOCA', 'KRISTEL ANNE', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '2nd Year', 'Regular', 1),
  ('8250466', 'COYOCA', 'LHENIE ROSE', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '2nd Year', 'Regular', 1),
  ('8261989', 'COYOCA', 'ZHEDNEY', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8241172', 'CRISOSTOMO', 'JHON CLIFFORD', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8260805', 'CURARATON', 'ANGEL AMIER', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8261013', 'CUYOS', 'FRANCIS ARNEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8261931', 'CUYOS', 'KYLA ROSE', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '1st Year', 'Regular', 1),
  ('8241136', 'CUYOS', 'ROWIN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8250838', 'DAMAYO', 'KEZIAH', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '2nd Year', 'Regular', 1),
  ('8250834', 'DAMAYO', 'WESZALYN', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '2nd Year', 'Regular', 1),
  ('8251391', 'DANGATAW', 'MARNEL', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8250632', 'DAUBA', 'ALBERT', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8231968', 'DAYATA', 'VELMARIE', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '4th Year', 'Regular', 1),
  ('8261309', 'DE ARCA', 'JEA', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8231375', 'DE ARCA', 'SYDNEY', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8230580', 'DE JESUS', 'ALLAN JR.', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8230251', 'DEGAMO', 'REYX', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '4th Year', 'Regular', 1),
  ('8260663', 'DEJITO', 'CHEJE', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240248', 'DEJITO', 'JAMES', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240283', 'DEJITO', 'JOSEPH IAN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8262042', 'DEJITO', 'KRIS JANE', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8250468', 'DEJITO', 'ROCEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8261996', 'DEJITO', 'SHENA MAE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8261894', 'DEJITO', 'VINCE HARRY', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8241231', 'DELA CRUZ', 'JULY', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8260643', 'DELA RAMA', 'JOE BEN', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8250476', 'DELA TORRE', 'CRIS', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250690', 'DELIGERO', 'VEGI', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8252939', 'DELOS SANTOS', 'GERLY', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8252966', 'DELOS SANTOS', 'JINKY', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8250923', 'DEMIAR', 'MARY JOY', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8260514', 'DESABELLA', 'MERIEL MAE', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '1st Year', 'Regular', 1),
  ('8262025', 'DESABILLE', 'DANIES', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8240601', 'DIAMOS', 'JULIUS', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8241401', 'DIAZ', 'CYDNIE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8231072', 'DIAZ', 'JHONEL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8250772', 'DIEGO', 'JULLIA', NULL, 'BACHELOR OF SCIENCE IN FISHERIES', '2nd Year', 'Regular', 1),
  ('8260527', 'DOCTORA', 'MARIEL', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260976', 'DUAN JR.', 'JOEL', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240368', 'DUBA', 'STEPHEN CLIFFORD', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8240810', 'DUBLIN', 'MICHAEL XANDER', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8251408', 'DUBLIN', 'MIKHAILA', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8250438', 'DUCENA', 'EMMALEN', NULL, 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8261085', 'DUMAGUIT', 'MARK GIL', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8251100', 'DUNGOG', 'ERUNZ', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8231766', 'DUTERTE', 'JIMSON JAY', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '4th Year', 'Regular', 1),
  ('8251124', 'EDAR', 'JADE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8261059', 'ELEPTICO', 'NIÑA', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260749', 'ENCIO', 'MARIEL', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '1st Year', 'Regular', 1),
  ('8250625', 'ENTERO', 'ERIKA', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8251906', 'ESCALICAS', 'JIMLYN', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8261196', 'ESCALICAS', 'VEEJAY', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8261482', 'ESTOPA', 'FRANCIS', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8260791', 'ESTRERA', 'NIRESSA JOY', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '1st Year', 'Regular', 1),
  ('8250970', 'FORMALEJO', 'MICHELLE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8261453', 'GABAY', 'JEFFERSON', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8262064', 'GABRIEL', 'ALEXA', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8231458', 'GABRIEL', 'SANDARA JONES', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8251086', 'GALLARES', 'CHRISLYN', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250855', 'GARBO', 'CHARISSE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250935', 'GARBO', 'CHRISTINE MAE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8262005', 'GARIBAY', 'JAMES HAROLD', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8261634', 'GASTADOR', 'CHRISTIAN GEE YU', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240645', 'GASTADOR', 'RINAH JANE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8260591', 'GASTARDO', 'ERICA', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8260515', 'GAVIOLA', 'JOHN RUSELLE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8251010', 'GAYO', 'CRIS JOSHUA', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8261885', 'GEDOQUIO', 'MARY JANE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8250666', 'GELIG', 'RICHMON', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8250913', 'GENTAPA', 'CHARLENE', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8260534', 'GIDUCOS', 'ROWELYN', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8230144', 'GIDUCOS', 'SARAH JANE', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '4th Year', 'Regular', 1),
  ('8250122', 'GILBUENA', 'ADRIANNE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8261055', 'GIMEDA', 'LESLIE MAE', NULL, 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '1st Year', 'Regular', 1),
  ('8260657', 'GOLISAO', 'YVONNE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8252980', 'GOMEZ', 'CRISMARIE', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '2nd Year', 'Regular', 1),
  ('8231412', 'ICOT', 'ROBERT', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8261172', 'IGNARIO', 'KEITH LESTER', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260117', 'INITAN', 'CARLFE MAE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8260283', 'INOT', 'ANNA KARANINA', NULL, 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '1st Year', 'Regular', 1),
  ('8250501', 'INOT', 'JOBERT', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8260902', 'INOT', 'KAREN', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '1st Year', 'Regular', 1),
  ('8250629', 'INOT', 'MARY ERICKA', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8231085', 'INOT', 'RUSSEL SHEEN', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '4th Year', 'Regular', 1),
  ('8240339', 'INSO', 'ANGELO', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8231255', 'INSO', 'LORENZ', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN AUTOMOTIVE TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8240919', 'INSO', 'MAEKYLA JANE', NULL, 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8251415', 'JADRAQUE', 'JORENZ', NULL, 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN COMPUTER TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8260261', 'JAEL', 'MARLO', NULL, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '1st Year', 'Regular', 1),
  ('8242865', 'ABACIAL', 'ANGELA', 'S.', 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8242079', 'AMORANTE', 'JINKY', 'P.', 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8231136', 'ARCILLAS', 'YAN CARL', 'A.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8242607', 'BATIANCILA', 'JERICK', 'C.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8242166', 'BOYOSE', 'LORD CEDRIC', 'O.', 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8242567', 'CAMAY', 'MARY ANN', 'M.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8242851', 'CASUNGCAD', 'SHAZEEN', 'A.', 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8242378', 'COLIGADO', 'MIKYLLA', 'F.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8211835', 'CONDE', 'ROGINE', 'T.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '4th Year', 'Regular', 1),
  ('8221891', 'CORONEL', 'KRISTINE JANE', NULL, 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '4th Year', 'Regular', 1),
  ('8230858', 'CUAMBOT', 'ANNALOU', 'B.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '3rd Year', 'Regular', 1),
  ('8230469', 'DADOL', 'ANGELYN', 'S.', 'BACHELOR OF ELEMENTARY EDUCATION', '3rd Year', 'Regular', 1),
  ('8222287', 'DIACOSA', 'DALE PATRICK', 'M.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '4th Year', 'Regular', 1),
  ('8242096', 'DILAO', 'HANAH JANE', 'D.', 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8242687', 'DINSAY', 'ROVIC', 'N.', 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '2nd Year', 'Regular', 1),
  ('8231603', 'EJURANGO', 'JERICO', 'G.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8231628', 'GABUNADA', 'KENETH JAY', 'C.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8231205', 'GOMEZ', 'CHRISMILE', 'G.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8242864', 'INSO', 'KIMBERLY JANE', 'O.', 'BACHELOR OF ELEMENTARY EDUCATION', '2nd Year', 'Regular', 1),
  ('8211695', 'JAGDON', 'JOVA MAE', 'A.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '4th Year', 'Regular', 1),
  ('8230877', 'JEREZ', 'EVON', 'C.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '3rd Year', 'Regular', 1),
  ('8230980', 'LAYASAN', 'KATHERINE', 'E.', 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8230497', 'LAYOS', 'LEAH JASMINE', 'A.', 'BACHELOR OF ELEMENTARY EDUCATION', '3rd Year', 'Regular', 1),
  ('8230885', 'LOBITAÑA', 'LEECHAN', 'A.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '3rd Year', 'Regular', 1),
  ('8242497', 'LUNA', 'CHRISTIAN', 'R.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8231210', 'MANZO', 'MARK LEOPARD', 'S.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8230505', 'MAOY', 'MELODY', 'B.', 'BACHELOR OF ELEMENTARY EDUCATION', '3rd Year', 'Regular', 1),
  ('8242675', 'MORACA', 'RHEYNMARC', 'A.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8230901', 'OLIMBA', 'IVY JEAN', 'C.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '3rd Year', 'Regular', 1),
  ('8242882', 'OLIVAR', 'KHYN', 'C.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8231691', 'PABLE', 'OLIMAR', 'M.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8231014', 'PAHILINO', 'KISMETH ANGELIQUE', 'H.', 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '3rd Year', 'Regular', 1),
  ('8231701', 'PALIN', 'JAYSON', 'S.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8200780', 'RETUYA', 'REXTER JAMES', 'S.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8242630', 'REYES', 'CLARK JOSEPH', 'C.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8230917', 'SALVADOR', 'AYESA', 'E.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '3rd Year', 'Regular', 1),
  ('8231170', 'SANORIA', 'WINDEL', 'P.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '3rd Year', 'Regular', 1),
  ('8242514', 'SIBONGA', 'RAFAEL', 'A.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8242756', 'SILVANO', 'JAY', 'C.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8231739', 'SINADJAN', 'JOERA MAE', 'A.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '3rd Year', 'Regular', 1),
  ('8242133', 'SINGURAN', 'JIREHGRACE', 'P.', 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT', '2nd Year', 'Regular', 1),
  ('8242036', 'TAÑEZA JR.', 'ESTELITO', 'C.', 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '2nd Year', 'Regular', 1),
  ('8222012', 'TERANA', 'ANGEL', 'B.', 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '4th Year', 'Regular', 1),
  ('8242658', 'TRAZO', 'REX', 'E.', 'BACHELOR OF INDUSTRIAL TECHNOLOGY MAJOR IN ELECTRONICS TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8242040', 'URSAL', 'JAMES DARYL', 'M.', 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '2nd Year', 'Regular', 1),
  ('8230827', 'VALLES', 'MARK LOUIE', 'P.', 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS', '3rd Year', 'Regular', 1),
  ('8241485', 'ABAYON', 'SONNY', 'G.', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8221527', 'ABELLA', 'YVEN', 'C.', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '4th Year', 'Regular', 1),
  ('8230023', 'ALICE', 'GLYZLL', 'G.', 'BACHELOR IN TECHNOLOGY AND LIVELIHOOD EDUCATION MAJOR IN HOME ECONOMICS', '3rd Year', 'Regular', 1),
  ('8241407', 'ALINGARO', 'ALTHEA BLAIRE', 'O.', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '2nd Year', 'Regular', 1),
  ('8221386', 'AUMAN', 'CHRISTIAN JAY', 'R.', 'BACHELOR OF SCIENCE IN INDUSTRIAL ENGINEERING', '4th Year', 'Regular', 1),
  ('8231041', 'BATAC', 'BLAIRE', 'L.', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '3rd Year', 'Regular', 1)
ON DUPLICATE KEY UPDATE program = VALUES(program), year_level = VALUES(year_level);

