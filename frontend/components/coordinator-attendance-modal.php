<div class="modal-overlay coordinator-attendance-overlay" id="coordinatorAttendanceDetailsModal">
  <section class="modal modal-lg coordinator-attendance-modal" role="dialog" aria-modal="true" aria-labelledby="coordinatorAttendanceModalTitle" tabindex="-1">
    <header class="coordinator-attendance-modal-header">
      <div class="coordinator-attendance-header-main">
        <div class="coordinator-attendance-student-copy">
          <span class="coordinator-student-modal-eyebrow">Daily time record</span>
          <h2 class="modal-title" id="coordinatorAttendanceModalTitle">Student Attendance</h2>
          <p id="coordinatorAttendanceStudentMeta">Loading student record…</p>
        </div>
        <div class="coordinator-attendance-summary" id="coordinatorAttendanceSummary"></div>
      </div>
      <button type="button" class="coordinator-student-modal-close" onclick="closeModal('coordinatorAttendanceDetailsModal')" aria-label="Close attendance details">&times;</button>
    </header>
    <div class="coordinator-attendance-modal-body">
      <div class="coordinator-attendance-table-wrap table-wrap">
        <table class="coordinator-dtr-table" data-skip-table-tools>
          <thead><tr><th>Date</th><th>AM In</th><th>AM Out</th><th>PM In</th><th>PM Out</th><th>Hours</th><th>Status</th><th>Remarks</th></tr></thead>
          <tbody id="coordinatorAttendanceRows"><tr><td colspan="8" class="text-center text-muted py-6">Loading attendance records…</td></tr></tbody>
        </table>
      </div>
      <p class="coordinator-attendance-empty-hint" id="coordinatorAttendanceEmptyHint" hidden>No attendance days have been recorded for this student yet.</p>
    </div>
    <footer class="coordinator-attendance-modal-footer">
      <span class="text-xs text-muted" id="coordinatorAttendanceRecordCount"></span>
      <button type="button" class="btn btn-primary" id="coordinatorAttendanceDownload" disabled>Download DTR (PDF)</button>
    </footer>
  </section>
</div>

