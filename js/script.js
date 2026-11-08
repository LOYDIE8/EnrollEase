/* =====================================================================
   EnrollEase - script.js
   Every HTML page is an empty shell: <body data-page="login">.
   This file looks at data-page and draws that page's content.

   HOW TO EDIT:
   - Enrollment eligibility and available appointment slots are managed by the backend.
   - Each page lives in section 6 as PAGES.<name> with two functions:
       render() -> returns the page's HTML
       setup()  -> attaches button clicks and form logic after drawing
   - Student accounts and enrollment appointments are verified by the PHP API.
   - localStorage is only used for display preferences and a profile cache.
   ===================================================================== */


/* ---------- 1. SETTINGS ---------- */
const PAGES_WITHOUT_LOGIN = ['index', 'login', 'register'];
const currentPage = document.body.dataset.page;


/* ---------- 2. SAVING AND LOADING DATA ---------- */
function loadData(key, fallback) {
  try {
    const text = localStorage.getItem(key);
    return text ? JSON.parse(text) : fallback;
  } catch (error) {
    return fallback;
  }
}
function saveData(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

function clearStudentSession() {
  for (const storage of [localStorage, sessionStorage]) {
    storage.removeItem('ee_session');
    storage.removeItem('ee_csrf');
  }
}

function initializeStudentSessionStorage() {
  if (!sessionStorage.getItem('ee_session') && localStorage.getItem('ee_session')) {
    sessionStorage.setItem('ee_session', localStorage.getItem('ee_session'));
  }
  if (!sessionStorage.getItem('ee_csrf') && localStorage.getItem('ee_csrf')) {
    sessionStorage.setItem('ee_csrf', localStorage.getItem('ee_csrf'));
  }
  localStorage.removeItem('ee_session');
  localStorage.removeItem('ee_csrf');
}

initializeStudentSessionStorage();

async function studentFetch(resource, options) {
  const response = await fetch(resource, options);
  if (response.status === 401) clearStudentSession();
  return response;
}

// Dark / light mode
document.documentElement.dataset.theme = loadData('ee_theme', 'light');

const savedProfiles = loadData('ee_users', []);
if (Array.isArray(savedProfiles) && savedProfiles.some(user =>
  user && typeof user === 'object' && Object.prototype.hasOwnProperty.call(user, 'password')
)) {
  saveData('ee_users', savedProfiles.map(user => {
    if (!user || typeof user !== 'object') return user;
    const { password, ...profile } = user;
    return profile;
  }));
  clearStudentSession();
}

function allUsers()        { return loadData('ee_users', []); }
function allAppointments() { return loadData('ee_appointments', []); }
function currentUser()     { return allUsers().find(u => u.sid === sessionStorage.getItem('ee_session')); }
function myAppointments()  {
  return allAppointments().filter(a => a.sid === sessionStorage.getItem('ee_session'));
}


/* ---------- 3. SMALL HELPERS ---------- */
const select = css => document.querySelector(css);

const studentInitials = name => (name || 'Student').trim().split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase();

function getCurriculumSubjects(program = '', year = '', section = '') {
  const prog = (program || '').toLowerCase();
  const yr = (year || '').toLowerCase();
  const sec = section || '';

  if (prog.includes('computer science') || prog.includes('bscs')) {
    return [
      { code: 'CS 211', title: 'Design and Analysis of Algorithms', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 09:00 AM - 10:30 AM', room: 'CS Lab 1', instructor: 'Prof. A. Gonzales' },
      { code: 'CS 212', title: 'Automata and Formal Language Theory', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'TTH 01:00 PM - 02:30 PM', room: 'Room 401', instructor: 'Prof. R. Mendoza' },
      { code: 'CS 213', title: 'Computer Organization & Architecture', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 01:00 PM - 02:30 PM', room: 'CS Lab 4', instructor: 'Prof. L. Alcantara' },
      { code: 'MATH 201', title: 'Linear Algebra & Differential Calculus', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'TTH 08:30 AM - 10:00 AM', room: 'Room 205', instructor: 'Prof. D. Garcia' },
      { code: 'GE 108', title: 'Science, Technology, and Society', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'Sat 08:00 AM - 11:00 AM', room: 'Room 102', instructor: 'Prof. E. Morales' },
      { code: 'PE 3', title: 'Physical Activities Toward Health & Fitness', lec: 2.0, lab: 0.0, units: 2.0, schedule: 'Fri 03:00 PM - 05:00 PM', room: 'University Gym', instructor: 'Coach C. Perez' }
    ];
  }

  if (prog.includes('hospitality') || prog.includes('bshm')) {
    return [
      { code: 'HM 211', title: 'Front Office Operations & Management', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 08:30 AM - 10:00 AM', room: 'HM Front Desk Lab', instructor: 'Chef R. Santos' },
      { code: 'HM 212', title: 'Food & Beverage Service Procedures', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'TTH 01:00 PM - 03:00 PM', room: 'Dining Hall Lab', instructor: 'Prof. J. Navarro' },
      { code: 'HM 213', title: 'Culinary Arts & Professional Kitchen Work', lec: 1.0, lab: 6.0, units: 3.0, schedule: 'MWF 01:00 PM - 04:00 PM', room: 'Commercial Kitchen', instructor: 'Chef M. Cruz' },
      { code: 'HM 214', title: 'Tourism Planning & Sustainable Development', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'TTH 09:00 AM - 10:30 AM', room: 'Room 302', instructor: 'Prof. C. Flores' },
      { code: 'GE 105', title: 'Purposive Communication in Hospitality', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'Sat 08:00 AM - 11:00 AM', room: 'Room 104', instructor: 'Prof. A. Ramos' },
      { code: 'PE 3', title: 'Physical Education 3 (Folk Dance & Fitness)', lec: 2.0, lab: 0.0, units: 2.0, schedule: 'Fri 03:00 PM - 05:00 PM', room: 'University Gym', instructor: 'Coach C. Perez' }
    ];
  }

  if (prog.includes('industrial') || prog.includes('bit')) {
    return [
      { code: 'BIT 211', title: 'Industrial Materials Testing & Metallurgy', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 08:30 AM - 10:00 AM', room: 'Mech Tech Lab', instructor: 'Engr. D. Tolentino' },
      { code: 'BIT 212', title: 'Computer-Aided Drafting & Design (CADD)', lec: 1.0, lab: 6.0, units: 3.0, schedule: 'TTH 01:00 PM - 03:00 PM', room: 'CAD/CAM Lab', instructor: 'Engr. J. Mercado' },
      { code: 'BIT 213', title: 'Occupational Safety, Health & Environmental Law', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'TTH 08:30 AM - 10:00 AM', room: 'Room 203', instructor: 'Engr. S. Lopez' },
      { code: 'BIT 214', title: 'Industrial Electronics & Motor Controls', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 01:00 PM - 02:30 PM', room: 'Electrical Lab', instructor: 'Engr. B. Castro' },
      { code: 'GE 106', title: 'The Contemporary World', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'Sat 08:00 AM - 11:00 AM', room: 'Room 202', instructor: 'Prof. V. Bautista' },
      { code: 'PE 3', title: 'Physical Education 3 (Recreational Activities)', lec: 2.0, lab: 0.0, units: 2.0, schedule: 'Fri 03:00 PM - 05:00 PM', room: 'University Gym', instructor: 'Coach C. Perez' }
    ];
  }

  if (prog.includes('education') || prog.includes('coed') || prog.includes('bsed')) {
    return [
      { code: 'ED 211', title: 'The Child and Adolescent Learners & Principles', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'MWF 08:30 AM - 10:00 AM', room: 'Education Hall 1', instructor: 'Dr. F. Manalo' },
      { code: 'ED 212', title: 'Facilitating Learner-Centered Teaching', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'TTH 01:00 PM - 02:30 PM', room: 'Room 102', instructor: 'Dr. G. Aquino' },
      { code: 'ED 213', title: 'Assessment in Learning 1', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'MWF 01:00 PM - 02:30 PM', room: 'Room 103', instructor: 'Prof. T. Soriano' },
      { code: 'ED 214', title: 'The Teaching Profession & School Culture', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'TTH 08:30 AM - 10:00 AM', room: 'Room 104', instructor: 'Dr. E. Hernandez' },
      { code: 'GE 107', title: 'Art Appreciation', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'Sat 08:00 AM - 11:00 AM', room: 'Room 205', instructor: 'Prof. K. Miranda' },
      { code: 'PE 3', title: 'Physical Education 3 (Rhythmic Activities)', lec: 2.0, lab: 0.0, units: 2.0, schedule: 'Fri 03:00 PM - 05:00 PM', room: 'University Gym', instructor: 'Coach C. Perez' }
    ];
  }

  // Default: BS Information Technology (2nd Year by default or based on year)
  if (yr.includes('1st')) {
    return [
      { code: 'CC 101', title: 'Introduction to Computing', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 08:00 AM - 09:30 AM', room: 'Lab 1', instructor: 'Prof. N. Lim' },
      { code: 'CC 102', title: 'Fundamentals of Programming', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'TTH 09:00 AM - 10:30 AM', room: 'Lab 2', instructor: 'Prof. M. Santos' },
      { code: 'MATH 101', title: 'Discrete Mathematics', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'MWF 10:00 AM - 11:00 AM', room: 'Room 202', instructor: 'Prof. D. Garcia' },
      { code: 'GE 101', title: 'Understanding the Self', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'TTH 01:00 PM - 02:30 PM', room: 'Room 203', instructor: 'Prof. L. Cruz' },
      { code: 'GE 102', title: 'Readings in Philippine History', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'Sat 08:00 AM - 11:00 AM', room: 'Room 101', instructor: 'Prof. R. Morales' },
      { code: 'NSTP 1', title: 'National Service Training Program 1', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'Sun 08:00 AM - 11:00 AM', room: 'Grandstand', instructor: 'Lt. B. Villanueva' },
      { code: 'PE 1', title: 'Movement Competency Training', lec: 2.0, lab: 0.0, units: 2.0, schedule: 'Fri 01:00 PM - 03:00 PM', room: 'Gymnasium', instructor: 'Coach C. Perez' }
    ];
  }

  // Default BSIT 2nd Year (Sections 2-A, 2-B, etc.)
  return [
    { code: 'P ELEC 2', title: 'Web Systems and Technologies', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 09:00 AM - 10:30 AM', room: 'Lab 3', instructor: 'Prof. E. Villanueva' },
    { code: 'IT 211', title: 'Data Structures and Algorithms', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'TTH 01:00 PM - 02:30 PM', room: 'Lab 1', instructor: 'Prof. M. Aranas' },
    { code: 'IT 212', title: 'Advanced Database Management Systems', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'MWF 01:00 PM - 02:30 PM', room: 'Lab 2', instructor: 'Prof. R. Dizon' },
    { code: 'IT 213', title: 'Object-Oriented Programming 2', lec: 2.0, lab: 3.0, units: 3.0, schedule: 'TTH 08:30 AM - 10:00 AM', room: 'Lab 3', instructor: 'Prof. K. Tan' },
    { code: 'IT 214', title: 'Quantitative Methods & Analytics', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'Sat 08:00 AM - 11:00 AM', room: 'Room 304', instructor: 'Prof. J. Reyes' },
    { code: 'GE 104', title: 'Mathematics in the Modern World', lec: 3.0, lab: 0.0, units: 3.0, schedule: 'MWF 10:30 AM - 11:30 AM', room: 'Room 201', instructor: 'Prof. S. Ramos' },
    { code: 'PE 3', title: 'Physical Activities Toward Health & Fitness', lec: 2.0, lab: 0.0, units: 2.0, schedule: 'Fri 03:00 PM - 05:00 PM', room: 'University Gym', instructor: 'Coach C. Perez' }
  ];
}

function goTo(pageName) { location.href = pageName + '.html'; }

// Show a message on the next page (used after login/logout redirects).
function flashMessage(text) { sessionStorage.setItem('ee_flash', text); }

// Today as "YYYY-MM-DD" (the format of <input type="date">).
function todayString() {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  return now.toISOString().slice(0, 10);
}

// "2026-10-14" -> "Wed, Oct 14, 2026"
function formatDate(dateString) {
  return new Date(dateString + 'T00:00').toLocaleDateString('en-US', {
    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
  });
}

// Real Date object of an appointment (needed to know if it is in the past).
function appointmentDateTime(appt) {
  const time = /^(\d{1,2}):(\d{2})\s*(AM|PM)$/i.exec(appt.time);
  if (!time) return new Date(NaN);
  const hour12 = Number(time[1]) % 12;
  const hour24 = hour12 + (time[3].toUpperCase() === 'PM' ? 12 : 0);
  return new Date(appt.date + 'T' + String(hour24).padStart(2, '0') + ':' + time[2] + ':00');
}

function isPast(appt) {
  return appt.status === 'Completed' || appointmentDateTime(appt) < new Date();
}

// Stops user text from being treated as HTML.
function escapeHtml(text) {
  return String(text).replace(/[&<>"]/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

// Small message in the top-right corner.
function showToast(message, isError) {
  const toast = document.createElement('div');
  toast.className = 'toast' + (isError ? ' e' : '');
  toast.textContent = message;
  document.body.append(toast);
  setTimeout(() => toast.remove(), 3200);
}

// Pop-up window. Returns the element so you can attach buttons inside it.
function showModal(html) {
  const overlay = document.createElement('div');
  overlay.className = 'modal';
  overlay.innerHTML = '<div class="card">' + html + '</div>';
  overlay.onclick = event => { if (event.target === overlay) overlay.remove(); };
  document.body.append(overlay);
  return overlay;
}

function logout() {
  studentFetch('api/student/logout.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': sessionStorage.getItem('ee_csrf') || '' }
  }).catch(() => {
    showToast('Could not contact the server to end your session.', true);
  }).finally(() => {
    clearStudentSession();
    flashMessage('You have been logged out.');
    goTo('login');
  });
}

function confirmLogout() {
  const modal = showModal(`
    <div style="text-align: center; padding: 12px 6px 8px;">
      <div style="width: 52px; height: 52px; margin: 0 auto 16px; border-radius: 50%; background: rgba(239, 68, 68, 0.12); color: #ef4444; display: flex; align-items: center; justify-content: center;">
        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
          <polyline points="16 17 21 12 16 7"></polyline>
          <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
      </div>
      <h3 style="margin: 0 0 8px 0; font-size: 19px; font-weight: 700; color: var(--tx);">Log out of your account?</h3>
      <p class="muted" style="margin: 0 0 24px 0; font-size: 13.5px; line-height: 1.5;">Are you sure you want to end your current session? You will need to sign in again to access appointments and records.</p>
      <div class="row" style="gap: 12px; justify-content: center;">
        <button type="button" class="btn alt" id="cancelLogoutModalBtn" style="flex: 1; height: 42px; font-size: 14px; font-weight: 600;">Cancel</button>
        <button type="button" class="btn" id="confirmLogoutModalBtn" style="flex: 1; height: 42px; font-size: 14px; font-weight: 600; background: #dc2626; border-color: #dc2626; color: #fff;">Log Out</button>
      </div>
    </div>
  `);

  modal.querySelector('#cancelLogoutModalBtn').onclick = () => modal.remove();
  modal.querySelector('#confirmLogoutModalBtn').onclick = () => {
    modal.remove();
    logout();
  };
}


/* ---------- 4. FORM HELPERS ---------- */
// A label + input + empty red error line.
function inputField(id, label, type = 'text', value = '', extra = '') {
  return `
    <label for="${id}">${label}</label>
    <input id="${id}" type="${type}" value="${escapeHtml(value)}" ${extra}>
    <div class="err" id="error-${id}"></div>`;
}

// Put a red message under a field. Always returns false (= "not valid").
function showError(fieldId, message) {
  const errorId = 'error-' + fieldId;
  const errorElement = select('#' + errorId);
  if (errorElement) {
    errorElement.textContent = message;
  } else {
    console.warn('Missing error element #' + errorId);
    const field = select('#' + fieldId);
    const fallback = document.createElement('div');
    fallback.className = 'err';
    fallback.setAttribute('role', 'alert');
    fallback.dataset.errorFallback = fieldId;
    fallback.textContent = message;
    const anchor = field && field.closest('.input-box, .input-group');
    if (anchor) {
      anchor.insertAdjacentElement('afterend', fallback);
    } else {
      const form = field && field.closest('form');
      (form || select('#app') || document.body).prepend(fallback);
    }
  }
  return false;
}

function clearErrors() {
  document.querySelectorAll('.err, .error').forEach(e => e.textContent = '');
  document.querySelectorAll('[data-error-fallback]').forEach(e => e.remove());
}

// Label/value list used on summary, details and enrollment pages.
function detailsList(items) {
  const rows = Object.entries(items)
    .map(([label, value]) => `<dt>${label}</dt><dd>${escapeHtml(value)}</dd>`)
    .join('');
  return '<dl>' + rows + '</dl>';
}


/* ---------- 5. APPOINTMENT LOGIC ---------- */
function generateBarcodeSvg(code) {
  const bars = [];
  let x = 12;
  const str = String(code || '');
  for (let i = 0; i < str.length; i++) {
    const c = str.charCodeAt(i);
    const w1 = ((c * 3) % 4) + 1;
    const w2 = ((c * 7) % 3) + 1;
    bars.push(`<rect x="${x}" y="0" width="${w1}" height="42" fill="currentColor"/>`);
    x += w1 + 2;
    bars.push(`<rect x="${x}" y="0" width="${w2}" height="42" fill="currentColor"/>`);
    x += w2 + 3;
  }
  return `<svg viewBox="0 0 ${x + 12} 46" preserveAspectRatio="none" style="width:100%;max-width:280px;height:42px;display:block;margin:0 auto;color:var(--tx)">${bars.join('')}</svg>`;
}

function renderQueuePassCard(appt, title = 'Appointment Confirmed!') {
  const user = currentUser();
  const backPage = currentPage === 'reschedule-confirmation' ? 'my-schedule.html' : 'dashboard.html';
  const backLabel = currentPage === 'reschedule-confirmation' ? 'Back to My Schedule' : 'Back to Dashboard';
  return `
    <div class="card queue-pass-card" id="printableQueuePass">
      <div class="queue-pass-badge-header">
        <div class="queue-pass-stamp">ENROLLMENT APPOINTMENT PASS</div>
        <div class="queue-pass-univ">${escapeHtml(user && user.campus || 'Not set')}</div>
      </div>

      <div class="queue-pass-body">
        <div style="text-align:center;margin-bottom:14px">
          <div class="check" style="margin:0 auto 8px;width:44px;height:44px;font-size:22px;line-height:44px">✓</div>
          <h2 style="font-size:20px;margin:0 0 4px">${title}</h2>
          <p class="muted" style="margin:0;font-size:13px">Present this digital slip or printed copy upon arrival</p>
        </div>

        <div class="queue-pass-main-ticket">
          <div class="queue-pass-ticket-label">ENROLLMENT APPOINTMENT REFERENCE</div>
          <div class="queue-pass-ticket-num">${appt.queue}</div>
        </div>

        <div class="queue-pass-info-grid">
          <div class="queue-pass-field">
            <span class="q-label">Student Name</span>
            <span class="q-val">${escapeHtml(user ? user.name : 'Not set')}</span>
          </div>
          <div class="queue-pass-field">
            <span class="q-label">Student ID</span>
            <span class="q-val">${escapeHtml(user ? user.sid : 'Not set')}</span>
          </div>
          <div class="queue-pass-field">
            <span class="q-label">Section & Program</span>
            <span class="q-val">${escapeHtml(user && user.section || 'Not set')} · ${escapeHtml(user && user.program || 'Not set')}</span>
          </div>
          <div class="queue-pass-field">
            <span class="q-label">Appointment Type</span>
            <span class="q-val">${escapeHtml(appt.type)}</span>
          </div>
          <div class="queue-pass-field">
            <span class="q-label">Appointment Date</span>
            <span class="q-val"><b>${formatDate(appt.date)}</b></span>
          </div>
          <div class="queue-pass-field">
            <span class="q-label">Designated Time Slot</span>
            <span class="q-val"><b>${appt.time}</b></span>
          </div>
        </div>

        <div class="queue-barcode-wrap">
          ${generateBarcodeSvg(appt.queue + '-' + appt.id)}
          <div class="queue-barcode-text">*${escapeHtml(appt.referenceNumber || appt.queue)}*</div>
        </div>

        <div class="queue-pass-checklist-reminder">
          <b style="color:var(--b)">Enrollment Appointment Instructions:</b>
          <ul>
            <li>Arrive 15 minutes before your appointment.</li>
            <li>Bring your verified enrollment documents and student identification.</li>
          </ul>
          ${appt.emailSent === false ? '<p class="err">Your appointment is saved, but the confirmation email could not be sent. Please contact the registrar.</p>' : ''}
        </div>
      </div>

      <div class="queue-pass-actions no-print">
        <button class="btn alt" id="printQueueBtn" type="button">🖨️ Print / Save Pass</button>
        <a class="btn" href="${backPage}">${backLabel}</a>
      </div>
    </div>`;
}

/* ---------- 6. LAYOUT (sidebar + page content) ---------- */
const MENU = [
  ['dashboard', '🏠', 'Dashboard'],
  ['book-appointment', '📅', 'Enrollment Appointment'],
  ['my-schedule', '🗓️', 'My Schedule'],
  ['enrollment', '🎓', 'Enrollment Details'],
  ['profile', '👤', 'Profile'],
  ['settings', '⚙️', 'Settings']
];
// Which menu item stays highlighted on sub-pages.
const HIGHLIGHT = {
  'appointment-summary': 'book-appointment', 'confirmation': 'book-appointment',
  'reschedule': 'my-schedule', 'reschedule-confirmation': 'my-schedule'
};

function withSidebar(content) {
  const active = HIGHLIGHT[currentPage] || currentPage;
  const user = currentUser();
  const studentName = user.name || 'Student';
  const nameParts = studentName.trim().split(/\s+/).filter(Boolean);
  const initials = nameParts.slice(0, 2).map(part => part.charAt(0).toUpperCase()).join('') || 'ST';
  const links = MENU.map(([page, icon, label]) =>
    `<a href="${page}.html" class="side-link ${page === active ? 'on' : ''}" ${page === active ? 'aria-current="page"' : ''}>
      <span class="side-icon" aria-hidden="true">${icon}</span><span>${label}</span>
    </a>`).join('');

  return `
    <div class="shell">
      <aside class="side" id="sidebar">
        <a class="side-brand" href="dashboard.html">
          <span class="side-brand-mark" aria-hidden="true">E</span>
          <span class="side-brand-copy"><strong>Enroll<span>Ease</span></strong><small>Student portal</small></span>
        </a>
        <div class="side-heading">STUDENT MENU</div>
        <nav class="side-nav" aria-label="Student navigation">${links}</nav>
        <div class="side-footer">
          <a class="side-account" href="profile.html">
            <span class="side-avatar" aria-hidden="true">${escapeHtml(initials)}</span>
            <span class="side-account-copy"><strong>${escapeHtml(studentName)}</strong><small>Student account</small></span>
          </a>
          <a href="#" id="sidebarLogout" class="sidebar-logout" title="Log out">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
          <span>Sign out</span>
          </a>
        </div>
      </aside>
      <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Close navigation"></button>
      <main class="main">
        <header class="top">
          <div class="topbar-leading">
            <button class="burger" id="menuButton" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">☰</button>
            <div class="topbar-heading"><span>STUDENT PORTAL</span><strong>Welcome, ${escapeHtml(nameParts[0] || 'Student')}</strong></div>
          </div>
          <a class="topbar-profile" href="profile.html">
            <span class="topbar-avatar" aria-hidden="true">${escapeHtml(initials)}</span>
            <span class="topbar-profile-copy"><strong>${escapeHtml(studentName)}</strong><small>View profile</small></span>
          </a>
        </header>
        <div class="main-content">${content}</div>
      </main>
    </div>`;
}


/* ---------- 7. THE PAGES ---------- */
const PAGES = {};

/* 1. Landing */
PAGES.index = {
  render: () => `
    <div class="hero"><div class="card">
      <svg width="120" height="110" viewBox="0 0 120 110" aria-hidden="true">
        <rect x="14" y="26" width="92" height="76" rx="12" fill="#dbeafe" stroke="#1d4ed8" stroke-width="4"/>
        <rect x="14" y="26" width="92" height="22" rx="12" fill="#1d4ed8"/>
        <path d="M60 4 22 18l38 14 38-14z" fill="#3b82f6"/>
        <circle cx="40" cy="70" r="7" fill="#1d4ed8"/><circle cx="60" cy="70" r="7" fill="#93c5fd"/>
        <circle cx="80" cy="70" r="7" fill="#93c5fd"/>
        <path d="m34 90 6 6 12-12" stroke="#16a34a" stroke-width="5" fill="none"/>
      </svg>
      <div class="logo">Enroll<span>Ease</span></div>
      <h2>Smarter Enrollment. Brighter Future.</h2>
      <p>Welcome! Book your enrollment appointment, get your queue number, and skip the long lines.</p>
      <a class="btn" href="register.html">Get Started</a>
      <p>Already have an account? <a href="login.html">Login</a></p>
    </div></div>`
};

/* 2. Login */
PAGES.login = {
  render: () => `
    <div class="auth"><form class="card" id="loginForm" novalidate>
      <div class="logo center">EnrollEase</div>
      <h2 class="center">Welcome back</h2>
      <p class="muted center">Sign in with your registered student account.</p>
      ${inputField('loginId', 'Email Address or Student ID')}
      ${inputField('loginPassword', 'Password', 'password')}
      <a href="#" id="forgotLink">Forgot password?</a><br><br>
      <button type="submit" class="btn" style="width:100%">Login</button>
      <p class="center">No account yet? <a href="register.html">Register</a></p>
    </form></div>`,

  setup() {
    select('#loginForm').onsubmit = async event => {
      event.preventDefault();
      clearErrors();
      const typedId = select('#loginId').value.trim();
      const typedPassword = select('#loginPassword').value;

      let valid = true;
      if (!typedId) valid = showError('loginId', 'Enter your email or student ID.');
      if (!typedPassword) valid = showError('loginPassword', 'Enter your password.');
      if (!valid) return;

      const submitButton = event.currentTarget.querySelector('button[type="submit"]');
      if (submitButton) submitButton.disabled = true;

      try {
        const response = await studentFetch('api/student/login.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ loginId: typedId, password: typedPassword })
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
          return showError('loginPassword', result.message || 'Unable to sign in.');
        }
        sessionStorage.setItem('ee_csrf', result.csrfToken);
        saveData('ee_users', [result.profile]);
        sessionStorage.setItem('ee_session', result.profile.sid);
        flashMessage('Welcome back, ' + result.profile.name.split(' ')[0] + '!');
        goTo('dashboard');
      } catch (error) {
        showError('loginPassword', 'The sign-in service is unavailable. Please try again.');
      } finally {
        if (submitButton) submitButton.disabled = false;
      }
    };

    select('#forgotLink').onclick = event => {
      event.preventDefault();
      showToast('Contact the registrar to reset your password.');
    };
  }
};

/* 3. Register */
PAGES.register = {
  render: () => `
    <div class="auth"><form class="card" id="registerForm" novalidate>
      <div class="logo center">Enroll<span>Ease</span></div>
      <h2 class="center">Create Account</h2>
      ${inputField('regName', 'Full Name')}
      ${inputField('regSid', 'Student ID', 'text', '', 'placeholder="2024-00000"')}
      ${inputField('regEmail', 'Email Address', 'email')}
      ${inputField('regPassword', 'Password', 'password')}
      ${inputField('regConfirm', 'Confirm Password', 'password')}
      <button class="btn" style="width:100%">Create Account</button>
      <p class="center">Have an account? <a href="login.html">Login</a></p>
    </form></div>`,

  setup() {
    select('#registerForm').onsubmit = event => {
      event.preventDefault();
      showError('regSid', 'Please register through the secure registration form.');
    };
  }
};

/* 4. Dashboard */
PAGES.dashboard = {
  render() {
    const user = currentUser();
    const next = myAppointments()
      .filter(a => !isPast(a) && a.status !== 'Cancelled')
      .sort((a, b) => appointmentDateTime(a) - appointmentDateTime(b))[0];

    const tile = (href, icon, title, extra = '') =>
      `<a class="card tile" href="${href}"><div class="ic">${icon}</div><b>${title}</b>${extra}</a>`;

    return withSidebar(`
      <div class="banner">
        <p style="margin:0;opacity:.85">Welcome back · <span style="background:rgba(255,255,255,0.22);padding:2px 8px;border-radius:6px;font-weight:700;letter-spacing:0.3px">${escapeHtml(user.section || 'Not set')}</span></p>
        <h1 style="margin: 6px 0 10px;">
          <a href="profile.html" class="banner-profile-link" title="View your student profile">
            <span>${escapeHtml(user.name)}</span>
            <svg class="profile-arrow-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M5 12h14"></path>
              <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
          </a>
        </h1>
        <p style="margin:0">${next
          ? 'Next appointment: ' + formatDate(next.date) + ' at ' + next.time + ' (' + next.type + ')'
          : 'You have no upcoming appointments.'}</p>
      </div>

      <!-- Quick Study Load & COR Summary Card -->
      <div class="card study-load-widget">
        <div class="study-load-main">
          <div class="study-load-info">
            <div class="study-load-tag">Student Record</div>
            <h3 class="study-load-title">${escapeHtml(user.program || 'Not set')}</h3>
            <div class="study-load-meta">
              <span class="load-pill">Year: <b>${escapeHtml(user.year || 'Not set')}</b></span>
              <span class="load-pill status-pill">Status: ${escapeHtml(user.status || 'Not set')}</span>
            </div>
          </div>
          <div class="study-load-action">
            <a href="enrollment.html" class="btn btn-view-cor">
              <span>View Student Record</span>
              <span class="arrow">➔</span>
            </a>
          </div>
        </div>
      </div>

      <div class="grid">
        ${tile('book-appointment.html', '📅', 'Enrollment Appointment', '<p class="muted">Check eligibility and reserve a time</p>')}
        ${tile('my-schedule.html', '🗓️', 'My Schedule', '<p class="muted">Upcoming and past</p>')}
        ${tile('my-schedule.html', '🎟️', 'Queue Number',
               next ? `<div class="q">${next.queue}</div>` : '<p class="muted">No active queue</p>')}
        ${tile('enrollment.html', '🎓', 'Enrollment & COR', '<p class="muted">Official Study Load (COR)</p>')}
      </div>

      <div class="dashboard-footer-bar">
        <button type="button" class="btn-subtle-exit" id="dashboardLogout" title="Log out of student account">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
          <span>Sign out</span>
        </button>
      </div>`);
  },
  setup() {
    const profileLink = select('.banner-profile-link');
    if (profileLink) {
      profileLink.onclick = event => {
        event.preventDefault();
        goTo('profile');
      };
    }
    const logoutBtn = select('#dashboardLogout');
    if (logoutBtn) {
      logoutBtn.onclick = event => {
        event.preventDefault();
        confirmLogout();
      };
    }
  }
};

/* 5. Book appointment */
PAGES['book-appointment'] = {
  render: () => withSidebar(`
    <h1>Enrollment Appointment</h1>
    <div class="card">
      <p class="muted">Your enrollment records are checked before any dates or times are made available.</p>
      <section id="eligibilityResult" aria-live="polite" aria-busy="true">Checking enrollment eligibility…</section>
      <div id="slotPicker" hidden>
        <label for="enrollmentSlot">Available enrollment appointment dates and times</label>
        <select id="enrollmentSlot"><option value="">Select an available slot</option></select>
        <p class="muted">Slots are subject to availability until your booking is confirmed.</p>
        <div class="err" id="bookingError" role="alert"></div>
        <button class="btn" id="confirmEnrollmentButton" disabled>Confirm Enrollment Appointment</button>
      </div>
    </div>`),

  setup() {
    const resultBox = select('#eligibilityResult');
    const slotPicker = select('#slotPicker');
    const slotSelect = select('#enrollmentSlot');
    const confirmButton = select('#confirmEnrollmentButton');
    let slots = [];
    const redirectToLogin = () => {
      clearStudentSession();
      goTo('login');
    };

    const renderEligibility = (eligibility, profile, schedule) => {
      resultBox.setAttribute('aria-busy', 'false');
      profile = profile || currentUser() || { name: 'Student', sid: '' };
      const checks = eligibility.checks || [];
      const today = new Date().toISOString().slice(0, 10);
      let weekWindowMessage = '';
      if (schedule) {
        if (today < schedule.start_date) {
          weekWindowMessage = `<p class="notice info">Booking opens on ${escapeHtml(formatDate(schedule.start_date))}.</p>`;
        } else if (today > schedule.end_date) {
          weekWindowMessage = `<p class="notice alert">The booking window for your year level has closed (closed on ${escapeHtml(formatDate(schedule.end_date))}).</p>`;
        }
      }
      resultBox.innerHTML = `<h2>${eligibility.eligible ? 'You are qualified to book' : 'Enrollment Appointment Not Available'}</h2>
        <p>Name: ${escapeHtml(profile.name)} · Student ID: ${escapeHtml(profile.sid)}</p>
        <p>Program: ${escapeHtml(profile.program || 'Not on file')} · Campus: ${escapeHtml(profile.campus || 'Not on file')}</p>
        <p>Year level: ${escapeHtml(profile.yearLevel || profile.year || 'Not on file')} · Student status: ${escapeHtml(profile.status || 'Not on file')}</p>
        <p>Assigned Enrollment Week: ${escapeHtml(profile.assignedEnrollmentWeek || 'Not available')}
          ${schedule ? ` · ${escapeHtml(formatDate(schedule.start_date))} – ${escapeHtml(formatDate(schedule.end_date))}` : ''}</p>
        ${weekWindowMessage}
        ${eligibility.eligible ? '<p>All enrollment requirements are currently satisfied.</p>' : '<p>You are currently not qualified for enrollment because:</p>'}
        <ul>${checks.map(check => `<li>${check.passed ? '✓' : '✗'} ${escapeHtml(check.label)}
          ${(check.reasons || []).length ? `<ul>${check.reasons.map(reason => `<li>${escapeHtml(reason)}</li>`).join('')}</ul>` : ''}</li>`).join('')}</ul>
        ${eligibility.eligible ? '' : '<p>Please resolve the requirements above before booking an enrollment appointment.</p>'}
        ${eligibility.eligible && !schedule ? '<p>No active enrollment schedule is available for your year level. Please contact the registrar.</p>' : ''}`;
      slotPicker.hidden = !eligibility.eligible || !schedule || (schedule && (today < schedule.start_date || today > schedule.end_date));
    };

    const loadSlots = async () => {
      const response = await studentFetch('api/student/slots.php', { credentials: 'same-origin' });
      if (response.status === 401) return redirectToLogin();
      const result = await response.json();
      if (!response.ok || !result.success) {
        if (result.message) {
          resultBox.insertAdjacentHTML('beforeend', `<p class="notice alert">${escapeHtml(result.message)}</p>`);
          slotPicker.hidden = true;
          return;
        }
        throw new Error(result.message || 'Available slots could not be loaded.');
      }
      if (!result.eligible) {
        renderEligibility(result.eligibility, result.student, result.schedule);
        return;
      }
      renderEligibility({ eligible: true, checks: [] }, result.student, result.schedule);
      slots = result.slots;
      slotSelect.innerHTML = '<option value="">Select an available slot</option>' +
        slots.map((slot, index) => `<option value="${index}" ${slot.remaining === 0 ? 'disabled' : ''}>${escapeHtml(formatDate(slot.date))} · ${slot.dailyBooked}/${slot.dailyCapacity} students (${slot.dailyRemaining} daily slots left) · ${escapeHtml(slot.time)}${slot.remaining === 0 ? ' — FULL' : ''}</option>`).join('');
      slotPicker.hidden = !result.schedule || slots.length === 0;
      if (result.message) resultBox.insertAdjacentHTML('beforeend', `<p>${escapeHtml(result.message)}</p>`);
      else if (!slots.length) resultBox.insertAdjacentHTML('beforeend', '<p>No enrollment appointment time slots are configured for your assigned week.</p>');
      else if (slots.every(slot => slot.remaining === 0)) resultBox.insertAdjacentHTML('beforeend', '<p>All dates in your assigned enrollment week are FULL.</p>');
    };

    const loadEligibility = async () => {
      try {
        const response = await studentFetch('api/student/eligibility.php', { credentials: 'same-origin' });
        if (response.status === 401) return redirectToLogin();
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Eligibility could not be checked.');
        renderEligibility(result.eligibility, result.student, result.schedule);
        if (result.eligibility.eligible) await loadSlots();
      } catch (error) {
        resultBox.setAttribute('aria-busy', 'false');
        resultBox.textContent = error.message || 'Eligibility records could not be checked. Please try again later.';
      }
    };

    slotSelect.onchange = () => { confirmButton.disabled = slotSelect.value === ''; };
    confirmButton.onclick = async () => {
      const slot = slots[Number(slotSelect.value)];
      if (!slot) return;
      confirmButton.disabled = true;
      select('#bookingError').textContent = '';
      try {
        const response = await studentFetch('api/student/book.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': sessionStorage.getItem('ee_csrf') || ''
          },
          body: JSON.stringify({ date: slot.date, time: slot.time })
        });
        if (response.status === 401) return redirectToLogin();
        const result = await response.json();
        if (!response.ok || !result.success) {
          if (result.eligibility) renderEligibility(result.eligibility);
          if (response.status === 409) {
            await loadSlots();
            slotSelect.value = '';
            confirmButton.disabled = true;
          }
          throw new Error(result.message || 'The appointment could not be booked.');
        }
        const user = currentUser();
        const appointment = {
          id: result.appointment.referenceNumber,
          referenceNumber: result.appointment.referenceNumber,
          sid: user.sid,
          type: 'Enrollment Appointment',
          date: result.appointment.date,
          time: result.appointment.time,
          queue: result.appointment.referenceNumber,
          status: result.appointment.status,
          emailSent: result.appointment.emailSent
        };
        saveData('ee_appointments', allAppointments().concat(appointment));
        saveData('ee_last', appointment.id);
        sessionStorage.removeItem('ee_draft');
        goTo('confirmation');
      } catch (error) {
        select('#bookingError').textContent = error.message || 'The appointment could not be booked.';
        confirmButton.disabled = slotSelect.value === '';
      }
    };
    loadEligibility();
  }
};

/* 6. Appointment summary */
PAGES['appointment-summary'] = {
  render() {
    goTo('book-appointment');
    return '';
  },
  setup() {}
};

// The most recently booked/rescheduled appointment.
function lastAppointment() {
  return allAppointments().find(a => a.id === loadData('ee_last'));
}

/* 7. Confirmation */
PAGES.confirmation = {
  render() {
    const appt = lastAppointment();
    if (!appt) { goTo('dashboard'); return ''; }
    return withSidebar(renderQueuePassCard(appt, 'Enrollment Appointment Requested'));
  },
  setup() {
    const printBtn = select('#printQueueBtn');
    if (printBtn) printBtn.onclick = () => window.print();
  }
};

/* 8. My schedule */
PAGES['my-schedule'] = {
  render() {
    const appointments = myAppointments().filter(a => a.type === 'Enrollment Appointment');
    const active = appointments.filter(a => a.status !== 'Cancelled');
    const upcoming = active.filter(a => !isPast(a)).sort((a, b) => appointmentDateTime(a) - appointmentDateTime(b));
    const past = appointments.filter(a => a.status === 'Cancelled' || isPast(a))
      .sort((a, b) => appointmentDateTime(b) - appointmentDateTime(a));

    const card = (appt, isUpcoming) => {
      const canChange = isUpcoming &&
        ['Submitted', 'Pending', 'Approved', 'Confirmed', 'Rescheduled'].includes(appt.status);
      const referenceNumber = escapeHtml(appt.referenceNumber || appt.id);
      return `
      <div class="card ap">
        <div>
          <b>${appt.type}</b>
          <div class="muted">${formatDate(appt.date)} · ${appt.time}${isUpcoming ? ' · Queue ' + appt.queue : ''}</div>
        </div>
        <span class="badge ${isUpcoming ? (appt.status === 'Rescheduled' ? 'p' : '') : 'd'}">
          ${isUpcoming ? appt.status : (appt.status === 'Cancelled' ? 'Cancelled' : 'Completed')}</span>
        ${isUpcoming ? `<div class="row">
          <button class="btn sm alt" type="button" data-details="${referenceNumber}">View Details</button>
          ${canChange ? `<button class="btn sm" type="button" data-reschedule="${referenceNumber}">Reschedule</button>
          <button class="btn sm alt" type="button" data-cancel="${referenceNumber}">Cancel</button>` : ''}
          </div>` : ''}
      </div>`;
    };

    return withSidebar(`
      <h1>My Schedule</h1>
      <h2>Upcoming Appointments</h2>
      ${upcoming.map(a => card(a, true)).join('') ||
        '<div class="card muted">Nothing booked yet. <a href="book-appointment.html">Book an appointment</a></div>'}
      <h2 class="sec">Past Appointments</h2>
      ${past.map(a => card(a, false)).join('') || '<div class="card muted">No past appointments.</div>'}`);
  },

  setup() {
    const bindDetails = () => {
      document.querySelectorAll('[data-details]').forEach(button => {
        button.onclick = () => {
          const appt = allAppointments().find(a => a.id === button.dataset.details);
          if (!appt) return;
          const popup = showModal(`
            <div style="text-align:center;margin-bottom:12px">
              <span style="font-size:11px;font-weight:700;color:var(--b2);letter-spacing:1px;text-transform:uppercase">Enrollment Appointment</span>
              <div style="font-size:24px;font-weight:900;color:var(--b2);letter-spacing:1px;margin:2px 0">${escapeHtml(appt.referenceNumber || appt.queue)}</div>
            </div>
            ${detailsList({
              'Appointment': appt.type,
              'Date': formatDate(appt.date),
              'Time': appt.time,
              'Status': appt.status
            })}
            <div style="margin-top:14px;padding:10px 12px;background:rgba(59,130,246,0.06);border-radius:6px;font-size:12px;color:var(--mu)">
              <b style="color:var(--tx)">Instructions:</b> Arrive 15 minutes early and bring your verified enrollment documents and student ID.
            </div>
            <br>
            <div class="row">
              <button class="btn alt" id="closeDetails">Close</button>
              <button class="btn" id="openPassSlip">View Appointment Reference</button>
            </div>`);
          popup.querySelector('#closeDetails').onclick = () => popup.remove();
          popup.querySelector('#openPassSlip').onclick = () => {
            saveData('ee_last', appt.id);
            popup.remove();
            goTo('confirmation');
          };
        };
      });
    };
    const bindAppointmentActions = () => {
      document.querySelectorAll('[data-reschedule]').forEach(button => {
        button.onclick = () => {
          sessionStorage.setItem('ee_reschedule_reference', button.dataset.reschedule);
          goTo('reschedule');
        };
      });
      document.querySelectorAll('[data-cancel]').forEach(button => {
        button.onclick = async () => {
          const referenceNumber = button.dataset.cancel;
          if (!window.confirm('Cancel this enrollment appointment?')) return;
          button.disabled = true;
          try {
            const response = await studentFetch('api/student/cancel.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': sessionStorage.getItem('ee_csrf') || ''
              },
              body: JSON.stringify({ referenceNumber })
            });
            if (response.status === 401) {
              clearStudentSession();
              goTo('login');
              return;
            }
            const result = await response.json();
            if (!response.ok || !result.success) {
              throw new Error(result.message || 'The appointment could not be cancelled.');
            }
            const updated = allAppointments().map(appointment =>
              appointment.referenceNumber === referenceNumber
                ? { ...appointment, status: 'Cancelled', emailSent: result.appointment.emailSent }
                : appointment
            );
            saveData('ee_appointments', updated);
            select('#app').innerHTML = PAGES['my-schedule'].render();
            bindDetails();
            bindAppointmentActions();
            showToast(result.message, !result.appointment.emailSent);
          } catch (error) {
            showToast(error.message || 'The appointment could not be cancelled.', true);
          } finally {
            if (button.isConnected) button.disabled = false;
          }
        };
      });
    };
    bindDetails();
    bindAppointmentActions();
    studentFetch('api/student/appointments.php', { credentials: 'same-origin' })
      .then(async response => {
        const result = await response.json();
        if (response.status === 401) {
          clearStudentSession();
          goTo('login');
          return;
        }
        if (!response.ok || !result.success) throw new Error(result.message || 'Appointments could not be loaded.');
        const serverAppointments = result.appointments.map(appointment => ({
          ...appointment,
          id: appointment.referenceNumber,
          sid: sessionStorage.getItem('ee_session'),
          type: 'Enrollment Appointment',
          queue: appointment.referenceNumber
        }));
        const existing = allAppointments().filter(appointment =>
          appointment.sid !== sessionStorage.getItem('ee_session') || appointment.type !== 'Enrollment Appointment'
        );
        saveData('ee_appointments', existing.concat(serverAppointments));
        select('#app').innerHTML = PAGES['my-schedule'].render();
        bindDetails();
        bindAppointmentActions();
      })
      .catch(error => showToast(error.message || 'Appointments could not be loaded.', true));
  }
};

/* 9. Reschedule */
PAGES.reschedule = {
  render() {
    const referenceNumber = sessionStorage.getItem('ee_reschedule_reference');
    const appointment = allAppointments().find(item =>
      item.referenceNumber === referenceNumber && item.status !== 'Cancelled'
    );
    if (!appointment) {
      goTo('my-schedule');
      return '';
    }
    return withSidebar(`
      <h1>Reschedule Appointment</h1>
      <div class="card">
        <p class="muted">Choose a new date and time for appointment ${escapeHtml(referenceNumber)}.</p>
        <div id="rescheduleStatus" class="muted" aria-live="polite">Loading available enrollment times…</div>
        <label for="rescheduleSlot">Available dates and times</label>
        <select id="rescheduleSlot" disabled><option value="">Loading…</option></select>
        <div id="rescheduleError" class="err" role="alert"></div>
        <div class="row">
          <button class="btn" id="confirmRescheduleButton" type="button" disabled>Confirm Reschedule</button>
          <button class="btn alt" id="cancelRescheduleButton" type="button">Back to My Schedule</button>
        </div>
      </div>`);
  },
  setup() {
    const referenceNumber = sessionStorage.getItem('ee_reschedule_reference');
    const slotSelect = select('#rescheduleSlot');
    const statusBox = select('#rescheduleStatus');
    const errorBox = select('#rescheduleError');
    const confirmButton = select('#confirmRescheduleButton');
    const backButton = select('#cancelRescheduleButton');
    let slots = [];

    backButton.onclick = () => {
      sessionStorage.removeItem('ee_reschedule_reference');
      goTo('my-schedule');
    };

    const redirectToLogin = () => {
      clearStudentSession();
      goTo('login');
    };
    const loadSlots = async () => {
      statusBox.textContent = 'Loading available enrollment times…';
      slotSelect.disabled = true;
      confirmButton.disabled = true;
      const response = await studentFetch(
        'api/student/slots.php?excludeReference=' + encodeURIComponent(referenceNumber),
        { credentials: 'same-origin' }
      );
      if (response.status === 401) return redirectToLogin();
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Available enrollment times could not be loaded.');
      }
      if (!result.eligible) {
        throw new Error('You are no longer eligible to reschedule. Contact the registrar.');
      }
      slots = result.slots;
      slotSelect.innerHTML = '<option value="">Select a new date and time</option>' +
        slots.map((slot, index) =>
          `<option value="${index}" ${slot.remaining === 0 ? 'disabled' : ''}>${escapeHtml(formatDate(slot.date))} · ${slot.dailyBooked}/${slot.dailyCapacity} students (${slot.dailyRemaining} daily slots left) · ${escapeHtml(slot.time)}${slot.remaining === 0 ? ' — FULL' : ''}</option>`
        ).join('');
      slotSelect.disabled = slots.length === 0;
      statusBox.textContent = result.message || (slots.length
        ? 'Select an available time. Your current appointment remains active until the change is confirmed.'
        : 'No enrollment appointment times are available for your assigned week.');
    };

    slotSelect.onchange = () => {
      confirmButton.disabled = slotSelect.value === '';
      errorBox.textContent = '';
    };
    confirmButton.onclick = async () => {
      const slot = slots[Number(slotSelect.value)];
      if (!slot) return;
      confirmButton.disabled = true;
      errorBox.textContent = '';
      try {
        const response = await studentFetch('api/student/reschedule.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': sessionStorage.getItem('ee_csrf') || ''
          },
          body: JSON.stringify({ referenceNumber, date: slot.date, time: slot.time })
        });
        if (response.status === 401) return redirectToLogin();
        const result = await response.json();
        if (!response.ok || !result.success) {
          if (response.status === 409) await loadSlots();
          throw new Error(result.message || 'The appointment could not be rescheduled.');
        }
        const updated = allAppointments().map(appointment =>
          appointment.referenceNumber === referenceNumber
            ? {
                ...appointment,
                date: result.appointment.date,
                time: result.appointment.time,
                status: result.appointment.status,
                emailSent: result.appointment.emailSent
              }
            : appointment
        );
        saveData('ee_appointments', updated);
        saveData('ee_last', referenceNumber);
        sessionStorage.removeItem('ee_reschedule_reference');
        goTo('reschedule-confirmation');
      } catch (error) {
        errorBox.textContent = error.message || 'The appointment could not be rescheduled.';
      } finally {
        confirmButton.disabled = slotSelect.value === '';
      }
    };

    loadSlots().catch(error => {
      statusBox.textContent = '';
      errorBox.textContent = error.message || 'Available enrollment times could not be loaded.';
    });
  },
};

/* 10. Reschedule confirmation */
PAGES['reschedule-confirmation'] = {
  render() {
    const appt = lastAppointment();
    if (!appt) { goTo('dashboard'); return ''; }
    return withSidebar(`
      <p class="muted">Your appointment has been updated. The reference number is unchanged.</p>
      ${renderQueuePassCard(appt, 'Appointment Rescheduled!')}`);
  },
  setup() {
    const printBtn = select('#printQueueBtn');
    if (printBtn) printBtn.onclick = () => window.print();
  }
};

/* 11. Enrollment record & eligibility status */
PAGES.enrollment = {
  render() {
    const user = currentUser();
    return withSidebar(`
      <div class="enrollment-header" style="margin-bottom: 24px;">
        <h1 style="font-size: 26px; font-weight: 700; margin-bottom: 6px;">Enrollment &amp; Eligibility Status</h1>
        <p class="muted" style="font-size: 14px;">Review your current academic standing, prerequisite clearances, and enrollment booking qualification.</p>
      </div>

      <div id="enrollmentEligibilityBanner" class="card" style="margin-bottom: 24px; padding: 20px; transition: all 0.2s ease;">
        <div style="display: flex; align-items: center; gap: 14px;">
          <div style="font-size: 24px; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 50%; background: #e0f2fe; color: #0284c7;">⌛</div>
          <div>
            <h2 style="font-size: 18px; margin: 0 0 4px; font-weight: 600;">Checking enrollment eligibility...</h2>
            <p class="muted" style="margin: 0; font-size: 13px;">Verifying academic records, financials, documents, holds, and schedule window...</p>
          </div>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <div class="card" style="padding: 22px;">
          <h2 style="font-size: 17px; font-weight: 600; margin-bottom: 14px; border-bottom: 1px solid var(--ln); padding-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
            <span>Student Academic Record</span>
            <span id="enrollmentStatusBadge" class="badge" style="font-size: 11px; padding: 4px 10px; border-radius: 20px; background: #e2e8f0; color: #475569;">${escapeHtml(user.status || 'Active')}</span>
          </h2>
          <div id="enrollmentStudentDetails">
            ${detailsList({
              'Student ID': user.sid || 'Not set',
              'Full Name': user.name || 'Not set',
              'Program': user.program || 'Not set',
              'Year Level': user.year || 'Not set',
              'Academic Standing': user.academicStatus || user.status || 'Regular',
              'Campus': user.campus || 'CTU Daanbantayan Campus',
              'Assigned Week': user.assignedEnrollmentWeek || 'Not assigned'
            })}
          </div>
        </div>

        <div class="card" style="padding: 22px;">
          <h2 style="font-size: 17px; font-weight: 600; margin-bottom: 14px; border-bottom: 1px solid var(--ln); padding-bottom: 10px;">
            Schedule &amp; Next Steps
          </h2>
          <div id="enrollmentScheduleSummary">
            <p class="muted" style="font-size: 14px;">Loading schedule and booking window information...</p>
          </div>
          <div id="enrollmentActionContainer" style="margin-top: 18px;" hidden>
            <a href="book-appointment.html" class="btn" style="display: block; text-align: center; text-decoration: none; padding: 12px 18px;">
              Proceed to Book Appointment &rarr;
            </a>
          </div>
        </div>
      </div>

      <div class="card" style="padding: 22px;">
        <h2 style="font-size: 17px; font-weight: 600; margin-bottom: 6px;">Prerequisite Checks Breakdown</h2>
        <p class="muted" style="font-size: 13px; margin-bottom: 18px;">All prerequisite checks must be satisfied before booking an in-person enrollment appointment.</p>
        <div id="enrollmentChecksList">
          <p class="muted">Loading verification checks...</p>
        </div>
      </div>
    `);
  },

  setup() {
    const banner = select('#enrollmentEligibilityBanner');
    const studentDetails = select('#enrollmentStudentDetails');
    const scheduleSummary = select('#enrollmentScheduleSummary');
    const actionContainer = select('#enrollmentActionContainer');
    const checksList = select('#enrollmentChecksList');
    const statusBadge = select('#enrollmentStatusBadge');

    const renderData = (eligibility, student, schedule) => {
      const isEligible = Boolean(eligibility && eligibility.eligible);
      const checks = (eligibility && eligibility.checks) || [];
      const user = currentUser();
      const today = new Date().toISOString().slice(0, 10);

      // 1. Render Overall Banner
      if (isEligible) {
        banner.style.border = '1px solid #86efac';
        banner.style.background = '#f0fdf4';
        banner.innerHTML = `
          <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="font-size: 22px; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 50%; background: #dcfce7; color: #16a34a; flex-shrink: 0;">✓</div>
            <div style="flex: 1;">
              <h2 style="font-size: 18px; margin: 0 0 4px; font-weight: 600; color: #166534;">Fully Qualified for Enrollment Appointment Booking</h2>
              <p style="margin: 0 0 6px; font-size: 14px; color: #15803d;">All academic records, financial accounts, holds, and document verification checks are satisfied. You may proceed to select an appointment slot.</p>
            </div>
          </div>
        `;
        if (actionContainer) actionContainer.hidden = false;
      } else {
        banner.style.border = '1px solid #fecaca';
        banner.style.background = '#fef2f2';
        const reasons = (eligibility && eligibility.reasons && eligibility.reasons.length)
          ? `<ul style="margin: 8px 0 0 18px; padding: 0; font-size: 13px; color: #b91c1c;">${eligibility.reasons.map(r => `<li>${escapeHtml(r)}</li>`).join('')}</ul>`
          : '';
        banner.innerHTML = `
          <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="font-size: 22px; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 50%; background: #fee2e2; color: #dc2626; flex-shrink: 0;">✕</div>
            <div style="flex: 1;">
              <h2 style="font-size: 18px; margin: 0 0 4px; font-weight: 600; color: #991b1b;">Enrollment Blocked — Review Requirements Below</h2>
              <p style="margin: 0; font-size: 14px; color: #b91c1c;">One or more prerequisites are currently blocking your appointment. Please review the itemized checks below and resolve any deficiencies before booking.</p>
              ${reasons}
            </div>
          </div>
        `;
        if (actionContainer) actionContainer.hidden = true;
      }

      // 2. Render Student Academic Details
      if (student) {
        if (statusBadge) {
          const actStatus = student.academicStatus || student.status || 'Active';
          statusBadge.textContent = actStatus;
          statusBadge.style.background = isEligible ? '#dcfce7' : '#fee2e2';
          statusBadge.style.color = isEligible ? '#166534' : '#991b1b';
        }
        if (studentDetails) {
          studentDetails.innerHTML = detailsList({
            'Student ID': student.sid || user.sid || 'Not set',
            'Full Name': student.name || user.name || 'Not set',
            'Program': student.program || user.program || 'Not set',
            'Year Level': student.yearLevel || student.year || user.year || 'Not set',
            'Academic Standing': student.academicStatus || 'Regular',
            'Campus': student.campus || user.campus || 'CTU Daanbantayan Campus',
            'Assigned Week': student.assignedEnrollmentWeek || user.assignedEnrollmentWeek || 'Not assigned'
          });
        }
      }

      // 3. Render Schedule Summary
      if (scheduleSummary) {
        if (!schedule) {
          scheduleSummary.innerHTML = `
            <div style="padding: 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; color: #92400e; font-size: 13px;">
              <strong>No Active Schedule:</strong> An enrollment schedule is not currently published for your year level. Please consult the registrar.
            </div>
          `;
        } else {
          let windowStatus = '';
          if (today < schedule.start_date) {
            windowStatus = `<span style="color: #0284c7; font-weight: 600;">Upcoming</span> (Opens in ${Math.ceil((new Date(schedule.start_date) - new Date(today)) / (1000 * 60 * 60 * 24))} days)`;
          } else if (today > schedule.end_date) {
            windowStatus = `<span style="color: #dc2626; font-weight: 600;">Closed</span> (Ended on ${formatDate(schedule.end_date)})`;
          } else {
            windowStatus = `<span style="color: #16a34a; font-weight: 600;">Open Now</span> (Active enrollment week)`;
          }

          scheduleSummary.innerHTML = `
            <div style="display: grid; gap: 8px; font-size: 14px;">
              <div style="display: flex; justify-content: space-between;"><span class="muted">Year Level:</span><strong>${escapeHtml(schedule.year_level || student.yearLevel || '')}</strong></div>
              <div style="display: flex; justify-content: space-between;"><span class="muted">Week Number:</span><strong>Week ${escapeHtml(schedule.week_number || '')}</strong></div>
              <div style="display: flex; justify-content: space-between;"><span class="muted">Enrollment Week:</span><strong>${formatDate(schedule.start_date)} &ndash; ${formatDate(schedule.end_date)}</strong></div>
              <div style="display: flex; justify-content: space-between; border-top: 1px dashed var(--ln); padding-top: 6px;"><span class="muted">Booking Window:</span><strong>${windowStatus}</strong></div>
            </div>
          `;
        }
      }

      // 4. Render Prerequisite Checks Breakdown
      if (checksList) {
        if (!checks.length) {
          checksList.innerHTML = '<p class="muted">No verification checks were returned.</p>';
        } else {
          checksList.innerHTML = `
            <div style="display: flex; flex-direction: column; gap: 12px;">
              ${checks.map(check => {
                const passed = Boolean(check.passed);
                const borderCol = passed ? '#bbf7d0' : '#fecaca';
                const bgCol = passed ? '#f8fafc' : '#fff5f5';
                const badgeBg = passed ? '#dcfce7' : '#fee2e2';
                const badgeTxt = passed ? '#166534' : '#991b1b';
                const icon = passed ? '✓' : '✕';
                const badgeLabel = passed ? 'Passed' : 'Action Required';

                const detailsHtml = (check.details && check.details.length)
                  ? `<div style="margin-top: 6px; font-size: 12px; color: var(--mu); line-height: 1.5;">${check.details.map(d => `<div>• ${escapeHtml(d)}</div>`).join('')}</div>`
                  : '';
                const reasonsHtml = (!passed && check.reasons && check.reasons.length)
                  ? `<div style="margin-top: 6px; padding: 6px 10px; background: #fee2e2; border-radius: 6px; font-size: 12px; color: #991b1b; line-height: 1.4;">${check.reasons.map(r => `<div>⚠ ${escapeHtml(r)}</div>`).join('')}</div>`
                  : '';

                return `
                  <div style="border: 1px solid ${borderCol}; background: ${bgCol}; border-radius: 10px; padding: 14px 16px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                      <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-weight: 700; color: ${badgeTxt}; font-size: 16px;">${icon}</span>
                        <strong style="font-size: 15px; color: var(--tx);">${escapeHtml(check.label)}</strong>
                      </div>
                      <span style="background: ${badgeBg}; color: ${badgeTxt}; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 12px;">
                        ${badgeLabel}
                      </span>
                    </div>
                    ${detailsHtml}
                    ${reasonsHtml}
                  </div>
                `;
              }).join('')}
            </div>
          `;
        }
      }
    };

    studentFetch('api/student/eligibility.php', { credentials: 'same-origin' })
      .then(async res => {
        if (res.status === 401) {
          clearStudentSession();
          goTo('login');
          return;
        }
        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.message || 'Could not load enrollment records.');
        }
        renderData(data.eligibility, data.student, data.schedule);
      })
      .catch(err => {
        if (banner) {
          banner.style.background = '#fef2f2';
          banner.style.border = '1px solid #fecaca';
          banner.innerHTML = `
            <div style="display: flex; align-items: center; gap: 14px;">
              <div style="font-size: 22px; color: #dc2626;">⚠</div>
              <div>
                <h2 style="font-size: 17px; margin: 0 0 4px; color: #991b1b;">Unable to Check Eligibility</h2>
                <p style="margin: 0; font-size: 13px; color: #b91c1c;">${escapeHtml(err.message || 'The server could not be reached. Please reload or try again later.')}</p>
              </div>
            </div>
          `;
        }
      });
  }
};

