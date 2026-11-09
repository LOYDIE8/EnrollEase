# EnrollEase enrollment appointment setup (XAMPP)

This integrates enrollment appointments into the existing EnrollEase pages and shared PHP authentication. It does not replace the site's page shell, navigation, or design.

## 1. Start XAMPP and open the project

1. Open the XAMPP Control Panel.
2. Start **Apache** and **MySQL**.
3. Keep the project under `C:\xampp\htdocs\EnrollEase`.
4. Open the URL matching Apache's `Listen` port. This computer's XAMPP is configured for port `8888`, so open `http://localhost:8888/EnrollEase/landing.html`. If your Apache uses the default port `80`, open `http://localhost/EnrollEase/landing.html`.

The project uses PHP, Apache, and MySQL/MariaDB only; it does not require Node.js.

## 2. Create or reuse the database

### Fresh local installation

1. Open `http://localhost/phpmyadmin`.
2. Select **Import** from the top-level page.
3. Choose `database/schema.sql` and import it. The script creates the `enrollment_system` database, tables, foreign keys, eligibility settings, example schedules, and default appointment times.
4. Select the `enrollment_system` database, choose **Import**, and import `database/enrollment_system.sql` to add five local test students.

`database/schema.sql` is the canonical full schema. It accepts both 7-digit CTU student IDs beginning with `824` and 12-digit LRNs. `database/enrollment_system.sql` is only a local test-data fixture; it is not a second schema.

The example schedule dates are November 9–December 4, 2026. They are editable in the shared admin dashboard and are not hard-coded in PHP.

### Existing installation

Do not import the full schema over an existing database. Back up the database first and confirm existing student IDs are either 7-digit CTU IDs beginning with `824` or 12-digit LRNs, are unique, and have supported year-level values. Then bring the existing database up to date in this order:

1. Apply `database/enrollment-appointments-migration.sql` if the database does not already have the enrollment appointment schema.
2. Apply `database/shared-admin-migration.sql` only if the existing admin setup needs that migration.
3. If the student table is still on the older registration schema, apply `database/migrations/20261106_align_student_registration_schema.sql` after verifying the existing Student IDs.
4. Import `database/migrations/20261106_daanbantayan_student_management.sql` into the existing database. It assigns existing student records to Daanbantayan Campus and adds student status and the admin audit log without replacing student or appointment records.
5. Apply `database/migrations/20261106_create_enrolled_student_registry.sql` if the registry table does not exist.
6. Apply `database/migrations/20261108_accept_lrn_student_numbers.sql` to allow 12-digit LRNs alongside the existing CTU IDs.
7. Apply `database/migrations/20261106_create_auth_rate_limits.sql` if the database does not already have the rate-limit table.

The SQL migrations default to `USE enrollment_system;`. If the existing database has a different name, change the relevant `USE` line and configure `DB_NAME` to the same database. Import migrations through phpMyAdmin's **Import** tab after selecting the intended database. Do not copy production data into the local test fixture.

## 3. Configure the database connection

`config/database.php` defaults to:

- Host: `127.0.0.1`
- Database: `enrollment_system`
- User: `root`
- Password: blank
- Port: `3306`

These are common XAMPP defaults. If your installation differs, set the `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_PORT` environment variables for Apache. For local Apache, put the settings in `C:\xampp\apache\conf\httpd.conf` (or the applicable virtual-host config), then restart Apache:

```apache
SetEnv DB_HOST 127.0.0.1
SetEnv DB_NAME enrollment_system
SetEnv DB_USER root
SetEnv DB_PORT 3306
```

Leave `DB_PASSWORD` unset only if the local MySQL `root` account truly has a blank password. Do not use a blank root password on an internet-facing server.

