// Synthetic guide checks. Requires Playwright; never signs in or submits application forms.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const sandbox = { window: {} };
vm.runInNewContext(fs.readFileSync(path.join(root, 'assets/js/student-guide.js'), 'utf8'), sandbox);
let checks = 0;
for (const page of ['onboarding','dashboard','requirements','attendance','journal','reports','evaluation','messages','announcements','certificate']) {
  const steps = [];
  sandbox.window.buildOJTrackStudentGuide({page: 'student/'+page, context: null, add: (...args) => steps.push(args)});
  assert.ok(steps.length >= 2, page+' has dedicated guidance');
  if (page === 'announcements') assert.ok(!steps.some(step => step[2].includes('choose a category or audience')));
  checks++;
}
for (const id of ['uploadModal','reportModal','journalWriteDialog','journalDetailsDialog','journalEntryForm','journalDetailContent','studentEvaluationDialog','newThreadModal','monthlyCompilationModal','profileModal','passwordModal']) {
  const steps = [];
  sandbox.window.buildOJTrackStudentGuide({page:'student/test',context:{id},add:(...args)=>steps.push(args)});
  assert.ok(steps.length >= 2, id+' has contextual guidance'); checks++;
}
(async () => {
  const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
  const browser = await chromium.launch({headless:true,...(fs.existsSync(edge) ? {executablePath:edge} : {})});
  try {
    const page = await browser.newPage({viewport:{width:1200,height:850}});
    const errors=[]; page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => localStorage.setItem('ojtrack-tour:synthetic:dont-show','1'));
    await page.route('http://guide.test/**', route => {
      const url = new URL(route.request().url());
      if (url.pathname.startsWith('/assets/')) return route.fulfill({path:path.join(root,url.pathname)});
      const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/onboarding-tour.css"></head>
      <body data-tour-user-id="synthetic" data-tour-role="student" data-tour-page="student/evaluation"><main style="padding:32px"><button id="guide" data-tour-open>Guide</button><div class="page-heading"><h1 class="page-title">OJT Evaluations</h1></div>
      <table class="student-evaluation-table"><tr><td>Assigned evaluation</td></tr></table><button data-view-evaluation id="result">View results</button><button class="student-evaluation-awaiting">Awaiting evaluation</button><details class="table-export-menu"><summary>Export</summary></details>
      <dialog id="studentEvaluationDialog" aria-labelledby="studentEvaluationDialogTitle" style="width:min(800px,calc(100% - 24px));max-height:85vh;padding:20px"><h2 id="studentEvaluationDialogTitle">Review Evaluation</h2><div class="evaluation-review-record">Synthetic evaluation</div><div class="evaluation-review-sections"><p>Question scores</p></div><div class="evaluation-review-overall">Overall score</div><a id="studentEvaluationPdf" href="#">Download PDF</a><button type="button" id="closeResult">Close result</button></dialog></main>
      <script>document.getElementById('result').onclick=()=>document.getElementById('studentEvaluationDialog').showModal();document.getElementById('closeResult').onclick=()=>document.getElementById('studentEvaluationDialog').close();</script>
      <script src="/assets/js/main.js"></script><script src="/assets/js/student-guide.js"></script><script src="/assets/js/onboarding-tour.js"></script></body></html>`;
      return route.fulfill({contentType:'text/html; charset=utf-8',body:html});
    });
    await page.goto('http://guide.test/student/evaluation');
    await page.locator('#guide').click();
    const descriptions=[];
    for (let index=0;index<10;index++) {
      descriptions.push(await page.locator('[data-tour-step-description]').innerText());
      if (await page.locator('[data-tour-next]').innerText() === 'Finish') break;
      await page.locator('[data-tour-next]').click();
    }
    assert.ok(descriptions.some(value=>value.includes('Evaluations (PDF)'))); checks++;
    assert.ok(descriptions.some(value=>value.includes('Pending evaluations have no results'))); checks++;
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#guide').evaluate(el=>document.activeElement===el),true); checks++;
    await page.locator('#result').click();
    await page.locator('#studentEvaluationDialog [data-tour-context-open]').click();
    assert.equal(await page.locator('.onboarding-tour-card').evaluate(el=>el.matches(':popover-open') && el.closest('dialog')?.open),true); checks++;
    assert.equal(await page.locator('[data-tour-step-title]').innerText(),'Check the evaluation record'); checks++;
    if (process.argv[2]) { await page.locator('.onboarding-tour-card').evaluate(el=>Promise.all(el.getAnimations().map(animation=>animation.finished))); await page.screenshot({path:path.join(process.argv[2],'student-guide-desktop.png')}); }
    await page.locator('[data-tour-next]').last().focus(); await page.keyboard.press('Tab');
    assert.equal(await page.locator('.onboarding-tour-close').evaluate(el=>el===document.activeElement),true); checks++;
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#studentEvaluationDialog').evaluate(el=>el.open),true); checks++;
    assert.equal(await page.locator('#studentEvaluationDialog [data-tour-context-open]').evaluate(el=>el===document.activeElement),true); checks++;
    await page.setViewportSize({width:390,height:844});
    await page.locator('#studentEvaluationDialog [data-tour-context-open]').click();
    const bounds=await page.locator('.onboarding-tour-card').boundingBox();
    assert.ok(bounds.x>=0 && bounds.y>=0 && bounds.x+bounds.width<=390 && bounds.y+bounds.height<=844); checks++;
    if (process.argv[2]) { await page.locator('.onboarding-tour-card').evaluate(el=>Promise.all(el.getAnimations().map(animation=>animation.finished))); await page.screenshot({path:path.join(process.argv[2],'student-guide-mobile.png')}); }
    // Removing the highlighted record must dismiss, not leave a stale spotlight.
    await page.locator('.evaluation-review-record').evaluate(el=>el.remove());
    await page.waitForFunction(()=>document.querySelector('.onboarding-tour-card').hidden); checks++;
    assert.deepEqual(errors,[]); checks++;
    console.log(`${checks} student guide checks passed; no application submissions.`);
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
