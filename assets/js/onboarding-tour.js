/* OJTRACK shared, English, page-aware onboarding tour. */
(function () {
  const body = document.body;
  const userId = body.dataset.tourUserId;
  if (!userId) return;

  const prefix = 'ojtrack-tour:' + userId + ':';
  const safeGet = key => { try { return localStorage.getItem(prefix + key); } catch (_) { return null; } };
  const safeSet = (key, value) => { try { localStorage.setItem(prefix + key, value); } catch (_) {} };
  const safeRemove = key => { try { localStorage.removeItem(prefix + key); } catch (_) {} };
  let steps = [];
  let currentStep = 0;
  let dontShowAgain = safeGet('dont-show') === '1';
  let shade;
  let card;
  let previousFocus;
  let highlighted;

  const copy = {
    en: {
      eyebrow: 'OJTRACK QUICK GUIDE', title: 'How this page works',
      step: (index, total) => 'Step ' + index + ' of ' + total,
      back: 'Back', next: 'Next', finish: 'Finish', skip: 'Skip tour', close: 'Close guide',
      dont: 'Don’t show this automatically again',
      navTitle: 'Move around OJTRACK',
      navText: 'Use the menu on the left to open another page. The highlighted item shows where you are.',
      pageTitle: title => title + ' page',
      pageText: title => 'This is the ' + title + ' page. Follow the tips to find the main actions and records.',
      addTitle: 'Add or manage records',
      addText: label => 'Choose “' + label + '” to start this page’s main action.',
      createText: label => 'Choose “' + label + '” to open the form. Enter the required details, then save.',
      toolsTitle: 'Find and organize records',
      toolsText: features => 'Use ' + features + ' to find records and adjust what the table shows.',
      bulkTitle: 'Select several records',
      bulkText: detail => 'Use the checkbox in the table heading to select the records on this page, or check individual rows. The bulk actions appear after you select records. ' + detail,
      rowsTitle: 'Work with a record',
      rowText: label => label
        ? 'This row has the “' + label + '” action. Use it to work with this record.'
        : 'Use the action buttons on a row to view or manage that record.',
      pagesTitle: 'See more records',
      pagesText: 'Use these controls to move through the list and see records on other pages.',
      dashboardTitle: 'Page overview',
      dashboardText: 'This area summarizes the information and tasks for this page.',
      dashboardStatsTitle: 'Dashboard totals',
      dashboardStatsText: 'These cards show current totals for users, partner companies, pending requirements, and reports waiting for review.',
      dashboardInsightsTitle: 'Recent activity and status',
      dashboardInsightsText: 'This section summarizes activity over the last seven days and shows the current OJT status breakdown for the active term.',
      dashboardOjtTitle: 'OJT progress this term',
      dashboardOjtText: 'See how many students are ongoing, completed, or not started in the active academic term.',
      dashboardActivityTitle: 'Recent admin activity',
      dashboardActivityText: 'Review the latest account and system changes here. Choose View All to open the full activity log.',
      formTitle: 'Complete this form',
      formText: 'Fill in the required details, then use the form button to save or send your changes.',
      setupStepsTitle: 'Follow the setup steps',
      setupStepsText: 'The progress line shows what is done and what you still need to complete.',
      setupFormTitle: 'Upload your required files',
      setupFormText: 'Choose the required files here. Check each one before submitting the set.',
      setupSubmitTitle: 'Send your files',
      setupSubmitText: 'When you have selected a file, use this button to send it for review.',
      announcementFeedTitle: 'Read announcements',
      announcementFeedText: 'Each post shows who shared it, who it is for, the message, and any attached files.',
      announcementSearchTitle: 'Find an announcement',
      announcementSearchText: 'Type a word or name to find matching announcements.',
      announcementFilterTitle: 'Filter announcements',
      announcementFilterText: 'Choose a category or audience, then apply the filters to narrow the list.',
      announcementAdminActionsText: 'Pin a post, edit it, or archive it. Open Archived Posts to restore an announcement later.',
      announcementPostTitle: 'Post an announcement',
      announcementPostText: 'Write a notice, choose who should see it, add a category or file, then publish.',
      announcementActionsTitle: 'Manage a post',
      announcementActionsText: 'Pin a post, edit it, or archive it. Open Archived Posts to restore it later.',
      announcementOwnerActionsText: 'Pin, edit, or archive your notice. Open Archived Posts to restore it later.',
      announcementArchiveTitle: 'Archived posts',
      announcementArchiveText: 'Open this list to find archived posts. Restore a post here to put it back in the active feed.',
      announcementActiveTitle: 'Active posts',
      announcementActiveText: 'Return to the active feed to view and manage published announcements.',
      announcementRestoreTitle: 'Restore a post',
      announcementRestoreText: 'Choose this button to return the post to the active announcements feed.',
      announcementOwnPostsTitle: 'Your notices',
      announcementOwnPostsText: 'Open this section to review and manage notices you posted.',
      termCreateTitle: 'Add a school term',
      termCreateText: 'Set the school year, semester, and optional dates. New terms start as drafts.',
      termCurrentTitle: 'Current school term',
      termCurrentText: 'OJTRACK uses this active term for current OJT records and new enrollment assignments.',
      termEditTitle: 'Edit term details',
      termEditText: 'Update the term dates. A term with enrollments keeps its school year and semester locked.',
      termActivateTitle: 'Switch the current term',
      termActivateText: 'Activating a term switches the current OJT views and closes the previous term. Past records stay with their original term.',
      finishText: 'You can open this guide again from the Guide button on any page.'
    }
  };

  const text = key => copy.en[key];
  const visible = element => {
    if (!element || !element.isConnected || element.closest('[hidden], [aria-hidden="true"]')) return false;
    const style = getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
  };
  const firstVisible = selector => [...document.querySelectorAll(selector)].find(visible) || null;
  const addStep = (target, title, description) => {
    if (visible(target) && !steps.some(step => step.target === target)) steps.push({target, title, description});
  };

  const studentContexts = '#uploadModal, #reportModal, #journalDetailsDialog, #journalWriteDialog, #studentEvaluationDialog, #newThreadModal, #monthlyCompilationModal, #profileModal, #passwordModal';
  function studentContext() {
    if (body.dataset.tourRole !== 'student') return null;
    const native = [...document.querySelectorAll('dialog[open]')].filter(visible);
    if (native.length) return native[native.length - 1];
    const contexts = [...document.querySelectorAll(studentContexts)].filter(element => visible(element) && (element.classList.contains('open') || element.id === 'monthlyCompilationModal'));
    return contexts[contexts.length - 1] || [...document.querySelectorAll('#journalEntryForm, #journalDetailContent')].find(visible) || null;
  }
  function addStudentSteps(context = null) {
    window.buildOJTrackStudentGuide?.({
      page: body.dataset.tourPage || '', context,
      add: (selector, title, description) => {
        const target = context ? (context.matches(selector) && visible(context) ? context : [...context.querySelectorAll(selector)].find(visible)) : firstVisible(selector);
        addStep(target, title, description);
      }
    });
    if (!steps.length && context) addStep(context.querySelector('h2, .modal-title') || context, 'About this window', 'Review the information shown here. Close this window to return to the page. This guide does not change or submit your records.');
  }
  function addStudentContextButtons() {
    if (body.dataset.tourRole !== 'student') return;
    document.querySelectorAll(studentContexts).forEach(context => {
      if (context.querySelector('[data-tour-context-open]')) return;
      const heading = context.querySelector('.modal-title, #journalWriteTitle, #journalDetailsTitle, #studentEvaluationDialogTitle, .section-title, .journal-dialog-header h2');
      if (!heading) return;
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'student-context-guide';
      button.dataset.tourOpen = ''; button.dataset.tourContextOpen = '';
      button.textContent = 'Guide'; button.setAttribute('aria-label', 'Open guide for this window');
      heading.appendChild(button);
    });
  }

  function buildSteps() {
    steps = [];
    const context = studentContext();
    if (context) { addStudentSteps(context); return steps; }
    const activeLink = firstVisible('.sidebar-nav .nav-link.active') || firstVisible('.sidebar-nav');
    const studentMenu = body.dataset.tourRole === 'student' && (!activeLink || activeLink.getBoundingClientRect().right <= 0) ? firstVisible('.topbar-menu-btn') : null;
    addStep(studentMenu || activeLink, text('navTitle'), studentMenu ? 'Use this menu button to open student navigation, then choose the page you need.' : text('navText'));

    const pageTitle = firstVisible('.page-heading .page-title, .onboarding-title, .page-title, h1');
    const titleValue = pageTitle?.textContent.trim() || 'this';
    const intro = text('pageText')(titleValue);
    addStep(pageTitle, text('pageTitle')(titleValue), intro);

    if (body.dataset.tourRole === 'student' && window.buildOJTrackStudentGuide) {
      addStudentSteps();
      return steps;
    }

    if (body.dataset.tourPage?.endsWith('/announcements')) {
      const isCoordinator = body.dataset.tourRole === 'coordinator';
      addStep(firstVisible('[data-tour-announcement-compose]'), text('announcementPostTitle'), isCoordinator
        ? 'Post a notice for students in your program. Add a category, message, optional expiry date, or attachment, then publish it.'
        : text('announcementPostText'));
      addStep(firstVisible('[data-tour-announcement-search]'), text('announcementSearchTitle'), text('announcementSearchText'));
      addStep(firstVisible('[data-tour-announcement-filter]'), text('announcementFilterTitle'), isCoordinator
        ? 'Filter coordinator posts by category or pinned status. Search by title or message using the search field.'
        : text('announcementFilterText'));
      addStep(firstVisible('[data-tour-announcement-own-posts]'), text('announcementOwnPostsTitle'), text('announcementOwnPostsText'));
      addStep(firstVisible('[data-tour-announcement-post]'), text('announcementFeedTitle'), text('announcementFeedText'));

      const restoreAction = firstVisible('[data-tour-announcement-restore]');
      const postActions = firstVisible('[data-tour-announcement-actions]');
      if (restoreAction) addStep(restoreAction, text('announcementRestoreTitle'), text('announcementRestoreText'));
      else if (postActions) {
        const role = body.dataset.tourRole;
        const ownerRole = ['company', 'coordinator'].includes(role);
        const actionsCopy = role === 'admin' ? 'announcementAdminActionsText' : (ownerRole ? 'announcementOwnerActionsText' : 'announcementActionsText');
        addStep(postActions, text('announcementActionsTitle'), role === 'coordinator'
          ? 'Pin, edit, or archive your notice here. Archived notices can be restored from Archived Posts.'
          : text(actionsCopy));
      }

      const archiveLink = firstVisible('[data-tour-announcement-archive]');
      const archivedView = new URLSearchParams(location.search).get('view') === 'archived';
      addStep(archiveLink, text(archivedView ? 'announcementActiveTitle' : 'announcementArchiveTitle'), text(archivedView ? 'announcementActiveText' : 'announcementArchiveText'));
      return steps;
    }

    if (body.dataset.tourPage?.endsWith('/academic-terms')) {
      addStep(firstVisible('[data-tour-term-create]'), text('termCreateTitle'), text('termCreateText'));
      addStep(firstVisible('[data-tour-term-current]'), text('termCurrentTitle'), text('termCurrentText'));
      addStep(firstVisible('[data-tour-term-edit]'), text('termEditTitle'), text('termEditText'));
      addStep(firstVisible('[data-tour-term-activate]'), text('termActivateTitle'), text('termActivateText'));
      return steps;
    }

    if (body.dataset.tourRole === 'company' && body.dataset.tourPage?.endsWith('/students')) {
      addStep(firstVisible('.company-trainee-table'), 'Review your trainees', 'See each assigned trainee’s program, OJT hours, placement dates, and current status in one table. Search by name or student ID, or filter by OJT status. Choose View details to open attendance and reports in a modal, or use Evaluate and Message for that trainee.');
      addStep(firstVisible('[data-tour-company-time]'), 'Record trainee attendance', 'Choose Time In / Time Out, select the trainees, then choose Record Now. The system records the first punch as Time In and the next punch as Time Out for each morning or afternoon session.');
    }

    if (body.dataset.tourRole === 'coordinator') {
      const page = body.dataset.tourPage || '';
      const addGuide = (selector, title, description) => addStep(firstVisible(selector), title, description);

      if (page.endsWith('/dashboard')) {
        addGuide('.dashboard-stats', 'Dashboard totals', 'These cards show your total students, active OJT students, students on hold or stopped, and pending requirements.');
        addGuide('.dashboard-insights', 'Attendance and student status', 'See attendance records from the last seven days and a breakdown of your students by OJT status.');
        addGuide('.coordinator-dashboard-requirements', 'Requirements to review', 'Open a requirement from this list to review the student’s submitted document.');
        addGuide('.coordinator-dashboard-hours', 'OJT hours by student', 'Check each student’s recorded OJT hours and progress toward the required total.');
      } else if (page.endsWith('/students')) {
        addGuide('.coordinator-student-filter-source, .table-tools-actions', 'Find your students', 'Search by student name or ID, then use the available filters to narrow the list.');
        addGuide('[data-student-details]', 'Open a student record', 'Choose a student row to see their placement, progress, attendance, and requirement details.');
      } else if (page.endsWith('/attendance')) {
        addGuide('.table-tools-actions', 'Find attendance records', 'Search or filter the student list. Choose a row to open that student’s daily time record.');
        addGuide('[data-attendance-student]', 'Review a student’s attendance', 'Open the student row to inspect their time-in, time-out, and recorded hours.');
      } else if (page.endsWith('/requirements')) {
        addGuide('.page-heading > div:last-child button:first-child', 'Add a requirement type', 'Create a requirement students can be asked to submit.');
        addGuide('.page-heading > div:last-child button:nth-child(2)', 'Send requirements to students', 'Choose one or more requirement types and students. Existing assignments are skipped.');
        addGuide('.tabs', 'Review by status', 'Switch between Pending Review, Approved, and Returned to see requirements in each state.');
        addGuide('#selectAllRequirements', 'Select requirements to review', 'Select rows on this page to reveal the available bulk review actions. The actions depend on the current status tab.');
        addGuide('[data-requirement-details]', 'Open a submitted requirement', 'Choose a row to inspect its file, student, status, and feedback.');
      } else if (page.endsWith('/monitoring')) {
        if (new URLSearchParams(location.search).has('student')) {
          addGuide('.journal-entry-filters', 'Find journal entries', 'Search entries or filter them by review status and week.');
          addGuide('.journal-card-select', 'Select pending journals', 'Select pending entries to show the bulk actions for approving or returning them.');
          addGuide('[data-journal-entry]', 'Review a journal entry', 'Open an entry to read the student’s activities, learnings, challenges, and attached proof.');
        } else {
          addGuide('.table-tools-actions', 'Find a student', 'Search or filter the student list, then open a student to review their daily journals.');
          addGuide('.coordinator-monitoring-student-row', 'Open student journals', 'Choose a student row to see their journal entries and review status.');
        }
      } else if (page.endsWith('/reports')) {
        addGuide('.report-toolbar-actions > .btn-primary', 'Create and assign a report', 'Set the report details, choose students, and optionally set a due date.');
        addGuide('.tabs', 'Choose a report list', 'Use these tabs to switch between reports for review, approved, returned, not submitted, and company evaluations.');
        addGuide('#reportSelectAll, .report-row-select', 'Review several reports', 'Select reports on this page to reveal the available bulk review actions. Some tabs do not allow bulk selection.');
        addGuide('[data-report-row]', 'Open report details', 'Choose a report row to inspect the submission and its review actions.');
      } else if (page.endsWith('/evaluation')) {
        if (new URLSearchParams(location.search).has('build')) {
          const stepper = firstVisible('[data-evaluation-stepper]');
          const activeStep = stepper?.querySelector('[aria-current="step"]')?.dataset.builderStep || '1';
          const activePanel = firstVisible('[data-builder-step-panel]:not([hidden])');
          addStep(stepper, 'Build an evaluation form', 'Follow the four steps: form details, questions, rating rules, then review and publish.');
          const stepCopy = {
            '1': ['Form details', 'Enter the form name, reviewer instructions, and scoring mode. Continue to Questions when these details are ready.'],
            '2': ['Questions and criteria', 'Organize criteria into sections, then continue to Rating rules when the form is ready to score.'],
            '3': ['Rating rules', 'Add score ranges and the rating or meaning for each one. Check that ranges do not overlap and cover the scores you need.'],
            '4': ['Review before publishing', 'Check the form summary and counts. Preview the form, save it as a draft, or publish it for company reviewers. Saving or publishing returns you to the forms list.']
          }[activeStep];
          if (activePanel && stepCopy) addStep(activePanel, stepCopy[0], stepCopy[1]);
        } else {
          addGuide('.evaluation-page-actions', 'Evaluation form actions', 'View submissions, assign an active form to companies, filter forms, or create a new form.');
          addGuide('.evaluation-status-filter', 'Filter forms by status', 'The list starts with Active forms. Choose Draft, Archived, or All forms to change what is shown.');
          addGuide('[data-evaluation-card-select]', 'Select active forms to archive', 'Select active forms to reveal the bulk archive action. To assign a form to a company, use Assign to company above.');
          addGuide('.evaluation-form-card', 'Open form details', 'Choose a form card to preview its sections, criteria, scoring, and rating rules. Use the three-dot menu for editing, duplicating, or archiving.');
        }
      } else if (page.endsWith('/messages')) {
        addGuide('.page-heading > .btn-primary', 'Start a conversation', 'Create a conversation with selected students or company contacts. You can choose more than one participant.');
        addGuide('.chat-inbox-tabs', 'Switch conversation folders', 'Inbox contains active conversations. Archived contains archived chats, which you can restore.');
        addGuide('.chat-main', 'Read and reply', 'Open a conversation from the left list, write a reply below, or use Archive to move it out of the inbox.');
      }
      return steps;
    }

    const createAction = firstVisible('.page-heading-actions .btn-primary, .page-heading-actions .btn, .table-tool-new');
    if (createAction && !createAction.hasAttribute('data-tour-open')) {
      const label = createAction.textContent.trim() || createAction.getAttribute('aria-label') || 'Add';
      const createsRecord = /add|new|create|register|post|compose/i.test(label);
      addStep(createAction, text('addTitle'), text(createsRecord ? 'createText' : 'addText')(label));
    }

    const toolbar = firstVisible('.table-tools-actions');
    if (toolbar) {
      const features = [];
      if (toolbar.querySelector('.table-quick-search')) features.push('search');
      if (toolbar.querySelector('[data-table-filter-toggle]')) features.push('filters');
      if (toolbar.querySelector('.table-export-menu')) features.push('export');
      if (toolbar.querySelector('.table-column-picker')) features.push('column controls');
      if (toolbar.children.length && features.length) {
        const joined = features.length === 1 ? features[0] : features.slice(0, -1).join(', ') + ' and ' + features[features.length - 1];
        addStep(toolbar, text('toolsTitle'), text('toolsText')(joined));
      }
    }

    if (body.dataset.tourRole === 'admin') {
      const bulkGuides = [
        {
          table: '[data-user-multi-select]',
          checkbox: '[data-user-select-all]',
          detail: 'In User Accounts, you can activate, deactivate, or archive the selected accounts. Your own admin account is protected.'
        },
        {
          table: '[data-archived-user-multi-select]',
          checkbox: '[data-archived-user-select-all]',
          detail: 'Here, you can restore the selected archived accounts.'
        },
        {
          table: '[data-program-multi-select]',
          checkbox: '[data-program-select-all]',
          detail: 'In Programs, you can activate or deactivate the selected programs.'
        }
      ];
      const bulkGuide = bulkGuides.find(item => firstVisible(item.table));
      if (bulkGuide) {
        const target = firstVisible(bulkGuide.checkbox) || firstVisible(bulkGuide.table)?.querySelector('thead th');
        addStep(target, text('bulkTitle'), text('bulkText')(bulkGuide.detail));
      }
    }

    const firstRow = firstVisible('.table-wrap tbody tr:not([hidden]):not(.table-page-hidden)');
    const rowAction = firstRow?.querySelector('.table-action-icon, .table-actions button, .table-actions a, [data-row-link]');
    if (rowAction) {
      const label = rowAction.getAttribute('title') || rowAction.getAttribute('aria-label') || rowAction.textContent.trim();
      let description = text('rowText')(label);
      if (label && /edit|update/i.test(label)) description = 'Choose “' + label + '” to update this record.';
      else if (label && /archive/i.test(label)) description = 'Choose “' + label + '” to move this record to Archived Accounts. You can restore it from there.';
      else if (label && /delete|remove/i.test(label)) description = 'Choose “' + label + '” to remove this record.';
      else if (label && /restore/i.test(label)) description = 'Choose “' + label + '” to restore this record.';
      else if (label && /view|open|review|details/i.test(label)) description = 'Choose “' + label + '” to open and review this record.';
      addStep(rowAction, text('rowsTitle'), description);
    }

    const pager = firstVisible('.table-pagination:not([hidden]), .evaluation-list-pagination');
    addStep(pager, text('pagesTitle'), text('pagesText'));

    const pageForm = [...document.querySelectorAll('.page-body form:not([data-live-filter])')]
      .find(form => visible(form) && !form.closest('.table-wrap, .table-filter-drawer, .modal-overlay'));
    if (pageForm) {
      const submit = pageForm.querySelector('button[type="submit"], input[type="submit"]');
      addStep(submit && visible(submit) ? submit : pageForm, text('formTitle'), text('formText'));
    }

    if (body.dataset.tourPage === 'student/onboarding') {
      addStep(firstVisible('.stepper'), text('setupStepsTitle'), text('setupStepsText'));
      addStep(firstVisible('#onboarding-documents-form'), text('setupFormTitle'), text('setupFormText'));
      addStep(firstVisible('#onboarding-submit'), text('setupSubmitTitle'), text('setupSubmitText'));
    } else if (body.dataset.tourPage?.endsWith('/dashboard')) {
      if (body.dataset.tourRole === 'admin') {
        addStep(firstVisible('.dashboard-stats'), text('dashboardStatsTitle'), text('dashboardStatsText'));
        addStep(firstVisible('.dashboard-insights'), text('dashboardInsightsTitle'), text('dashboardInsightsText'));
        addStep(firstVisible('[data-tour-admin-ojt-status]'), text('dashboardOjtTitle'), text('dashboardOjtText'));
        addStep(firstVisible('[data-tour-admin-activity]'), text('dashboardActivityTitle'), text('dashboardActivityText'));
      } else {
        addStep(firstVisible('.dashboard-grid, .dashboard-grid-2, .stat-grid'), text('dashboardTitle'), text('dashboardText'));
      }
    }
    return steps;
  }

  function createUi() {
    shade = document.createElement('div');
    shade.className = 'onboarding-tour-shade';
    shade.setAttribute('aria-hidden', 'true');
    ['top', 'right', 'bottom', 'left'].forEach(side => {
      const panel = document.createElement('div');
      panel.className = 'onboarding-tour-shade-' + side;
      shade.appendChild(panel);
    });

    card = document.createElement('section');
    card.className = 'onboarding-tour-card';
    card.tabIndex = -1;
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.setAttribute('aria-labelledby', 'onboardingTourTitle');
    card.innerHTML = '<header class="onboarding-tour-header"><div><span class="onboarding-tour-eyebrow"></span><h2 id="onboardingTourTitle"></h2></div><button type="button" class="onboarding-tour-close" aria-label="Close guide">×</button></header><div class="onboarding-tour-content"><h3 data-tour-step-title></h3><p data-tour-step-description aria-live="polite"></p></div><footer class="onboarding-tour-footer"><div class="onboarding-tour-progress" role="progressbar" aria-label="Guide progress" aria-valuemin="1" aria-valuemax="1" aria-valuenow="1"><span></span></div><div class="onboarding-tour-meta"><label><input type="checkbox" data-tour-dont-show><span data-tour-dont-label></span></label><span data-tour-step-count></span></div><div class="onboarding-tour-actions"><button type="button" class="onboarding-tour-back" data-tour-back></button><button type="button" class="onboarding-tour-skip" data-tour-skip></button><button type="button" class="onboarding-tour-next" data-tour-next></button></div></footer>';
    if (body.dataset.tourRole === 'student' && typeof card.showPopover === 'function') {
      card.setAttribute('popover', 'manual'); shade.setAttribute('popover', 'manual');
    }
    document.body.append(shade, card);
    card.querySelector('[data-tour-back]').addEventListener('click', () => showStep(currentStep - 1));
    card.querySelector('[data-tour-next]').addEventListener('click', () => currentStep + 1 < steps.length ? showStep(currentStep + 1) : finishTour());
    card.querySelector('[data-tour-skip]').addEventListener('click', closeTour);
    card.querySelector('.onboarding-tour-close').addEventListener('click', closeTour);
    card.querySelector('[data-tour-dont-show]').addEventListener('change', event => {
      dontShowAgain = event.target.checked;
      if (dontShowAgain) safeSet('dont-show', '1');
      else safeRemove('dont-show');
    });
  }

  function renderText() {
    if (!card) return;
    card.querySelector('.onboarding-tour-eyebrow').textContent = text('eyebrow');
    card.querySelector('#onboardingTourTitle').textContent = text('title');
    card.querySelector('[data-tour-back]').textContent = text('back');
    card.querySelector('[data-tour-skip]').textContent = text('skip');
    card.querySelector('[data-tour-dont-label]').textContent = text('dont');
    const step = steps[currentStep];
    if (!step) return;
    card.querySelector('[data-tour-step-title]').textContent = step.title;
    card.querySelector('[data-tour-step-description]').textContent = step.description;
    card.querySelector('[data-tour-step-count]').textContent = text('step')(currentStep + 1, steps.length);
    const progress = card.querySelector('.onboarding-tour-progress');
    progress.setAttribute('aria-valuemax', String(steps.length));
    progress.setAttribute('aria-valuenow', String(currentStep + 1));
    progress.querySelector('span').style.width = ((currentStep + 1) / steps.length * 100) + '%';
    card.querySelector('[data-tour-next]').textContent = currentStep === steps.length - 1 ? text('finish') : text('next');
    card.querySelector('[data-tour-back]').disabled = currentStep === 0;
    card.querySelector('[data-tour-dont-show]').checked = dontShowAgain;
  }

  function positionSpotlight(target) {
    const rect = target.getBoundingClientRect();
    const panels = Object.fromEntries([...shade.children].map(panel => [panel.className.split('-').pop(), panel]));
    Object.assign(panels.top.style, {left: '0', top: '0', width: '100vw', height: Math.max(0, rect.top) + 'px'});
    Object.assign(panels.bottom.style, {left: '0', top: rect.bottom + 'px', width: '100vw', height: Math.max(0, innerHeight - rect.bottom) + 'px'});
    Object.assign(panels.left.style, {left: '0', top: rect.top + 'px', width: Math.max(0, rect.left) + 'px', height: Math.max(0, rect.height) + 'px'});
    Object.assign(panels.right.style, {left: rect.right + 'px', top: rect.top + 'px', width: Math.max(0, innerWidth - rect.right) + 'px', height: Math.max(0, rect.height) + 'px'});

    const cardRect = card.getBoundingClientRect();
    const left = Math.max(12, Math.min(innerWidth - cardRect.width - 12, rect.left + rect.width / 2 - cardRect.width / 2));
    const below = rect.bottom + 14;
    const top = below + cardRect.height <= innerHeight - 12 ? below : Math.max(12, rect.top - cardRect.height - 14);
    card.style.left = left + 'px';
    card.style.top = top + 'px';
  }

  function showStep(index) {
    if (!steps.length) { closeTour(); return; }
    currentStep = Math.max(0, Math.min(index, steps.length - 1));
    if (!visible(steps[currentStep].target)) { closeTour(); return; }
    if (highlighted) highlighted.classList.remove('onboarding-tour-highlight');
    highlighted = steps[currentStep].target;
    highlighted.scrollIntoView({behavior: 'instant', block: 'center', inline: 'nearest'});
    highlighted.classList.add('onboarding-tour-highlight');
    renderText();
    const target = highlighted;
    requestAnimationFrame(() => {
      if (highlighted === target && visible(target) && card && !card.hidden) positionSpotlight(target);
    });
  }

  function openTour() {
    if (card && !card.hidden) closeTour();
    buildSteps();
    if (!steps.length) return false;
    previousFocus = document.activeElement;
    if (!card) createUi();
    if (body.dataset.tourRole === 'student') {
      const context = studentContext();
      const host = context?.tagName === 'DIALOG' ? context : document.body;
      host.append(shade, card);
    }
    card.hidden = false;
    shade.hidden = false;
    if (card.hasAttribute('popover')) { shade.showPopover(); card.showPopover(); }
    renderText();
    showStep(0);
    card.focus({preventScroll: true});
    return true;
  }

  function closeTour() {
    if (card?.hasAttribute('popover') && card.matches(':popover-open')) card.hidePopover();
    if (shade?.hasAttribute('popover') && shade.matches(':popover-open')) shade.hidePopover();
    if (card) card.hidden = true;
    if (shade) shade.hidden = true;
    if (highlighted) highlighted.classList.remove('onboarding-tour-highlight');
    highlighted = null;
    if (visible(previousFocus)) previousFocus.focus({preventScroll: true});
  }

  function finishTour() {
    closeTour();
  }

  document.addEventListener('click', event => {
    if (!event.target.closest('[data-tour-open]')) return;
    event.preventDefault();
    openTour();
  });
  document.addEventListener('keydown', event => {
    if (!card || card.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); closeTour(); return; }
    if (event.key === 'Tab') {
      const controls = [...card.querySelectorAll('button:not([disabled]), input')].filter(visible);
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && (document.activeElement === first || !card.contains(document.activeElement))) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && (document.activeElement === last || !card.contains(document.activeElement))) { event.preventDefault(); first?.focus(); }
      event.stopImmediatePropagation();
    }
    if (event.key === 'ArrowRight' && currentStep < steps.length - 1) { event.preventDefault(); event.stopImmediatePropagation(); showStep(currentStep + 1); }
    if (event.key === 'ArrowLeft' && currentStep > 0) { event.preventDefault(); event.stopImmediatePropagation(); showStep(currentStep - 1); }
  }, true);
  window.addEventListener('resize', () => { if (highlighted && card && !card.hidden) positionSpotlight(highlighted); });
  window.addEventListener('scroll', () => { if (highlighted && card && !card.hidden) positionSpotlight(highlighted); }, true);

  document.addEventListener('DOMContentLoaded', () => {
    addStudentContextButtons();
    if (body.dataset.tourRole === 'student') {
      let scheduled = false;
      new MutationObserver(() => {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
          scheduled = false;
          addStudentContextButtons();
          if (highlighted && !visible(highlighted)) closeTour();
        });
      }).observe(body, {childList:true, subtree:true, attributes:true, attributeFilter:['open','hidden','class']});
      document.addEventListener('close', event => { if (event.target.tagName === 'DIALOG' && card && event.target.contains(card)) closeTour(); }, true);
    }
    if (safeGet('dont-show') === '1') return;
    window.setTimeout(() => {
      if (safeGet('dont-show') === '1') return;
      if (card && !card.hidden) return;
      if (document.querySelector('.modal-overlay.open, dialog[open], .monthly-filter-panel:not([hidden])')) return;
      openTour();
    }, 900);
  });
})();
