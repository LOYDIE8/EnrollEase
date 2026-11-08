# CTU Daanbantayan Campus Enrollment System

This is the Cebu Technological University – Daanbantayan Campus student enrollment appointment system. Its browser interface is served from the project directory; student and administrator actions are handled by PHP/PDO APIs.

## XAMPP setup

1. Put the project in `C:\xampp\htdocs\PC316v2`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel. Open the project using the port shown by Apache's `Listen` setting. This installation listens on port `8888`, so use `http://localhost:8888/PC316v2/`; a default XAMPP Apache installation commonly uses `http://localhost/PC316v2/`.
3. Configure the local database settings. Copy `config\database.example.php` to `config\database.php` if the local config is missing. Defaults are MySQL at `127.0.0.1:3306`, database `enrollment_system`, user `root`, and a blank password. The actual config is ignored by Git.
4. Open phpMyAdmin and import `database\schema.sql`. It creates/selects `enrollment_system` and creates the complete schema and example enrollment schedules. **Fresh setup = import `database/schema.sql` (creates `enrollment_system`), then optionally `database/enrollment_system.sql` for test students.** The fixture depends on the schema and must be imported second.
5. To rebuild a disposable development database, import the fresh schema and its fixtures in the order above. Do not drop a database containing records you need to preserve. Do not import `database/enrollment-appointments-migration.sql` or `database/shared-admin-migration.sql` into a fresh database created from `schema.sql`.
6. For an existing database, back it up and inspect its student records first. If it has the older student schema, run `database/migrations/20261106_align_student_registration_schema.sql`, then apply `database/migrations/20261106_daanbantayan_student_management.sql` to add the Daanbantayan campus relationship, account status, year-level constraint, and admin audit log while preserving students and appointments. Apply `database/migrations/20261106_create_enrolled_student_registry.sql` if the registry table is missing, then `database/migrations/20261108_accept_lrn_student_numbers.sql` to allow both 7-digit CTU IDs beginning with `824` and 12-digit LRNs. Apply `database/migrations/20261106_create_auth_rate_limits.sql` as well if rate limiting is not already configured. These migrations do not drop or recreate the database.
7. Open `http://localhost:8888/PC316v2/api/student/session.php` in a browser (replace `8888` if Apache uses another port). Once the schema is imported, without a student session it should return JSON containing `"Student sign-in is required."`. If it shows a PHP warning about `config/database.php`, create that file from `config/database.example.php`; if it reports an unknown database, import `database/schema.sql`.
8. Create the shared administrator account from PowerShell:

   ```powershell
   $env:ADMIN_INITIAL_PASSWORD = 'replace-with-a-unique-strong-password'
   & 'C:\xampp\php\php.exe' database\create-first-admin.php
   Remove-Item Env:\ADMIN_INITIAL_PASSWORD
   ```

   Follow the prompts. The shared administrator signs in at `admin-login/admin-login.html`.

### Optional email support

Booking works without email; PHPMailer and SMTP are optional. If confirmation emails are wanted, copy `config\mail.example.php` to the ignored `config\mail.php`, run `composer install` from the project directory (or use `C:\xampp\php\php.exe C:\path\to\composer.phar install`), and configure the SMTP environment variables described in `ENROLLMENT-XAMPP-SETUP.md`. No SMTP credentials are required for booking.

### Optional local diagnostics

Copy `config\app.example.php` to the ignored `config\app.php` and set `debug` to `true` only on a local development instance. Student API 503 responses then include the caught exception message as a `detail` field. The default and production setting is `false`; never enable this on a public server.

## Local test accounts

Import `database/enrollment_system.sql` after `database/schema.sql`. All fixture accounts use the local-only password `Local-Enrollment-Test-2026!`.