/* 12. Profile */
PAGES.profile = {
  render() {
    const user = currentUser();
    const section = user.section || 'Not set';
    const academicStatus = user.status || 'Not set';

    return withSidebar(`
      <div class="profile-page-wrapper">
        <!-- Top Profile Card -->
        <div class="card profile-header-card">
          <div class="profile-header-flex">
            <div class="profile-avatar">${studentInitials(user.name)}</div>
            <div class="profile-header-info">
              <h2 style="margin: 0 0 4px 0;">${escapeHtml(user.name)}</h2>
              <div class="profile-badge-row">
                <span class="badge-pill font-mono">${escapeHtml(user.sid)}</span>
                <span class="badge-pill badge-primary">${escapeHtml(section)}</span>
                <span class="badge-pill badge-success">${escapeHtml(academicStatus)}</span>
              </div>
            </div>
            <!-- Discreet, non-obvious edit button -->
            <button type="button" class="profile-mini-edit-btn" id="openEditContactBtn" title="Update Contact Info">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -1px; margin-right: 4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Edit Contact
            </button>
          </div>
        </div>

        <!-- Academic & Student Details Grid -->
        <div class="card sec">
          <h3 style="margin: 0 0 14px 0; font-size: 16px;">Student Record & Academic Profile</h3>
          <dl class="profile-dl">
            <dt>Student ID</dt><dd class="font-mono"><b>${escapeHtml(user.sid)}</b></dd>
            <dt>Degree Program</dt><dd>${escapeHtml(user.program)}</dd>
            <dt>Year Level</dt><dd><b>${escapeHtml(user.year)}</b></dd>
            <dt>Campus</dt><dd>${escapeHtml(user.campus || 'Not set')}</dd>
            <dt>Assigned Enrollment Week</dt><dd>${escapeHtml(user.assignedEnrollmentWeek || 'Not assigned')}</dd>
            <dt>Institutional Email</dt><dd id="displayEmail">${escapeHtml(user.email)}</dd>
            <dt>Contact Number</dt><dd id="displayContact">${user.contact ? escapeHtml(user.contact) : '<span class="muted">Not provided</span>'}</dd>
            <dt>Student Status</dt><dd>${escapeHtml(academicStatus)}</dd>
            <dt>Academic Status</dt><dd>${escapeHtml(user.academicStatus || 'Not set')}</dd>
            <dt>Enrollment Date</dt><dd>${user.enrolled ? formatDate(user.enrolled) : 'Not set'}</dd>
          </dl>
        </div>
      </div>
    `);
  },

  setup() {
    select('#openEditContactBtn').onclick = () => {
      const user = currentUser();
      const popup = showModal(`
        <h3 style="margin-top:0;">Update Contact Information</h3>
        <p class="muted" style="margin-bottom:14px; font-size:13px;">Student ID, campus, year level, and enrollment week are protected. You may update your email and contact number.</p>
        <form id="contactEditForm" novalidate>
          ${inputField('editEmail', 'Email Address', 'email', user.email)}
          ${inputField('editContact', 'Contact Number', 'tel', user.contact || '')}
          <p id="contactEditMessage" class="err" role="alert"></p>
          <div class="row" style="margin-top: 16px; gap: 10px;">
            <button type="button" class="btn alt" id="cancelContactEdit" style="flex: 1;">Cancel</button>
            <button type="submit" class="btn" style="flex: 1;">Save Changes</button>
          </div>
        </form>
      `);

      popup.querySelector('#cancelContactEdit').onclick = () => popup.remove();
      popup.querySelector('#contactEditForm').onsubmit = async event => {
        event.preventDefault();
        clearErrors();
        const email = popup.querySelector('#editEmail').value.trim();
        const contact = popup.querySelector('#editContact').value.trim();

        let valid = true;
        if (!/^\S+@\S+\.\S+$/.test(email)) valid = showError('editEmail', 'Enter a valid email address.');
        if (contact && !/^[\d\s()+-]{7,32}$/.test(contact)) valid = showError('editContact', 'Enter a valid contact number.');
        if (!valid) return;

        const saveButton = popup.querySelector('button[type="submit"]');
        const message = popup.querySelector('#contactEditMessage');
        saveButton.disabled = true;
        try {
          const response = await studentFetch('api/student/profile.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': sessionStorage.getItem('ee_csrf') || ''
            },
            body: JSON.stringify({ email, contactNumber: contact })
          });
          const result = await response.json();
          if (!response.ok || !result.success) {
            message.textContent = result.message || 'Contact information could not be updated.';
            return;
          }
          const users = allUsers();
          const target = users.find(u => u.sid === user.sid);
          if (target) {
            target.email = email;
            target.contact = contact;
            saveData('ee_users', users);
          }
          const elEmail = select('#displayEmail');
          const elContact = select('#displayContact');
          if (elEmail) elEmail.textContent = email;
          if (elContact) elContact.textContent = contact || 'Not provided';
          popup.remove();
          showToast(result.message || 'Contact information updated successfully.');
        } catch (error) {
          message.textContent = 'The contact information could not be updated. Please try again.';
        } finally {
          saveButton.disabled = false;
        }
      };
    };
  }
};