If `config/database.php` is missing, copy `config/database.example.php` to `config/database.php`. The runtime file is intentionally ignored by Git and must exist in the XAMPP-served project directory. Verify the setup by opening `http://localhost:8888/EnrollEase/api/student/session.php` (replace `8888` with Apache's configured port); after importing the schema, an unauthenticated request should return JSON saying `Student sign-in is required.` A missing-config PHP warning means the runtime file is absent; a database connection error means the configured host, port, user, password, or database name is incorrect.

## 4. Install PHPMailer

Install Composer for Windows and select `C:\xampp\php\php.exe` as its PHP interpreter. From PowerShell or Command Prompt, run:

```powershell
cd C:\xampp\htdocs\EnrollEase
composer install
```

The project manifest installs PHPMailer. The generated `vendor` directory is ignored by Git and must be installed on each deployment.

## 5. Configure SMTP

SMTP settings are read only by PHP from `config/mail.php`; they are not sent to the browser. Add these Apache environment variables to the same server configuration, substitute the values from your mail provider, and restart Apache:

```apache
SetEnv SMTP_HOST smtp.example.com
SetEnv SMTP_PORT 587
SetEnv SMTP_USERNAME enrollment@example.com
SetEnv SMTP_PASSWORD replace-with-provider-password
SetEnv SMTP_FROM_EMAIL enrollment@example.com
SetEnv SMTP_FROM_NAME EnrollEase
```

Use the SMTP credentials or application password from your mail provider. Port `587` uses STARTTLS; port `465` uses implicit TLS. Never put real SMTP credentials in HTML, JavaScript, or a committed file.

The appointment is committed to MySQL before email is attempted. If SMTP fails, the booking remains saved and the student sees an explicit email-delivery warning; failed bookings never send confirmation messages.

## 6. Create the one shared admin account

From the project directory, set a strong initial password and run the existing CLI-only creator:

```powershell
$env:ADMIN_INITIAL_PASSWORD = 'replace-with-a-unique-strong-password'
& 'C:\xampp\php\php.exe' database\create-first-admin.php
Remove-Item Env:\ADMIN_INITIAL_PASSWORD
```

Follow the prompts for the shared username, email, first name, and last name. The password is stored using `password_hash()`. Admin authentication uses `password_verify()`. All admins use the same `admin-login/admin-login.html` login and `admin/dashboard.php`; older department-specific login URLs redirect to this shared login.

## 7. Student IDs, year level, and eligibility

- The system is for CTU Daanbantayan Campus. Registration fixes the campus to Daanbantayan Campus; the server does not accept a different campus from the browser.
- Students enter either a 7-digit CTU Student ID beginning with `824` or a 12-digit LRN during registration; the identifier must be unique.
- Year level is not collected during registration. An authorized admin must add it before the student can book, because year level maps to the enrollment week: 1st Year = Week 1, 2nd Year = Week 2, 3rd Year = Week 3, and 4th Year = Week 4.
- The server checks that the current date and the requested appointment date are both within the student's assigned enrollment week. It enforces a maximum of 150 active appointments per day.
- Authorized admins can view, add, edit, activate, and deactivate student accounts. Changing a student's year level updates the assigned week automatically; student changes are recorded in the admin audit log. Admin access is enforced by backend permissions and department scope.
- An inactive student, unsupported year level, or unresolved enrollment requirement blocks booking.
- The eligibility service checks active status, recognized year level, grades/deficiencies, outstanding balances, required documents, active holds, and configured enrollment-blocking requirements. Those checks cannot be disabled in the admin UI.
- PHP endpoints repeat authentication, campus, eligibility, assigned-week, past-date, duplicate-appointment, slot, and daily-capacity checks when booking or rescheduling; client-side validation is not trusted.

## 8. Test accounts and test steps

The test fixture creates:

| Student ID | Year | Test condition |
|---|---|---|
| 8240000 | First Year | Qualified |
| 8240001 | First Year | Missing and incomplete grades |
| 8240002 | Second Year | ₱3,500 outstanding balance |
| 8240003 | Third Year | Missing document and unresolved requirement |
| 8240004 | Fourth Year | Active enrollment hold |

All five fixture accounts use the local-only password `Local-Enrollment-Test-2026!`. Their `@example.com` addresses are placeholders; replace the qualified student's email with a real test inbox before testing delivery.

Using the initial example schedules (verify they are still future dates):

1. **Qualified Week 1 booking:** Sign in as 8240000 and book a date/time from November 9–13, 2026. Expect a saved appointment and a reference such as `ENR-2026-0001`.
2. **Wrong year week:** Before 8240002 is otherwise made eligible, update their balance to zero and payment status to `paid`. As this Second Year student, attempt November 9 (Week 1). Expect the backend to reject it as outside the assigned week.
3. **Missing grade:** Sign in as 8240001. Expect booking to be blocked with the affected subjects listed.
4. **Incomplete grade:** In phpMyAdmin, change 8240001's IT101 grade to complete; IT312 remains incomplete. Reload the eligibility page and verify that IT312 still blocks booking.
5. **Outstanding balance:** Sign in as 8240002 before clearing the balance. Expect the ₱3,500 balance to be shown and booking blocked.
6. **Missing requirement/document:** Sign in as 8240003. Expect the missing enrollment document and unresolved requirement to be shown.
7. **Active hold:** Sign in as 8240004. Expect the active hold reason to be shown.
8. **Available date:** Sign in as 8240000 and book any open time on a date in their Week 1. The booking should save once; their second active booking must be rejected.
9–11. **Daily capacity and concurrent final booking:** For an isolated local test, use a weekday in the First Year schedule (for example November 10) and add 149 active appointment rows via phpMyAdmin using the SQL below. It deliberately reuses the qualified fixture student's ID and is only for the local capacity test. Qualify 8240001 first and make sure it has no active appointment. Its next booking on November 10 should bring the daily total to 150. Then qualify 8240002 and submit the same date: it must fail as full. To test the race, instead populate November 11 to 149 and submit the final booking at the same time from two eligible accounts in separate browsers; exactly one request should succeed. The shared `enrollment_daily_capacity` row serializes all times for that date.

```sql
INSERT INTO appointments
  (public_id, reference_number, student_id, department_id, appointment_status_id,
   appointment_type, scheduled_at, created_by)
SELECT UUID(), CONCAT('CAP-', REPLACE(UUID(), '-', '')), 8240000,
       departments.id, statuses.id, 'Enrollment Appointment',
       '2026-11-10 08:00:00', users.id
FROM (
  SELECT hundreds.n * 100 + tens.n * 10 + ones.n + 1 AS n
  FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) hundreds
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) tens
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) ones
) numbers
JOIN departments ON departments.department_code = 'Registrar'
JOIN appointment_status AS statuses ON statuses.status_code = 'pending'
JOIN users ON users.email = 'enrollment-test-8240000@example.com'
WHERE numbers.n <= 149;
```

To clear only these test capacity rows:

```sql
DELETE FROM appointments WHERE reference_number LIKE 'CAP-%';
```

12. **Manual outside-week request:** While signed in as an eligible First Year student, use the browser console to submit a date in Week 2. Replace the CSRF token lookup if your browser blocks storage access:

```js
fetch('api/student/book.php', {
  method: 'POST',
  credentials: 'same-origin',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': sessionStorage.getItem('ee_csrf') || localStorage.getItem('ee_csrf')
  },
  body: JSON.stringify({ date: '2026-11-16', time: '09:00' })
}).then(response => response.json()).then(console.log);
```

Expect rejection with “This date is outside your assigned enrollment week.”
13. **Duplicate appointment:** With a Pending, Confirmed, or Rescheduled appointment already active, attempt another. Expect “You already have an active enrollment appointment.”
14. **Cancellation frees capacity:** Cancel an appointment from the admin dashboard and refresh the capacity page. The daily active count should decrease by one.
15. **Confirmation email:** Configure SMTP and a real inbox, book successfully, and confirm the email includes student ID, name, year, week dates, appointment time, and reference number.
16. **Update emails:** Confirm or reschedule/cancel from the admin dashboard. Verify the changed appointment details are emailed.

## 9. Admin pages

- `admin/dashboard.php`: search by name/student ID/reference, filter by year level/status/date, review eligibility details, and update appointment status.
- `admin/schedules.php`: add/edit/activate schedules, view per-day `active / 150` capacity, and add/close appointment times.
- Cancelled and completed appointments do not consume the daily active capacity; Pending, Confirmed, and Rescheduled appointments do.

## 10. Files added or changed in this integration

**Added:** `.gitignore`, `composer.json`, `config/database.php`, `config/mail.php`, `api/enrollment-booking.php`, `admin/schedules.php`, `ENROLLMENT-XAMPP-SETUP.md`.

**Updated:** `database/schema.sql`, `database/enrollment_system.sql`, `database/enrollment-appointments-migration.sql`, `database/shared-admin-migration.sql`, `api/admin/database.php`, `api/enrollment-eligibility.php`, `api/student/common.php`, `api/student/register.php`, `api/student/eligibility.php`, `api/student/slots.php`, `api/student/book.php`, `admin/dashboard.php`, `register.html`, `js/student-auth.js`, and `js/script.js`.