| Student number | Expected eligibility result |
|---|---|
| `8240000` | Qualified; eligible for a booking when the assigned schedule is active. |
| `8240001` | Blocked by missing/incomplete grades (IT101 and IT312). |
| `8240002` | Blocked by an outstanding ₱3,500 balance. |
| `8240003` | Blocked by a missing required document and unresolved Transcript of Records requirement. |
| `8240004` | Blocked by an active enrollment hold. |

These are local test accounts only. Their `@example.com` addresses are placeholders.

For a locally registered student that needs to be made eligible for testing, edit the student number in the final `CALL` in `database/dev-make-student-eligible.sql` and import/run that script after `database/schema.sql`. It deliberately marks grades complete, zeroes local balances, releases enrollment holds, resolves blocking requirements, and verifies all required documents. It is local-testing-only and must never be run against production or shared data.

## Schedules and appointments

Year-level enrollment weeks and example appointment slots are seeded by `database/schema.sql`. Edit schedules through the shared administrator dashboard. Student booking rechecks eligibility, assigned-week dates, daily and slot capacity, and duplicate appointments in the PHP API.

Students can reschedule or cancel active enrollment appointments from **My Schedule**. Rescheduling rechecks eligibility, the assigned week, date/time availability, and capacity; both actions update appointment status history and record email notification results. Email remains optional.

Appointments are committed before email is attempted. A missing PHPMailer installation or SMTP configuration produces an explicit email warning, but does not undo a successful booking.

The administrator dashboard includes **Student Management** for authorized main administrators: search and filter campus students, add and edit accounts, activate or deactivate accounts, and inspect audit logs. Student identifiers may be 7-digit CTU IDs beginning with `824` or 12-digit LRNs; year level determines the assigned enrollment week and can be added by an administrator after registration. Role-specific administrator pages and APIs check stored role permissions and department assignments. **Student eligibility records** remain available to administrators with student-update permission; changes continue to use a transaction and are recorded in the existing activity log.

Rate limiting uses the database-backed `auth_rate_limits` table in the fresh schema. For an existing database, apply `database/migrations/20261106_create_auth_rate_limits.sql`. Login and registration requests are limited by both the client IP address and the normalized login identifier; retry responses include a `Retry-After` header and `retryAfter` seconds.

The browser stores the student CSRF token and display-session key in `sessionStorage` only. Both keys are removed from `localStorage` and `sessionStorage` on any student API `401` response and on logout.

## Recent Updates (November 2026)

- **Year Level Canonical Format**: Year levels are canonicalized to `1st Year`, `2nd Year`, `3rd Year`, and `4th Year`. Applied via `database/migrations/20261106_normalize_student_year_levels.sql`.
- **Shared Admin & Admin Accounts Manager**: Removed legacy role-specific login stubs; all admins log in through `admin-login/admin-login.html`. Main administrators can manage department administrator accounts directly in the admin dashboard under **Admin accounts**.
- **Academic Status**: Students now have an `academic_status` (Regular, Irregular, Returnee, Transferee, Shiftee, New Student) displayed on their profile/enrollment details and editable in Student Management.
- **Enrolled Student Registry & CSV Import**: Validates self-registering students against the official enrolled registry when `require_registry_match` is enabled. Administrators can upload/import enrolled student CSV lists via `api/admin/registry.php` and the Student Management panel. Schema defined in `database/migrations/20261106_create_enrolled_student_registry.sql`.
- **Password Policy**: Student and administrator passwords enforce 8–72 characters with at least one letter and one number across all registration, management, and change-password forms.
- **Email Notifications**: Booking, rescheduling, and cancellation now cleanly report when email notifications are not enabled without producing false delivery warning errors.
- **Eligibility Settings & Item Removal**: Administrators can configure allowed academic statuses, manage document types, and remove individual eligibility items (subjects, balances, holds, requirements) with full audit logging.
- **Booking Window Clarity**: Clear messaging when booking has not opened yet (`Booking opens on <date>`), when it has closed, or when an appointment date falls outside the student's assigned week.