/* 13. Settings */
PAGES.settings = {
  render() {
    const notify = loadData('ee_notifications', { email: 1, sms: 0, reminders: 1 });
    const user = currentUser();
    const section = user.section || 'Not set';
    const toggle = (key, label) => `
      <div class="row sp" style="margin:10px 0">
        <span>${label}</span>
        <span class="sw"><input type="checkbox" data-notify="${key}" ${notify[key] ? 'checked' : ''}><i></i></span>
      </div>`;

    return withSidebar(`
      <h1>Settings</h1>
      <div class="card"><h3>Notifications</h3>
        ${toggle('email', 'Email notifications')}
        ${toggle('sms', 'SMS notifications')}
        ${toggle('reminders', 'Appointment reminders')}
      </div>
      <div class="card sec"><h3>Appearance</h3>
        <div class="row sp"><span>Dark mode</span>
          <span class="sw"><input type="checkbox" id="darkToggle" ${loadData('ee_theme') === 'dark' ? 'checked' : ''}><i></i></span>
        </div>
      </div>
      <div class="card sec"><h3>Account</h3>
        ${detailsList({ 'Name': user.name, 'Student ID': user.sid, 'Section': section, 'Email': user.email })}
      </div>

      <!-- Security / Change Password with Clickable Button (Not in-your-face) -->
      <div class="card sec">
        <div class="row sp" style="align-items: center;">
          <div>
            <h3 style="margin: 0 0 4px 0;">Account Password</h3>
            <p class="muted" style="margin: 0; font-size: 13px;">•••••••••••• · Protected with local student security</p>
          </div>
          <button type="button" class="btn alt sm" id="openPasswordModal" style="padding: 8px 16px; font-size: 13px; font-weight: 600;">
            Change Password
          </button>
        </div>
      </div>

      <div class="sec"><button class="btn alt" id="settingsLogout">Logout</button></div>`);
  },

  setup() {
    document.querySelectorAll('[data-notify]').forEach(box => {
      box.onchange = () => {
        const notify = loadData('ee_notifications', {});
        notify[box.dataset.notify] = box.checked ? 1 : 0;
        saveData('ee_notifications', notify);
        showToast('Notification settings saved.');
      };
    });

    select('#darkToggle').onchange = event => {
      const theme = event.target.checked ? 'dark' : 'light';
      saveData('ee_theme', theme);
      document.documentElement.dataset.theme = theme;
    };

    select('#settingsLogout').onclick = logout;

    // Change Password Modal Trigger
    select('#openPasswordModal').onclick = () => {
      const popup = showModal(`
        <h3 style="margin-top:0;">Change Password</h3>
        <p class="muted" style="margin-bottom:14px; font-size:13px;">Enter your current password and choose a new one.</p>
        <form id="modalPasswordForm" novalidate>
          ${inputField('oldPassword', 'Current password', 'password')}
          ${inputField('newPassword', 'New password', 'password')}
          ${inputField('confirmPassword', 'Confirm new password', 'password')}
          <div class="row" style="margin-top: 16px; gap: 10px;">
            <button type="button" class="btn alt" id="cancelPasswordModal" style="flex: 1;">Cancel</button>
            <button type="submit" class="btn" style="flex: 1;">Update</button>
          </div>
        </form>
      `);

      popup.querySelector('#cancelPasswordModal').onclick = () => popup.remove();
      popup.querySelector('#modalPasswordForm').onsubmit = async event => {
        event.preventDefault();
        clearErrors();
        const oldPass = popup.querySelector('#oldPassword').value;
        const newPass = popup.querySelector('#newPassword').value;
        const confirmPass = popup.querySelector('#confirmPassword').value;

        let valid = true;
        if (newPass.length < 8 || newPass.length > 72 || !/[A-Za-z]/.test(newPass) || !/[0-9]/.test(newPass)) {
          valid = showError('newPassword', 'Password must be 8–72 characters with at least one letter and one number.');
        }
        if (confirmPass !== newPass) valid = showError('confirmPassword', 'Passwords do not match.');
        if (!valid) return;

        try {
          const response = await studentFetch('api/student/password.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': sessionStorage.getItem('ee_csrf') || ''
            },
            body: JSON.stringify({ currentPassword: oldPass, newPassword: newPass })
          });
          const result = await response.json();
          if (!response.ok || !result.success) {
            return showError('oldPassword', result.message || 'The password could not be updated.');
          }
          popup.remove();
          showToast(result.message);
        } catch (error) {
          showError('oldPassword', 'The password service is unavailable. Please try again.');
        }
      };
    };
  }
};