<script>
(function () {
  const modalId = 'coordinatorAttendanceDetailsModal';
  const modal = document.getElementById(modalId);
  const studentMeta = document.getElementById('coordinatorAttendanceStudentMeta');
  const title = document.getElementById('coordinatorAttendanceModalTitle');
  const summary = document.getElementById('coordinatorAttendanceSummary');
  const rows = document.getElementById('coordinatorAttendanceRows');
  const recordCount = document.getElementById('coordinatorAttendanceRecordCount');
  const emptyHint = document.getElementById('coordinatorAttendanceEmptyHint');
  const download = document.getElementById('coordinatorAttendanceDownload');
  let requestSequence = 0;
  let currentDtrPayload = null;

  const escapeText = value => String(value ?? '');
  const appendCell = (row, value, className) => {
    const cell = document.createElement('td');
    if (className) cell.className = className;
    cell.textContent = value || '—';
    row.appendChild(cell);
  };

  async function openAttendanceDetails(studentRow) {
    const studentId = Number(studentRow.dataset.attendanceStudent || 0);
    if (!studentId) return;
    const currentRequest = ++requestSequence;
    title.textContent = 'Student Attendance';
    studentMeta.textContent = 'Loading student record…';
    summary.replaceChildren();
    rows.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-6">Loading attendance records…</td></tr>';
    recordCount.textContent = '';
    emptyHint.hidden = true;
    currentDtrPayload = null;
    download.disabled = true;
    if (typeof openModal === 'function') openModal(modalId);

    try {
      const response = await fetch('/ojtrack/coordinator/attendance.php?attendance_student=' + encodeURIComponent(studentId), {
        headers: {'Accept': 'application/json'},
        credentials: 'same-origin'
      });
      const payload = await response.json();
      if (currentRequest !== requestSequence || !modal.closest('.modal-overlay')?.classList.contains('open')) return;
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Unable to load attendance records.');

      const student = payload.student || {};
      const attendance = Array.isArray(payload.records) ? payload.records : [];
      currentDtrPayload = payload;
      download.disabled = false;
      title.textContent = student.name || 'Student Attendance';
      studentMeta.textContent = [student.student_id_no, student.program, student.company_name].filter(Boolean).join(' · ') || 'Student DTR';
      summary.replaceChildren();
      [
        ['Attendance days', String(attendance.length)],
        ['Total recorded hours', attendance.reduce((total, item) => total + (Number(item.hours_rendered) || 0), 0).toFixed(2) + 'h']
      ].forEach(([label, value]) => {
        const item = document.createElement('div');
        item.className = 'coordinator-attendance-summary-item';
        const itemLabel = document.createElement('span');
        itemLabel.textContent = label;
        const itemValue = document.createElement('strong');
        itemValue.textContent = value;
        item.append(itemLabel, itemValue);
        summary.appendChild(item);
      });

      rows.replaceChildren();
      attendance.forEach(item => {
        const row = document.createElement('tr');
        appendCell(row, item.date ? new Date(item.date + 'T12:00:00').toLocaleDateString(undefined, {year:'numeric',month:'short',day:'numeric'}) : '—', 'font-bold');
        appendCell(row, escapeText(item.morning_in), 'td-mono');
        appendCell(row, escapeText(item.morning_out), 'td-mono');
        appendCell(row, escapeText(item.afternoon_in), 'td-mono');
        appendCell(row, escapeText(item.afternoon_out), 'td-mono');
        appendCell(row, Number(item.hours_rendered) > 0 ? Number(item.hours_rendered).toFixed(2) + 'h' : '—', 'td-mono font-bold');
        appendCell(row, item.status || 'Not Logged');
        appendCell(row, escapeText(item.remarks), 'text-xs text-muted');
        rows.appendChild(row);
      });
      if (!attendance.length) {
        rows.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-6">No attendance records found.</td></tr>';
        emptyHint.hidden = false;
      }
      recordCount.textContent = attendance.length + (attendance.length === 1 ? ' DTR day' : ' DTR days');
    } catch (error) {
      if (currentRequest !== requestSequence) return;
      rows.replaceChildren();
      const errorRow = document.createElement('tr');
      const errorCell = document.createElement('td');
      errorCell.colSpan = 8;
      errorCell.className = 'text-center text-danger py-6';
      errorCell.textContent = error.message || 'Unable to load attendance records.';
      errorRow.appendChild(errorCell);
      rows.appendChild(errorRow);
      studentMeta.textContent = 'The attendance record could not be loaded.';
    }
  }

  window.openCoordinatorAttendanceDetails = function (studentId) {
    const id = Number(studentId || 0);
    if (id) openAttendanceDetails({dataset: {attendanceStudent: String(id)}});
  };

  document.addEventListener('click', function (event) {
    const row = event.target.closest('.coordinator-attendance-student-row');
    if (!row || event.defaultPrevented || event.button !== 0 || event.target.closest('a,button,input,select,textarea,label')) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    openAttendanceDetails(row);
  });
  document.addEventListener('keydown', function (event) {
    const row = event.target.closest('.coordinator-attendance-student-row');
    if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      openAttendanceDetails(row);
    }
  });

  download.addEventListener('click', async function () {
    if (!currentDtrPayload?.student?.id) return;
    const originalLabel = download.textContent;
    download.disabled = true;
    download.textContent = 'Preparing PDF…';
    let objectUrl = null;
    try {
      const url = new URL('/ojtrack/coordinator/attendance.php', window.location.origin);
      url.searchParams.set('download_attendance_pdf', String(currentDtrPayload.student.id));
      const response = await fetch(url, {credentials:'same-origin',cache:'no-store'});
      if (!response.ok || !response.headers.get('content-type')?.includes('application/pdf')) {
        throw new Error('The server did not return a PDF.');
      }
      const pdfBlob = await response.blob();
      if (pdfBlob.size < 500 || (await pdfBlob.slice(0, 5).text()) !== '%PDF-') {
        throw new Error('The generated PDF is empty or invalid.');
      }
      objectUrl = URL.createObjectURL(pdfBlob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = 'DTR_' + String(currentDtrPayload.student.student_id_no || 'student').replace(/[^A-Za-z0-9_-]+/g, '_') + '.pdf';
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.showAppToast?.('DTR PDF downloaded.', {type:'success'});
    } catch (error) {
      console.error('Could not download DTR PDF:', error);
      window.showAppToast?.('The DTR PDF could not be created. Please try again.', {type:'error'});
    } finally {
      if (objectUrl) setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      download.disabled = false;
      download.textContent = originalLabel;
    }
  });
})();
</script>
