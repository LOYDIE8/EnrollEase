(() => {
  "use strict";

  const root = document.querySelector("#student-eligibility-manager");
  if (!root) return;

  const searchForm = document.querySelector("#eligibility-student-search");
  const studentNumberInput = document.querySelector("#eligibility-student-number");
  const editor = document.querySelector("#eligibility-record-editor");
  const message = document.querySelector("#eligibility-manager-message");
  const csrfToken = root.dataset.csrf;
  const apiUrl = "../api/admin/student-eligibility.php";
  let lastLoadedRecord = null;

  const academicStatuses = ["applicant", "active", "inactive", "graduated", "dropped", "suspended", "enrolling", "enrolled"];
  const gradeStatuses = ["complete", "missing", "incomplete", "deficient", "failed", "pending"];
  const paymentStatuses = ["paid", "unpaid", "pending"];
  const holdStatuses = ["active", "released"];
  const requirementStatuses = ["unresolved", "in_progress", "resolved"];
  const documentStatuses = ["missing", "submitted", "verified", "rejected"];

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, character => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;"
    }[character]));
  }

  function canonicalYearLevel(y) {
    if (!y) return '';
    const s = String(y).toLowerCase().trim();
    if (s.startsWith('1') || s.startsWith('first')) return '1st Year';
    if (s.startsWith('2') || s.startsWith('second')) return '2nd Year';
    if (s.startsWith('3') || s.startsWith('third')) return '3rd Year';
    if (s.startsWith('4') || s.startsWith('fourth')) return '4th Year';
    return y;
  }

  function options(values, selected, labels = {}) {
    return values.map(value =>
      `<option value="${escapeHtml(value)}" ${String(value) === String(selected) ? "selected" : ""}>${escapeHtml(labels[value] || value)}</option>`
    ).join("");
  }

  function subjectRow(subject = {}) {
    return `<tr data-subject-row>
      <td><input data-field="subject" maxlength="150" value="${escapeHtml(subject.subject || "")}" placeholder="e.g. IT101" required></td>
      <td><select data-field="isRequired">
        <option value="true" ${subject.isRequired ? "selected" : ""}>Required</option>
        <option value="false" ${subject.isRequired ? "" : "selected"}>Not required</option>
      </select></td>
      <td><input data-field="grade" type="number" min="0" max="100" step="0.01" value="${escapeHtml(subject.grade ?? "")}" placeholder="Grade (e.g. 1.50)"></td>
      <td><select data-field="gradeStatus">${options(gradeStatuses, subject.gradeStatus || "complete")}</select></td>
      <td style="text-align:center;"><button type="button" class="btn-remove-row" data-action="remove-row" title="Remove this subject">✕ Remove</button></td>
    </tr>`;
  }

  function financialRow(record = {}) {
    return `<tr data-financial-row data-id="${escapeHtml(record.id ?? "")}">
      <td>
        <div class="currency-input-wrap">
          <span class="currency-symbol">₱</span>
          <input data-field="balance" type="number" min="0" max="99999999.99" step="0.01" value="${escapeHtml(record.balance ?? "0.00")}" required>
        </div>
      </td>
      <td><select data-field="paymentStatus">${options(paymentStatuses, record.paymentStatus || "paid")}</select></td>
      <td><input data-field="description" maxlength="255" value="${escapeHtml(record.description || "")}" placeholder="Description (e.g. Tuition Balance)"></td>
      <td style="text-align:center;"><button type="button" class="btn-remove-row" data-action="remove-row" title="Remove this financial record">✕ Remove</button></td>
    </tr>`;
  }

  function holdRow(hold = {}) {
    return `<tr data-hold-row data-id="${escapeHtml(hold.id ?? "")}">
      <td><input data-field="reason" maxlength="500" value="${escapeHtml(hold.reason || "")}" placeholder="Reason for hold" required></td>
      <td><select data-field="status">${options(holdStatuses, hold.status || "active")}</select></td>
      <td style="text-align:center;"><button type="button" class="btn-remove-row" data-action="remove-row" title="Remove this hold">✕ Remove</button></td>
    </tr>`;
  }

  function requirementRow(requirement = {}) {
    return `<tr data-requirement-row data-id="${escapeHtml(requirement.id ?? "")}">
      <td><input data-field="name" maxlength="180" value="${escapeHtml(requirement.name || "")}" placeholder="Requirement name" required></td>
      <td><select data-field="status">${options(requirementStatuses, requirement.status || "unresolved", {
        unresolved: "Unresolved",
        in_progress: "In progress",
        resolved: "Resolved"
      })}</select></td>
      <td><select data-field="blocksEnrollment">
        <option value="true" ${requirement.blocksEnrollment !== false ? "selected" : ""}>Blocks enrollment</option>
        <option value="false" ${requirement.blocksEnrollment === false ? "selected" : ""}>Does not block</option>
      </select></td>
      <td style="text-align:center;"><button type="button" class="btn-remove-row" data-action="remove-row" title="Remove this requirement">✕ Remove</button></td>
    </tr>`;
  }

  function documentRow(documentRecord) {
    return `<tr data-document-row data-document-type-id="${escapeHtml(documentRecord.documentTypeId)}">
      <td><strong>${escapeHtml(documentRecord.name)}</strong></td>
      <td><select data-field="status">${options(documentStatuses, documentRecord.status)}</select></td>
    </tr>`;
  }

  function renderRecord(record) {
    lastLoadedRecord = JSON.parse(JSON.stringify(record));
    const student = record.student;
    const eligibility = record.eligibility;
    const initials = (student.name || "S").trim().split(/\s+/).map(p => p[0]).slice(0, 2).join("").toUpperCase();

    const verdictHtml = eligibility.eligible
      ? `<div class="eligibility-verdict verdict-pass">
          <div class="verdict-icon">✓</div>
          <div class="verdict-body">
            <strong>Student is currently ELIGIBLE for enrollment booking</strong>
            <p>All academic prerequisites, financial clearances, document validations, and administrative checks have passed.</p>
          </div>
        </div>`
      : `<div class="eligibility-verdict verdict-fail">
          <div class="verdict-icon">✗</div>
          <div class="verdict-body">
            <strong>Student is currently NOT ELIGIBLE for enrollment booking</strong>
            <p>The student has the following outstanding condition(s) blocking enrollment:</p>
            <ul>${(eligibility.reasons || ["Requirements incomplete."]).map(r => `<li>${escapeHtml(r)}</li>`).join("")}</ul>
          </div>
        </div>`;

    const subjectsRows = (record.subjects && record.subjects.length)
      ? record.subjects.map(subjectRow).join("")
      : `<tr class="empty-state-row"><td colspan="5">No required subjects on record. Click "Add subject" to add one.</td></tr>`;

    const financialRows = (record.financialRecords && record.financialRecords.length)
      ? record.financialRecords.map(financialRow).join("")
      : `<tr class="empty-state-row"><td colspan="4">No financial obligations or balance records on file. (Clean clearance)</td></tr>`;

    const holdsRows = (record.holds && record.holds.length)
      ? record.holds.map(holdRow).join("")
      : `<tr class="empty-state-row"><td colspan="3">No active enrollment holds on file.</td></tr>`;

    const requirementsRows = (record.requirements && record.requirements.length)
      ? record.requirements.map(requirementRow).join("")
      : `<tr class="empty-state-row"><td colspan="4">No unresolved requirements on file.</td></tr>`;

    editor.innerHTML = `
      <section class="panel">
        <div class="eligibility-student-header">
          <div class="eligibility-avatar">${escapeHtml(initials)}</div>
          <div class="eligibility-header-details">
            <h2>${escapeHtml(student.name)}</h2>
            <div class="eligibility-meta-tags">
              <span class="meta-tag tag-id">ID: ${escapeHtml(student.studentNumber)}</span>
              <span class="meta-tag">${escapeHtml(student.email)}</span>
              <span class="meta-tag">${escapeHtml(student.program)}</span>
              <span class="meta-tag ${student.accountActive ? 'tag-active' : 'tag-inactive'}">Account: ${student.accountActive ? 'Active' : 'Inactive'}</span>
            </div>
          </div>
        </div>

        ${verdictHtml}
      </section>

      <form id="eligibility-record-form">
        <section class="panel">
          <h2>Student Academic Status</h2>
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;">
            <div>
              <label for="eligibility-year-level">Year level</label>
              <select id="eligibility-year-level" name="yearLevel">
                <option value="">Not set</option>
                ${options(["1st Year", "2nd Year", "3rd Year", "4th Year"], canonicalYearLevel(student.yearLevel))}
              </select>
            </div>
            <div>
              <label for="eligibility-academic-status">Academic status</label>
              <select id="eligibility-academic-status" name="academicStatus">${options(academicStatuses, student.academicStatus)}</select>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="toolbar">
            <h2>Required Subjects and Grades</h2>
            <button class="button" type="button" data-add-row="subjects">+ Add subject</button>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th style="width:35%;">Subject</th><th style="width:20%;">Requirement</th><th style="width:20%;">Grade</th><th style="width:15%;">Status</th><th style="width:10%;text-align:center;">Action</th></tr></thead>
            <tbody data-rows="subjects">${subjectsRows}</tbody>
          </table></div>
        </section>

        <section class="panel">
          <div class="toolbar">
            <h2>Financial Obligations & Balances</h2>
            <button class="button" type="button" data-add-row="financialRecords">+ Add record</button>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th style="width:25%;">Balance</th><th style="width:25%;">Payment Status</th><th style="width:40%;">Description</th><th style="width:10%;text-align:center;">Action</th></tr></thead>
            <tbody data-rows="financialRecords">${financialRows}</tbody>
          </table></div>
        </section>

        <section class="panel">
          <div class="toolbar">
            <h2>Enrollment Holds</h2>
            <button class="button" type="button" data-add-row="holds">+ Add hold</button>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th style="width:65%;">Reason</th><th style="width:25%;">Hold Status</th><th style="width:10%;text-align:center;">Action</th></tr></thead>
            <tbody data-rows="holds">${holdsRows}</tbody>
          </table></div>
        </section>

        <section class="panel">
          <div class="toolbar">
            <h2>Other Enrollment Requirements</h2>
            <button class="button" type="button" data-add-row="requirements">+ Add requirement</button>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th style="width:45%;">Requirement</th><th style="width:25%;">Status</th><th style="width:20%;">Enrollment Impact</th><th style="width:10%;text-align:center;">Action</th></tr></thead>
            <tbody data-rows="requirements">${requirementsRows}</tbody>
          </table></div>
        </section>

        <section class="panel">
          <h2>Required Documents Verification</h2>
          <div class="table-wrap"><table>
            <thead><tr><th style="width:60%;">Document Name</th><th style="width:40%;">Verification Status</th></tr></thead>
            <tbody data-rows="documents">${record.documents.map(documentRow).join("")}</tbody>
          </table></div>
        </section>

        <div class="admin-form-actions">
          <button class="button primary" type="submit">Save eligibility records</button>
          <button class="button secondary" type="button" id="btn-revert-changes">Revert changes</button>
        </div>
      </form>`;
    editor.hidden = false;
  }

  function setMessage(text, isError = false) {
    message.textContent = text;
    message.className = isError ? "alert" : "notice";
  }

  async function parseResponse(response) {
    const result = await response.json();
    if (response.status === 401) {
      window.location.replace("../admin-login/admin-login.html");
      throw new Error("Your administrator session has expired.");
    }
    if (!response.ok || !result.success) {
      throw new Error(result.message || "The student record could not be loaded.");
    }
    return result;
  }

  async function loadRecord(studentNumber) {
    setMessage("Loading student eligibility records…");
    editor.hidden = true;
    const response = await fetch(`${apiUrl}?studentNumber=${encodeURIComponent(studentNumber)}`, {
      credentials: "same-origin"
    });
    const result = await parseResponse(response);
    renderRecord(result.record);
    setMessage("Student eligibility records loaded.");
  }

  function collectRows(selector, mapRow) {
    return Array.from(editor.querySelectorAll(selector)).map(mapRow);
  }

  function collectId(row) {
    const id = row.dataset.id || "";
    return id === "" ? null : Number(id);
  }

  function collectRecord() {
    const form = editor.querySelector("#eligibility-record-form");
    const subjects = collectRows("[data-subject-row]", row => ({
      subject: row.querySelector('[data-field="subject"]').value.trim(),
      isRequired: row.querySelector('[data-field="isRequired"]').value === "true",
      grade: row.querySelector('[data-field="grade"]').value,
      gradeStatus: row.querySelector('[data-field="gradeStatus"]').value
    })).filter(row => row.subject !== "");

    const financialRecords = collectRows("[data-financial-row]", row => ({
      id: collectId(row),
      balance: row.querySelector('[data-field="balance"]').value,
      paymentStatus: row.querySelector('[data-field="paymentStatus"]').value,
      description: row.querySelector('[data-field="description"]').value
    }));

    const holds = collectRows("[data-hold-row]", row => ({
      id: collectId(row),
      reason: row.querySelector('[data-field="reason"]').value,
      status: row.querySelector('[data-field="status"]').value
    })).filter(row => row.reason.trim() !== "");

    const requirements = collectRows("[data-requirement-row]", row => ({
      id: collectId(row),
      name: row.querySelector('[data-field="name"]').value,
      status: row.querySelector('[data-field="status"]').value,
      blocksEnrollment: row.querySelector('[data-field="blocksEnrollment"]').value === "true"
    })).filter(row => row.name.trim() !== "");

    const documents = collectRows("[data-document-row]", row => ({
      documentTypeId: Number(row.dataset.documentTypeId),
      status: row.querySelector('[data-field="status"]').value
    }));

    return {
      csrf_token: csrfToken,
      studentNumber: studentNumberInput.value.trim(),
      yearLevel: form.elements.yearLevel.value || null,
      academicStatus: form.elements.academicStatus.value,
      subjects,
      financialRecords,
      holds,
      requirements,
      documents
    };
  }

  root.addEventListener("submit", async event => {
    if (event.target === searchForm) {
      event.preventDefault();
      if (!searchForm.reportValidity()) return;
      try {
        await loadRecord(studentNumberInput.value.trim());
      } catch (error) {
        setMessage(error.message || "The student record could not be loaded.", true);
      }
      return;
    }

    if (event.target.id !== "eligibility-record-form") return;
    event.preventDefault();
    const submitButton = event.target.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    setMessage("Saving eligibility records…");
    try {
      const response = await fetch(apiUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(collectRecord())
      });
      const result = await parseResponse(response);
      renderRecord(result.record);
      setMessage("Eligibility records saved and the booking checks have been refreshed.");
    } catch (error) {
      setMessage(error.message || "The student record could not be saved.", true);
    } finally {
      if (submitButton.isConnected) submitButton.disabled = false;
    }
  });

  root.addEventListener("click", event => {
    // 1. Remove Row Button handler
    const removeBtn = event.target.closest("[data-action='remove-row']");
    if (removeBtn) {
      event.preventDefault();
      const row = removeBtn.closest("tr");
      const tbody = row ? row.closest("tbody") : null;
      if (row) row.remove();
      if (tbody && tbody.querySelectorAll("tr:not(.empty-state-row)").length === 0) {
        const colCount = tbody.closest("table").querySelectorAll("thead th").length || 4;
        tbody.innerHTML = `<tr class="empty-state-row"><td colspan="${colCount}">No records. Click "+ Add" above to add one.</td></tr>`;
      }
      return;
    }

    // 2. Revert Changes Button handler
    const revertBtn = event.target.closest("#btn-revert-changes");
    if (revertBtn) {
      event.preventDefault();
      if (lastLoadedRecord) {
        renderRecord(lastLoadedRecord);
        setMessage("All unsaved changes have been reverted.", false);
      }
      return;
    }

    // 3. Add Row Button handler
    const addBtn = event.target.closest("[data-add-row]");
    if (!addBtn) return;
    event.preventDefault();

    const targetType = addBtn.dataset.addRow;
    const targetKey = (targetType === "financial" || targetType === "financialRecords") ? "financialRecords" : targetType;
    const rows = editor.querySelector(`[data-rows="${targetKey}"]`);
    if (!rows) return;

    // Remove any empty state placeholder
    const emptyPlaceholder = rows.querySelector(".empty-state-row");
    if (emptyPlaceholder) emptyPlaceholder.remove();

    const templates = {
      subjects: subjectRow(),
      financial: financialRow(),
      financialRecords: financialRow(),
      holds: holdRow(),
      requirements: requirementRow()
    };

    if (templates[targetKey]) {
      rows.insertAdjacentHTML("beforeend", templates[targetKey]);
      const newlyAddedInput = rows.lastElementChild.querySelector("input");
      if (newlyAddedInput) newlyAddedInput.focus();
    }
  });

  const initialStudentNumber = studentNumberInput.value.trim();
  if (initialStudentNumber) {
    loadRecord(initialStudentNumber).catch(error => {
      setMessage(error.message || "The student record could not be loaded.", true);
    });
  }
})();
