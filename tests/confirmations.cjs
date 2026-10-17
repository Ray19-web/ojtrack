// Browser checks on synthetic forms only. Requires Playwright and Chromium/Edge.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
(async () => {
  const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
  const browser = await chromium.launch({headless: true, ...(fs.existsSync(edge) ? {executablePath: edge} : {})});
  let checks = 0;
  const check = (actual, expected) => { assert.deepEqual(actual, expected); checks++; };
  try {
    const page = await browser.newPage();
    await page.setContent(`<form id="mutation" method="post">
      <input name="action" value="approve" type="hidden">
      <input name="csrf_token" value="synthetic-token" type="hidden">
      <input name="name" required value="Synthetic student">
      <input name="document" type="file">
      <button id="submit" name="decision" value="yes">Approve</button>
    </form>
    <button id="external" type="submit" form="mutation" name="decision" value="external">Issue Official Certificate</button>
    <form id="search"><input name="q"><button>Search</button></form>
    <form id="logout" method="post" data-confirm-skip><button>Sign Out</button></form>
    <div class="modal-overlay open" id="editor"><input id="editorInput" value="Retained draft"></div>`);
    await page.addStyleTag({content: fs.readFileSync(path.join(root,'assets/css/confirmations.css'),'utf8')});
    await page.addScriptTag({content: fs.readFileSync(path.join(root,'assets/js/confirmations.js'),'utf8')});
    await page.addScriptTag({content: fs.readFileSync(path.join(root,'assets/js/main.js'),'utf8')});
    await page.evaluate(() => {
      window.saved = []; window.searches = 0; window.logouts = 0;
      document.getElementById('mutation').addEventListener('submit', e => {
        e.preventDefault();
        const data = new FormData(e.target,e.submitter);
        window.saved.push({name:data.get('name'), token:data.get('csrf_token'), decision:data.get('decision'), file:data.get('document').name});
      });
      document.getElementById('search').addEventListener('submit', e => {e.preventDefault();window.searches++;});
      document.getElementById('logout').addEventListener('submit', e => {e.preventDefault();window.logouts++;});
    });
    await page.locator('input[type=file]').setInputFiles({name:'synthetic.pdf',mimeType:'application/pdf',buffer:Buffer.from('synthetic')});
    await page.click('#submit');
    check(await page.locator('#actionConfirmation').isVisible(),true);
    check(await page.locator('#actionConfirmationTitle').textContent(),'Approve this submission?');
    check(await page.evaluate(() => saved.length),0);
    check(await page.evaluate(() => document.activeElement.className),'action-confirm-cancel');
    check(await page.locator('#editor').evaluate(el=>el.inert),true);
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.className),'action-confirm-accept');
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.className),'action-confirm-cancel');
    await page.keyboard.press('Escape');
    check(await page.locator('#actionConfirmation').isVisible(),false);
    check(await page.locator('#editor').evaluate(el=>el.inert),false);
    check(await page.locator('#editor').evaluate(el=>el.classList.contains('open')),true);
    check(await page.evaluate(() => document.activeElement.id),'submit');
    check(await page.evaluate(() => saved.length),0);
    check(await page.locator('#editorInput').inputValue(),'Retained draft');
    await page.click('#submit');
    await page.click('.action-confirm-cancel');
    check(await page.evaluate(() => saved.length),0);
    await page.click('#submit');
    await page.click('.action-confirm-accept');
    check(await page.evaluate(() => saved),[{name:'Synthetic student',token:'synthetic-token',decision:'yes',file:'synthetic.pdf'}]);
    await page.fill('input[name=name]','');
    await page.click('#submit');
    check(await page.locator('#actionConfirmation').isVisible(),false);
    check(await page.evaluate(() => saved.length),1);
    await page.fill('input[name=name]','Revalidated');
    await page.click('#external');
    await page.evaluate(() => { document.querySelector('input[name=name]').value=''; });
    await page.click('.action-confirm-accept');
    check(await page.evaluate(() => saved.length),1);
    await page.fill('input[name=name]','External');
    await page.evaluate(() => {
      document.querySelector('input[name=action]').value='issue_certificate';
      document.getElementById('mutation').dataset.confirmMessage='Permanent certificate snapshot.';
    });
    await page.click('#external');
    check(await page.locator('#actionConfirmationTitle').textContent(),'Issue official certificate?');
    check(await page.locator('#actionConfirmationMessage').textContent(),'Permanent certificate snapshot.');
    await page.click('.action-confirm-accept');
    check(await page.evaluate(() => saved[1].decision),'external');
    await page.locator('#search button').click();
    check(await page.evaluate(() => searches),1);
    check(await page.locator('#actionConfirmation').isVisible(),false);
    await page.locator('#logout button').click();
    check(await page.evaluate(() => logouts),1);
    check(await page.locator('#actionConfirmation').isVisible(),false);
    await page.evaluate(() => {
      const form = document.createElement('form');
      form.id='journal'; form.method='post';
      form.innerHTML='<input type="hidden" name="action" value="review_journal"><button name="status" value="approved">Approve</button><button name="status" value="rejected">Return</button>';
      form.addEventListener('submit', e=>{e.preventDefault();window.journalDecision=e.submitter.value;});
      document.body.append(form);
    });
    await page.locator('#journal button[value=approved]').click();
    check(await page.locator('#actionConfirmationTitle').textContent(),'Approve this submission?');
    await page.click('.action-confirm-accept');
    check(await page.evaluate(()=>journalDecision),'approved');
    await page.locator('#journal button[value=rejected]').click();
    check(await page.locator('#actionConfirmationTitle').textContent(),'Return this submission?');
    check(await page.locator('.action-confirm-accept').evaluate(el=>el.classList.contains('is-danger')),true);
    await page.click('.action-confirm-cancel');
    await page.evaluate(() => {
      document.querySelector('input[name=action]').value='archive_form';
      delete document.getElementById('mutation').dataset.confirmMessage;
      document.getElementById('submit').textContent='Restore';
    });
    await page.click('#submit');
    check(await page.locator('#actionConfirmationTitle').textContent(),'Restore this record?');
    check(await page.locator('.action-confirm-accept').textContent(),'Restore');
    await page.click('.action-confirm-cancel');
    await page.evaluate(() => {
      window.first = confirmOJTrack({title:'<img src=x onerror=alert(1)>',message:'<script>unsafe</script>'});
    });
    check(await page.locator('#actionConfirmation img, #actionConfirmation script').count(),0);
    await page.evaluate(async () => { window.second = await confirmOJTrack({title:'Duplicate'}); });
    check(await page.evaluate(() => second),false);
    await page.mouse.click(2,2);
    check(await page.evaluate(() => first),false);
    for (const width of [320,390,768,1440]) {
      await page.setViewportSize({width,height:740});
      await page.evaluate(() => {window.mobile = confirmOJTrack({message:'A long requirement name '.repeat(35)});});
      check(await page.locator('.action-confirm-dialog').evaluate(el => {
        const box=el.getBoundingClientRect();return box.left>=0 && box.right<=innerWidth && box.top>=0 && box.bottom<=innerHeight;
      }),true);
      await page.click('.action-confirm-cancel');
    }
    if (process.env.OJTRACK_CONFIRMATION_SCREENSHOT) {
      await page.setViewportSize({width:390,height:844});
      await page.evaluate(()=>{window.preview=confirmOJTrack({title:'Archive this student?',message:'The student will lose login access. Their academic and OJT records will remain preserved.',confirmLabel:'Archive',danger:true});});
      await page.screenshot({path:process.env.OJTRACK_CONFIRMATION_SCREENSHOT});
      await page.click('.action-confirm-cancel');
    }
    console.log(`${checks} confirmation browser checks passed.`);
  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
