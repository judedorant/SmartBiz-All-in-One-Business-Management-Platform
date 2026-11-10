import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir, readdir, stat } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = process.cwd();
const artifacts = resolve(root, '.runtime/qa2026-browser');
const profile = resolve(root, '.runtime/qa2026-edge');
await mkdir(artifacts, { recursive: true });
const manifest = JSON.parse(await readFile(resolve(root, 'backend/storage/app/private/demo-accounts-smartbiz_demo.json'), 'utf8'));
assert.equal(manifest.accounts.length, 14);
const edge = spawn(process.env.SMARTBIZ_QA_BROWSER ?? 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=19335', '--user-data-dir=' + profile, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
const pause = (ms) => new Promise(resolve => setTimeout(resolve, ms));
let socket;
let serial = 0;
const pending = new Map();
const errors = [];
const results = [];
try {
  let target;
  for (let index = 0; index < 100; index++) {
    try {
      const targets = await (await fetch('http://127.0.0.1:19335/json')).json();
      target = targets.find(item => item.type === 'page');
      if (target) break;
    } catch {}
    await pause(200);
  }
  assert.ok(target, 'Headless Edge debugger is available');
  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id) {
      const handler = pending.get(message.id);
      if (handler) { pending.delete(message.id); message.error ? handler.reject(new Error(message.error.message)) : handler.resolve(message.result); }
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text + ': ' + (message.params.exceptionDetails.exception?.description ?? ''));
  });
  const call = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++serial;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
  const evaluate = async expression => {
    const result = await call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text + ': ' + (result.exceptionDetails.exception?.description ?? ''));
    return result.result.value;
  };
  const wait = async (expression, name, timeout = 20000) => {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
      if (await evaluate(expression)) return;
      await pause(150);
    }
    const diagnostic = await evaluate('({path:location.pathname,text:document.body.innerText})');
    await writeFile(resolve(artifacts, 'timeout.json'), JSON.stringify({name,diagnostic,errors}, null, 2));
    const image = await call('Page.captureScreenshot', {format:'png'});
    await writeFile(resolve(artifacts, 'timeout.png'), Buffer.from(image.data, 'base64'));
    throw new Error('Timed out: ' + name + ' at ' + diagnostic.path + '; see timeout.json');
  };
  await call('Page.enable');
  await call('Runtime.enable');
  await call('Network.enable');
  await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
  await call('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: artifacts });
  const navigate = async path => {
    await call('Page.navigate', { url: 'http://localhost:3001' + path });
    await wait('document.readyState === "complete" && !!document.querySelector("main, form")', 'page ' + path);
  };
  const fill = async (selector, text) => {
    await evaluate('document.querySelector(' + JSON.stringify(selector) + ').focus()');
    await call('Input.insertText', { text });
  };
  const select = async (label, value) => {
    await wait('(() => { const field = [...document.querySelectorAll("label")].find(label => label.firstElementChild?.textContent.trim() === ' + JSON.stringify(label) + '); return !!field?.querySelector("select option[value=\\"' + value + '\\"]") || [...(field?.querySelector("select")?.options ?? [])].some(option => option.value === ' + JSON.stringify(value) + '); })()', 'option ' + label + ' ' + value);
    await evaluate('(() => { const field = [...document.querySelectorAll("label")].find(label => label.firstElementChild?.textContent.trim() === ' + JSON.stringify(label) + '); const select = field?.querySelector("select"); if (!select) throw new Error("Select missing"); select.value = ' + JSON.stringify(value) + '; select.dispatchEvent(new Event("change", {bubbles: true})); })()');
  };
  const screenshot = async name => {
    const result = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    await writeFile(resolve(artifacts, name + '.png'), Buffer.from(result.data, 'base64'));
  };
  const api = async path => evaluate('fetch(' + JSON.stringify('http://localhost:8001/api/v1' + path) + ', {credentials:"include",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"}}).then(async response => ({status:response.status,body:await response.json()}))');
  const qa = JSON.parse(await readFile(resolve(root,'backend/storage/app/private/qa-2026-manifest.json'),'utf8'));
  assert.equal(qa.database,'smartbiz_demo','Browser mutations require the dedicated demo scenario');
  const evidence = JSON.parse(await readFile(resolve(root,'backend/storage/app/private/qa-2026-validation.json'),'utf8'));
  await call('Emulation.setTimezoneOverride',{timezoneId:'Asia/Manila'});
  const button = async text => evaluate('(() => { const button=[...document.querySelectorAll("#main button,form button")].find(button=>button.textContent.trim()==='+JSON.stringify(text)+'); if(!button)throw new Error("Button missing: '+text+'"); button.click(); })()');
  const replace = async (selector,text) => {
    await evaluate('document.querySelector('+JSON.stringify(selector)+').focus()');
    await call('Input.dispatchKeyEvent',{type:'keyDown',key:'a',code:'KeyA',windowsVirtualKeyCode:65,modifiers:2});
    await call('Input.dispatchKeyEvent',{type:'keyUp',key:'a',code:'KeyA',windowsVirtualKeyCode:65,modifiers:2});
    await call('Input.insertText',{text});
  };
  const field = async (name,text) => {
    await evaluate('(() => { document.querySelector("[data-qa-field]")?.removeAttribute("data-qa-field"); const label=[...document.querySelectorAll("label")].find(label=>label.firstElementChild?.textContent.trim()==='+JSON.stringify(name)+'); const input=label?.querySelector("input,textarea"); if(!input)throw new Error("Field missing"); input.setAttribute("data-qa-field","current"); })()');
    await replace('[data-qa-field="current"]',text);
  };
  const dates = async () => {
    await select('Date range','custom');
    for(const [name,value] of [['Start date','2026-01-01'],['End date','2026-12-31']]) {
      await evaluate('(() => { const label=[...document.querySelectorAll("label")].find(label=>label.firstElementChild?.textContent.trim()==='+JSON.stringify(name)+');const input=label.querySelector("input");Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,"value").set.call(input,'+JSON.stringify(value)+');input.dispatchEvent(new Event("input",{bubbles:true}));input.dispatchEvent(new Event("change",{bubbles:true}));})()');
    }
    await button('Apply dates');
  };
  let acceptDialog=false;
  let dialogs=0;
  socket.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(message.method==='Page.javascriptDialogOpening'){dialogs++;call('Page.handleJavaScriptDialog',{accept:acceptDialog}).catch(()=>{});}
  });
  await navigate('/login');
  await wait('!!document.querySelector("input[type=password]")','login form');
  await fill('input[type=email]',qa.account.email);
  await fill('input[type=password]',qa.account.password);
  await evaluate('document.querySelector("form button").click()');
  await wait('location.pathname==="/dashboard" && document.body.innerText.includes("Welcome,")','QA admin dashboard');
  await wait('document.body.innerText.includes("SmartBiz · Phase 7")','current phase label');
  results.push('Sidebar identifies the implemented Phase 7 correctly');
  await select('Revenue currency','PHP');
  await dates();
  await wait('document.querySelector("#main").innerText.includes("2026-01-01") && !!document.querySelector("[aria-label=\\"Monthly sales performance chart\\"] svg")','annual dashboard');
  const expectedRevenue=Number(evidence.summary.metrics.total_recorded_sales).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  await wait('document.querySelector("[aria-label=\\"Total recorded sales\\"]").innerText.includes('+JSON.stringify(expectedRevenue)+')','annual revenue matches independent SQL');
  results.push('Desktop annual dashboard: real charts and revenue match independent SQL');
  await screenshot('desktop-dashboard-2026');
  await select('Date range','last_7_days');
  await wait('!document.querySelector("#main").innerText.includes("2026-01-01")','date preset changed');
  results.push('Dashboard date preset updates results');
  await navigate('/reports');
  await wait('!!document.querySelector("#main table")','reports table');
  await select('Revenue currency','PHP');
  await dates();
  for(const report of ['sales','customers','tasks','pipeline']){
    await select('Report',report);
    const expected=evidence.reports[report].rows.map(row=>row.map(String));
    await wait('JSON.stringify([...document.querySelectorAll("#main tbody tr")].map(row=>[...row.querySelectorAll("td")].map(cell=>cell.textContent.trim())))==='+JSON.stringify(JSON.stringify(expected)),report+' table matches SQL');
    const before=new Map(await Promise.all((await readdir(artifacts)).map(async file=>[file,(await stat(resolve(artifacts,file))).mtimeMs])));
    await button('Download CSV');
    let downloaded;
    for(let index=0;index<100;index++){for(const file of await readdir(artifacts)){if(file.endsWith('.csv')&&(!before.has(file)||(await stat(resolve(artifacts,file))).mtimeMs>before.get(file))){downloaded=file;break;}}if(downloaded)break;await pause(100);}
    assert.ok(downloaded,report+' actual CSV download');
    const csv=await readFile(resolve(artifacts,downloaded),'utf8');
    assert.ok(csv.includes(evidence.reports[report].title)&&csv.includes('2026-01-01')&&csv.includes('PHP'));
    results.push(report+': annual table matches independent SQL; actual CSV download checked');
    if(report==='sales')await screenshot('desktop-sales-report-2026');
  }
  await navigate('/customers');
  await wait('!!document.querySelector("#main tbody tr")','customers list');
  const firstRow=await evaluate('document.querySelector("#main tbody tr").innerText');
  await button('Next');
  await wait('document.querySelector("#main").innerText.includes("Page 2 of")','pagination page 2');
  assert.notEqual(await evaluate('document.querySelector("#main tbody tr").innerText'),firstRow);
  await replace('input[aria-label="Search customers"]','QA2026');
  await wait('document.querySelector("#main").innerText.includes("20 records")','QA customer search');
  await evaluate('const select=document.querySelector("[aria-label=\\"Customer status filter\\"]");select.value="inactive";select.dispatchEvent(new Event("change",{bubbles:true}));');
  await wait('document.querySelector("#main").innerText.includes("4 records")','inactive filter');
  results.push('Customers: real search, inactive filter, pagination, and changed table rows');
  await replace('input[aria-label="Search customers"]','qa-no-matching-customer-2026');
  await wait('document.body.innerText.includes("No matching records")','empty search result');
  results.push('Customers empty state renders correctly');
  await navigate('/customers/new');
  await wait('!!document.querySelector("input[name=first_name]")','customer form');
  await button('Save customer');
  await wait('document.querySelectorAll("#main [role=alert]").length>=2','client validation');
  results.push('Customer form required validation prevents empty submission');
  const browserRecordFile=resolve(artifacts,'browser-customer.json');
  let previousBrowserRecord;
  try{previousBrowserRecord=JSON.parse(await readFile(browserRecordFile,'utf8'));}catch{}
  if(!previousBrowserRecord){
    await field('First name','QA2026 Browser');
    await field('Last name','Verification');
    await field('Email address','qa2026.browser.'+Date.now()+'@example.test');
    await field('Company','QA2026 Browser create and edit');
    await button('Save customer');
    await wait('/^\\/customers\\/\\d+$/.test(location.pathname) && !!document.querySelector("#main h1")','customer created through UI');
    const id=await evaluate('Number(location.pathname.split("/").pop())');
    await writeFile(browserRecordFile,JSON.stringify({id,state:'created'}));
    await navigate('/customers/'+id+'/edit');
    await wait('!!document.querySelector("input[name=company]")','edit form');
    await field('Company','QA2026 Browser edited successfully');
    await button('Save customer');
    await wait('location.pathname==="/customers/'+id+'" && document.body.innerText.includes("QA2026 Browser edited successfully")','customer updated through UI');
    acceptDialog=false;
    await button('Delete customer');
    assert.ok(dialogs>=1);
    assert.equal(await evaluate('location.pathname'),'/customers/'+id);
    acceptDialog=true;
    await button('Delete customer');
    await wait('location.pathname==="/customers"','new QA customer archived through UI');
    await writeFile(browserRecordFile,JSON.stringify({id,state:'archived',description:'Only this newly created QA browser record was archived; existing rows preserved'}));
    results.push('Browser customer create/read/update/archive; native confirmation cancel and accept');
  } else {
    assert.equal(previousBrowserRecord.state,'archived','Previous browser fixture must be complete before rerun');
    results.push('Prior browser-created QA record remains archived; rerun avoids duplicate creation');
  }
  await navigate('/automation');
  await wait('!!document.querySelector("#main article")','automation workflows');
  await replace('input[aria-label="Search workflows"]','QA2026 Local');
  await wait('document.querySelectorAll("#main article").length===1','workflow search');
  await button('Execution logs');
  await wait('document.body.innerText.includes("Execution logs") && document.body.innerText.includes("succeeded")','actual automation logs');
  results.push('Automation: workflow search and real successful execution logs render');
  await screenshot('desktop-automation-logs');
  let mode='delay';
  let delayed=false;
  socket.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(message.method!=='Fetch.requestPaused')return;
    const request=message.params;
    if(request.request.method==='OPTIONS'){call('Fetch.continueRequest',{requestId:request.requestId}).catch(()=>{});return;}
    if(mode==='error'){
      call('Fetch.fulfillRequest',{requestId:request.requestId,responseCode:500,responseHeaders:[{name:'Content-Type',value:'application/json'},{name:'Access-Control-Allow-Origin',value:'http://localhost:3001'},{name:'Access-Control-Allow-Credentials',value:'true'}],body:Buffer.from(JSON.stringify({success:false,message:'QA simulated reporting failure'})).toString('base64')}).catch(()=>{});
    }else{
      delayed=true;setTimeout(()=>call('Fetch.continueRequest',{requestId:request.requestId}).catch(()=>{}),1500);
    }
  });
  await call('Fetch.enable',{patterns:[{urlPattern:'*localhost:8001/api/v1/reports?*',requestStage:'Request'}]});
  await navigate('/reports');
  await wait('document.querySelector("#main").innerText.includes("Loading records")','real loading indicator');
  await wait('!!document.querySelector("#main table")','loaded report');
  assert.ok(delayed);
  results.push('Report loading indicator verified using delayed real request');
  mode='error';
  await button('Refresh');
  await wait('document.querySelector("#main").innerText.includes("QA simulated reporting failure")','meaningful API error');
  results.push('Report API failure displays meaningful error and retry action');
  await call('Fetch.disable');
  await button('Try again');
  await wait('!!document.querySelector("#main table") && !document.querySelector("#main [role=alert]")','report recovery after retry');
  results.push('Retry recovers the report after simulated failure');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
  for(const route of ['/dashboard','/reports','/customers','/appointments','/tasks']){
    await navigate(route);
    await wait('!!document.querySelector("#main h1") && !document.querySelector("#main").innerText.includes("Loading records")','mobile '+route);
    if(route==='/dashboard'){
      await select('Revenue currency','PHP');
      await wait('!!document.querySelector("[aria-label=\\"Monthly sales performance chart\\"] svg") && !document.body.innerText.includes("Loading charts")','mobile charts finished loading');
      await pause(700);
    }
    if(route==='/reports'){
      await wait('!!document.querySelector("#main table")','mobile report table');
      await select('Revenue currency','PHP');
      await wait('document.querySelector("#main").innerText.includes("PHP") && !!document.querySelector("#main table")','mobile PHP report');
    }
    if(route==='/appointments'){
      await wait('!!document.querySelector(".fc-view-harness") && !document.body.innerText.includes("Loading calendar")','mobile calendar fully rendered');
      await pause(500);
    }
    const width=await evaluate('({viewport:innerWidth,scroll:document.documentElement.scrollWidth})');
    assert.ok(width.scroll<=width.viewport+2,'Mobile root overflow '+route+': '+JSON.stringify(width));
    assert.equal(await evaluate('document.querySelectorAll("#main [role=alert]").length'),0);
    await screenshot('mobile-'+route.slice(1));
    results.push('Mobile 390px: '+route+' loads without page overflow or errors');
  }
  await evaluate('document.querySelector("button[aria-label=\\"Open navigation\\"]").click()');
  await wait('!!document.querySelector("button[aria-label=\\"Close navigation overlay\\"]")','mobile menu opened');
  await evaluate('document.querySelector("button[aria-label=\\"Close navigation overlay\\"]").click()');
  results.push('Mobile navigation opens and closes');
  for(const role of ['Manager','Staff']){
    await call('Page.navigate',{url:'about:blank'});await pause(400);await call('Network.clearBrowserCookies');
    await navigate('/login');
    await wait('!!document.querySelector("input[type=password]")','role login');
    const account=manifest.accounts.find(account=>account.role===role);
    await fill('input[type=email]',account.email);await fill('input[type=password]',account.password);
    await evaluate('document.querySelector("form button").click()');
    await wait('location.pathname==="/dashboard" && document.body.innerText.includes("Welcome,")',role+' dashboard');
    assert.equal((await api('/dashboard/summary')).status,200);
    assert.equal((await api('/users')).status,403);
    assert.equal((await api('/reports?report=sales')).status,role==='Staff'?403:200);
    results.push(role+': mobile login and scoped access boundaries verified');
  }
  assert.deepEqual(errors,[],'No uncaught browser JavaScript errors');
  await writeFile(resolve(artifacts,'results.json'),JSON.stringify({reference:qa.reference,checks:results,errors,viewports:['1440x1100','390x844'],browser:'Microsoft Edge CDP'},null,2));
  console.log(JSON.stringify({passed:results.length,checks:results,errors},null,2));
  await call('Browser.close').catch(()=>{});
} finally {
  socket?.close();
  edge.kill();
}