/* ---------- 8. START THE APP ---------- */
const needsLogin = !PAGES_WITHOUT_LOGIN.includes(currentPage);

const startApplication = async () => {
  if (needsLogin) {
    try {
      const response = await studentFetch('api/student/session.php', { credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok || !result.success || !result.profile) {
        clearStudentSession();
        flashMessage(result.message || 'Please log in to continue.');
        goTo('login');
        return;
      }
      sessionStorage.setItem('ee_csrf', result.csrfToken);
      saveData('ee_users', [result.profile]);
      sessionStorage.setItem('ee_session', result.profile.sid);
    } catch (error) {
      clearStudentSession();
      flashMessage('The student session could not be verified. Please sign in again.');
      goTo('login');
      return;
    }
  }

  const page = PAGES[currentPage];
  if (!page) return;
  const usesStaticLoginShell = currentPage === 'login' && Boolean(select('#loginForm'));
  const html = usesStaticLoginShell ? '' : page.render(); // returns '' when it redirects away
  if (!usesStaticLoginShell) {
    select('#app').innerHTML = html;
  }
  if (usesStaticLoginShell) {
    page.setup();
    const passwordInput = select('#loginPassword');
    const passwordToggle = select('#togglePassword');
    if (passwordInput && passwordToggle) {
      passwordToggle.onclick = () => {
        const showingPassword = passwordInput.type === 'text';
        passwordInput.type = showingPassword ? 'password' : 'text';
        const passwordToggleIcon = passwordToggle.querySelector('i') || passwordToggle;
        passwordToggleIcon.classList.toggle('fa-eye', showingPassword);
        passwordToggleIcon.classList.toggle('fa-eye-slash', !showingPassword);
        passwordToggle.setAttribute('aria-label', showingPassword ? 'Show password' : 'Hide password');
        passwordToggle.setAttribute('aria-pressed', String(!showingPassword));
      };
    }
  } else if (html && page.setup) {
    page.setup();
  }

  // Sidebar buttons (only exist on logged-in pages)
  const menuButton = select('#menuButton');
  const sidebar = select('#sidebar');
  const sidebarBackdrop = select('#sidebarBackdrop');
  const closeSidebar = () => {
    if (sidebar) sidebar.classList.remove('open');
    if (sidebarBackdrop) sidebarBackdrop.classList.remove('open');
    if (menuButton) {
      menuButton.setAttribute('aria-expanded', 'false');
      menuButton.setAttribute('aria-label', 'Open navigation');
    }
  };
  if (menuButton) menuButton.onclick = () => {
    const isOpen = sidebar.classList.toggle('open');
    if (sidebarBackdrop) sidebarBackdrop.classList.toggle('open', isOpen);
    menuButton.setAttribute('aria-expanded', String(isOpen));
    menuButton.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
  };
  if (sidebarBackdrop) sidebarBackdrop.onclick = closeSidebar;
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeSidebar();
  });
  const sidebarLogout = select('#sidebarLogout');
  if (sidebarLogout) sidebarLogout.onclick = event => { event.preventDefault(); confirmLogout(); };

  // Show a message left by the previous page
  const message = sessionStorage.getItem('ee_flash');
  if (message) {
    sessionStorage.removeItem('ee_flash');
    showToast(message);
  }
};

startApplication();
