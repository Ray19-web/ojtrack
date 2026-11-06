/* Student guides describe existing controls without opening or submitting them. */
window.buildOJTrackStudentGuide = function ({page, context, add}) {
  if (context) {
    const id = context.id;
    if (id === 'journalWriteDialog' || id === 'journalEntryForm') {
      add('[name="entry_date"]', 'Choose the training date', 'Use the date of the work you are recording. When editing, review the existing entry before submitting a revision.');
      add('[name="hours_rendered"]', 'Record your journal hours', 'Enter the hours for this journal entry. Journal hours describe your work log; official credited hours come from company attendance.');
      add('[name="activities"]', 'Describe your activities', 'Write the tasks you completed and the work you contributed.');
      add('[name="learnings"], [name="challenges"]', 'Explain learning and challenges', 'Complete both learning and challenges fields. Describe what you learned and how you addressed any difficulties.');
      add('.journal-image-picker', 'Add activity photos', 'Choose multiple supported images if you have supporting proof. Review the previews and remove unwanted new selections before submitting. Follow the size limits shown in the form.');
      add('#journalWriteSubmit, button[type="submit"]', 'Submit your entry or revision', 'This sends the entry for coordinator review. Approved entries cannot be revised. The guide never submits the form for you.');
    } else if (id === 'journalDetailsDialog' || id === 'journalDetailContent') {
      add('#journalDetailContent', 'Read the saved journal', 'Review the date, status, activities, learning, challenges, and any coordinator feedback.');
      add('.announcement-item-attachment, [data-attachment-preview], .journal-attachment, a[href*="download.php"]', 'View supporting images', 'Choose an attachment to inspect the submitted proof. Close the preview to return to this journal.');
      add('#journalDialogFooter a, a[href*="view=edit"]', 'Revise an editable entry', 'Edit Entry is available for pending or returned entries. It opens the same form used for a new entry, with the saved values filled in.');
    } else if (id === 'uploadModal' || id === 'reportModal') {
      const requirement = id === 'uploadModal';
      add(requirement ? '#uploadDocSub' : '#reportModalSub', 'Check the assigned item', 'Confirm that this is the coordinator-assigned item you intend to submit. Approved items cannot be replaced.');
      add('#reportSubmissionInstructions', 'Follow the coordinator instructions', 'Read the assigned instructions before attaching your report.');
      add('.upload-area', 'Choose your files', 'Browse or drag files into this area. Multiple files are supported; follow the allowed file types, per-file size, and total limits displayed here. A resubmission replaces the previous file set.');
      add('.attachment-preview, .attachment-preview-list', 'Review selected files', 'Inspect your selected files and use a file’s remove control to exclude it before submitting.');
      add('.requirement-add-files', 'Add more files without scrolling', 'This footer button opens the file picker again while keeping the selected files accessible.');
      add('[name="notes"], [name="remarks"], [name="student_notes"]', 'Add optional notes', 'Include any context your coordinator needs for this submission.');
      add('button[type="submit"]', 'Send files for review', 'Review the selected files, then submit. Wait for the confirmation and status feedback. This guide does not upload or submit anything.');
    } else if (id === 'studentEvaluationDialog') {
      add('.evaluation-review-record', 'Check the evaluation record', 'This identifies the assigned form, trainee, company evaluator, and completion date.');
      add('.evaluation-review-sections', 'Read the actual saved scores', 'Questions are grouped by the assigned form’s sections. Scores show the earned value and maximum; ratings follow that form’s rules. Missing scores are not treated as zero.');
      add('.evaluation-review-overall, .evaluation-result-comments', 'Review the result and feedback', 'Check the overall result and any saved supervisor comments. Students cannot edit company evaluations.');
      add('#studentEvaluationPdf:not([hidden])', 'Download this evaluation', 'Download only this completed evaluation as a PDF, using the paper-form layout, saved scores, feedback, and blank signing fields.');
    } else if (id === 'newThreadModal') {
      add('.message-participant-select > summary', 'Choose your participants', 'Expand this picker to choose your assigned coordinator, company supervisor, or both. Search inside, use Select all matching, and check the selection count. Filtering retains your choices. Other contacts are not available here.');
      add('#messageParticipantSearch', 'Find an assigned contact', 'Search by name, role, or company. Filtering retains selections you already made.');
      add('[data-message-select-visible]', 'Select matching contacts', 'Select all matching chooses the currently shown contacts. Check the selected count before continuing.');
      add('#messageParticipantList', 'Check who will receive the message', 'One selected contact uses a direct conversation. Selecting both starts a group discussion in which both contacts can read the messages.');
      add('textarea[name="message"]', 'Write your first message', 'Enter the message you want the selected participants to receive.');
      add('button[type="submit"]', 'Start the conversation', 'At least one participant and a message are required. Review both before choosing Start Conversation. The guide never sends a message.');
    } else if (id === 'monthlyCompilationModal') {
      add('#compilationMonth', 'Choose a completed month', 'Select a month that has fully ended. The compilation uses your saved attendance and journal entries for that month.');
      add('.compilation-month-form', 'Load the month', 'Apply month loads the saved-record summary. Reset clears the selected month.');
      add('.compilation-summary', 'Review the saved-record totals', 'Check present days, journal count, and DTR hours. Journal hours and official attendance hours are different records.');
      add('a[href*="monthly_pdf"]', 'Download the compilation PDF', 'Download a paginated monthly report, including the coordinator-style DTR and saved journals.');
      add('.compilation-actions button[type="submit"]', 'Compile and submit', 'This generates and sends the PDF report to your coordinator for review. Downloading alone does not submit it. This guide never submits the report.');
    } else if (id === 'profileModal') {
      add('.profile-record-section', 'Review your official details', 'Check your account, student ID, academic term, OJT assignments, and attendance hours. These records are read-only; contact your coordinator or administrator about corrections.');
      add('.profile-account-section', 'Update your contact details', 'Edit your available personal details below. Contact email is optional; continue using your student ID to sign in.');
      add('input[type="file"], .avatar-upload', 'Update your photo', 'Use the profile photo control and follow its displayed file limits.');
      add('button[type="submit"]', 'Save your profile', 'Check the changes before saving. The guide does not change your profile.');
    } else if (id === 'passwordModal') {
      add('[name="current_password"]', 'Confirm your current password', 'Enter the password you currently use to sign in.');
      add('[name="new_password"], [name="confirm_password"]', 'Choose and confirm a new password', 'Use a private password that meets the displayed requirements, and enter the same new password in the confirmation field.');
      add('button[type="submit"]', 'Save the new password', 'Submit only when the fields are correct. The guide never reads or saves your password.');
    }
    return;
  }
  if (page.endsWith('/onboarding')) {
    add('.stepper, .steps', 'Complete your setup', 'Follow the setup stages shown here. Submit the requirements assigned to you rather than creating new requirement types.');
    add('#onboarding-documents-form', 'Attach your assigned documents', 'Choose supported files for each pending or returned item. Review selected files and remove unwanted selections before submitting. Approved files are locked.');
    add('#onboarding-submit', 'Submit your setup documents', 'Check the files and displayed limits, then submit the pending documents. Your coordinator reviews them.');
  } else if (page.endsWith('/dashboard') || page.endsWith('/progress')) {
    add('.dashboard-stats', 'Check your training totals', 'Review official hours, approved requirements, OJT progress, and remaining hours for your current enrollment.');
    add('.dashboard-insights', 'Review recent training activity', 'Use these summaries to check your recent attendance and training activity.');
    add('#ojt-progress', 'Track your completion progress', 'Compare the displayed recorded hours and requirements with your assigned training target. Contact your coordinator about missing or incorrect records.');
  } else if (page.endsWith('/requirements')) {
    add('.table-tools-actions', 'Find an assigned requirement', 'Use search and available table controls to find a document. Requirement types are assigned by your coordinator; students submit files against those assignments.');
    add('#reqTable', 'Read status and coordinator remarks', 'Check the deadline, submission date, review status, coordinator remarks, and attached files for each requirement.');
    add('.requirement-submit-row', 'Open the submission dialog', 'Choose a pending or returned row, or its upload button, to attach files. You can select multiple files, remove unwanted selections, and add more from the footer.');
    add('[data-locked-feedback]', 'Approved requirements are locked', 'An approved requirement cannot be changed or resubmitted. Choosing it explains the restriction; its file links remain available.');
    add('#reqTable .submission-file-list, #reqTable details', 'Inspect submitted attachments', 'Expand an attachment list or choose a file link to review what was submitted.');
  } else if (page.endsWith('/attendance')) {
    add('.table-tools-actions', 'Search, filter, or download the DTR', 'Use the available table controls to find records, choose a month, or export. The export menu includes CSV and Download DTR (PDF).');
    add('#attTable', 'Read company-recorded attendance', 'AM and PM columns show time in and time out. Total Hours shows credited attendance time. This page is view-only; contact your supervisor about corrections.');
  } else if (page.endsWith('/journal')) {
    add('a[href*="view=form"]', 'Write your daily entry', 'Write Daily Entry and Log Entry open the same centered form. Record the date, hours, activities, learning, and challenges, with optional multiple activity images.');
    add('a[href*="view=detail"], [data-journal-entry], .journal-entry-row', 'Open a saved entry', 'Choose an entry to read its saved details, status, feedback, and attachments in a modal. Pending or returned entries can be revised through Edit Entry.');
    add('.journal-list, .journal-entries, .card:has(a[href*="view=detail"])', 'Follow the review status', 'Your coordinator reviews submitted journals. Approved entries retain their saved history and are not editable.');
  } else if (page.endsWith('/reports')) {
    add('.table-tools-actions', 'Find assigned reports', 'Search your assigned reports and use the available table controls. Narrative report requirements and deadlines come from your coordinator.');
    add('.report-submit-row', 'Submit or revise an assigned report', 'Choose an editable row or its upload/update action to open the report dialog. Read coordinator instructions and attach the required files. Resubmitting replaces the file set.');
    add('[data-locked-feedback]', 'Approved reports stay locked', 'Approved reports cannot be changed or resubmitted. Their attached files remain available to view.');
    add('.report-monthly-action', 'Create your monthly compilation', 'Open Monthly compilation, choose a completed month, and apply it. Review the saved DTR and journal totals, then download the PDF or use Compile & Submit to send it for review.');
    add('.report-guidelines summary', 'Read the report guidelines', 'Expand these notes for report-content guidance. Submit only the report types and milestones assigned by your coordinator.');
  } else if (page.endsWith('/evaluation')) {
    add('.student-evaluation-table', 'Review assigned evaluations', 'This list contains assigned midterm, final, or custom evaluations. Your coordinator decides which evaluations are required. Pending evaluations have no results yet.');
    add('[data-view-evaluation]', 'Read a completed result', 'Choose View results or a completed row to open the saved section-by-section scores, ratings, and supervisor feedback.');
    add('.student-evaluation-awaiting', 'Understand pending results', 'Results become available after the company evaluator completes the assigned form. Students do not fill in company scores.');
    add('.table-export-menu', 'Download evaluation PDFs', 'The export menu offers CSV and Evaluations (PDF). The PDF includes the assignment overview and completed results. For a single result, open its modal and choose Download PDF.');
  } else if (page.endsWith('/messages')) {
    add('.page-heading .btn-primary, .chat-empty-state button', 'Start a conversation', 'New Conversation opens the participant picker. Select your assigned coordinator, company supervisor, or both. Search and Select all matching help you choose recipients.');
    add('.chat-inbox-tabs', 'Switch conversation folders', 'Inbox contains active discussions. Archived conversations can be opened and restored.');
    add('.chat-threads, .chat-thread-list, .thread-list', 'Open a discussion', 'Choose a conversation to read its messages. In a group conversation, all participants can read the discussion.');
    add('.chat-main', 'Read and reply', 'Read the selected conversation and use its message box to reply. Review the recipient or group before sending. Archive moves a conversation out of your inbox.');
  } else if (page.endsWith('/announcements')) {
    add('[data-tour-announcement-search]', 'Search announcements', 'Search by title, message, or author using the compact toolbar. Search works together with the category filter.');
    add('[data-tour-announcement-filter]', 'Filter by category', 'Open the filter icon to choose a category. Reset clears the search and category. Students read notices; posting and management actions are not available here.');
    add('[data-tour-announcement-post]', 'Read the notice', 'Each card shows the author, date, category, audience, and message. Open an image or file attachment to inspect it.');
  } else if (page === 'certificate' || page.endsWith('/certificate')) {
    add('#certificate', 'View your issued certificate', 'Your official certificate appears when it has been issued. Check its details and contact your coordinator about any corrections.');
    add('button[onclick*="downloadCert"]', 'Download your certificate', 'Use Download to save the issued certificate. It is separate from the evaluation PDF and monthly report.');
    add('.empty-state, .card', 'Certificate availability', 'If no certificate has been issued, follow the status or instructions shown here and contact your coordinator.');
  }
};
