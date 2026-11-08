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
  year_level ENUM('First Year', 'Second Year', 'Third Year', 'Fourth Year') NOT NULL,
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
    (year_level = 'Fourth Year' AND week_number = 4)
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
  program VARCHAR(150) NOT NULL,
  year_level VARCHAR(40) NOT NULL,
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
  ('Fourth Year', 4, '2026-11-30', '2026-12-04', 'Active');

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
