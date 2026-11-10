import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const base = process.env.SMARTBIZ_API_URL ?? 'http://localhost:8000';
const frontend = process.env.SMARTBIZ_FRONTEND_URL ?? 'http://localhost:3000';
const accountSource = JSON.parse(await readFile(process.env.SMARTBIZ_ACCOUNTS_FILE ?? resolve(import.meta.dirname, '../backend/storage/app/private/local-accounts.json'), 'utf8'));
const accounts = Array.isArray(accountSource) ? accountSource : accountSource.accounts;
assert.ok(Array.isArray(accounts) && accounts.length >= 3, 'Credentials must contain accounts for all three roles');
for (const role of ['Admin', 'Manager', 'Staff']) {
  assert.ok(accounts.some(account => account.role === role && typeof account.email === 'string' && typeof account.password === 'string' && account.password.length > 0), `Missing valid ${role} credentials`);
}

function session() {
  const cookies = new Map();
  return async (path, { method = 'GET', body, csrf = true } = {}) => {
    const headers = { Accept: 'application/json', Origin: frontend, 'X-Requested-With': 'XMLHttpRequest' };
    if (cookies.size) headers.Cookie = [...cookies].map(([key, value]) => `${key}=${value}`).join('; ');
    if (csrf && cookies.has('XSRF-TOKEN')) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookies.get('XSRF-TOKEN'));
    if (body) headers['Content-Type'] = 'application/json';
    const response = await fetch(`${base}${path}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(';', 1)[0]; const index = pair.indexOf('=');
      cookies.set(pair.slice(0, index), pair.slice(index + 1));
    }
    return response;
  };
}

assert.equal((await fetch(`${base}/up`)).status, 200, 'Laravel health');
assert.equal((await fetch(`${frontend}/login`)).status, 200, 'Next.js login page');
assert.equal((await fetch(`${frontend}/dashboard`)).status, 200, 'Next.js dashboard shell');
for (const route of ['/customers', '/customers/new', '/leads', '/leads/new', '/pipeline', '/sales', '/appointments', '/appointments/new', '/appointments/availability', '/tasks', '/tasks/new', '/automation', '/settings/integrations', '/reports', '/users']) {
  assert.equal((await fetch(`${frontend}${route}`)).status, 200, `Frontend route ${route}`);
}
const anonymous = session();
assert.equal((await anonymous('/api/v1/me')).status, 401);
await anonymous('/sanctum/csrf-cookie');
assert.equal((await anonymous('/api/v1/login', { method: 'POST', body: {email: 'invalid@example.test', password: 'invalid'}, csrf: false })).status, 419);
for (const account of accounts) {
  const send = session();
  const csrf = await send('/sanctum/csrf-cookie');
  assert.equal(csrf.status, 204);
  const login = await send('/api/v1/login', { method: 'POST', body: { email: account.email, password: account.password } });
  assert.equal(login.status, 200, `Login for ${account.role}`);
  const profile = await login.json();
  assert.ok(profile.data.roles.includes(account.role));
  assert.equal(profile.data.email, account.email);
  assert.ok(profile.data.last_login_at);
  assert.equal(profile.data.password, undefined);
  const current = await send('/api/v1/me');
  assert.equal(current.status, 200);
  assert.equal((await current.json()).data.id, profile.data.id);
  assert.equal((await send('/api/v1/dashboard/access')).status, 200);
  assert.equal((await send('/api/v1/team/access')).status, account.role === 'Staff' ? 403 : 200);
  assert.equal((await send('/api/v1/admin/access')).status, account.role === 'Admin' ? 200 : 403);
  assert.equal((await send('/api/v1/users')).status, account.role === 'Admin' ? 200 : 403);
  for (const report of ['sales', 'customers', 'tasks', 'pipeline']) {
    const filters = new URLSearchParams({ report, preset: 'last_30_days', timezone: 'Asia/Manila', currency: 'PHP' });
    const preview = await send(`/api/v1/reports?${filters}`);
    const exported = await send(`/api/v1/reports/export?${filters}`);
    assert.equal(preview.status, account.role === 'Staff' ? 403 : 200, `${account.role} report ${report}`);
    assert.equal(exported.status, account.role === 'Staff' ? 403 : 200, `${account.role} export ${report}`);
    if (account.role !== 'Staff') {
      const data = (await preview.json()).data;
      assert.equal(data.filters.currency, 'PHP');
      assert.match(exported.headers.get('content-type'), /text\/csv/);
      assert.match(exported.headers.get('cache-control'), /no-store/);
      assert.ok((await exported.text()).includes(data.title));
    }
  }
  for (const path of ['/api/v1/customers', '/api/v1/leads']) {
    const response = await send(path);
    assert.equal(response.status, 200, `${account.role} CRM list`);
    const result = await response.json();
    assert.equal(result.success, true);
    assert.ok(Array.isArray(result.data));
    assert.equal(typeof result.meta.total, 'number');
  }
  const stages = await send('/api/v1/pipeline-stages');
  assert.equal(stages.status, 200);
  assert.equal((await stages.json()).data.length, 7);
  assert.equal((await send('/api/v1/crm/assignees')).status, account.role === 'Staff' ? 403 : 200);
  const summary = await send('/api/v1/dashboard/summary?preset=today&timezone=Asia%2FCalcutta');
  assert.equal(summary.status, 200);
  const metrics = (await summary.json()).data;
  assert.equal(typeof metrics.metrics.total_customers, 'number');
  assert.match(metrics.metrics.total_recorded_sales, /^\d+\.\d{2}$/);
  assert.equal(metrics.filters.timezone, 'Asia/Calcutta');
  const analytics = await send('/api/v1/dashboard/analytics?preset=last_7_days');
  assert.equal(analytics.status, 200);
  assert.equal((await analytics.json()).data.customer_growth.length, 7);
  assert.equal((await send('/api/v1/sales')).status, 200);
  assert.equal((await send('/api/v1/sales', {method: 'POST', body: {}})).status, account.role === 'Staff' ? 403 : 422);
  assert.equal((await send('/api/v1/appointments')).status, 200);
  const from = new Date(); from.setUTCHours(0,0,0,0);
  const to = new Date(from); to.setUTCDate(to.getUTCDate()+1);
  const calendarQuery = new URLSearchParams({start_at:from.toISOString().replace(/\.\d{3}Z$/,'Z'),end_at:to.toISOString().replace(/\.\d{3}Z$/,'Z')});
  const calendar = await send(`/api/v1/appointments/calendar?${calendarQuery}`);
  assert.equal(calendar.status, 200);
  assert.ok(Array.isArray((await calendar.json()).data));
  assert.equal((await send(`/api/v1/staff/${profile.data.id}/availability`)).status, 200);
  assert.equal((await send('/api/v1/appointments', {method:'POST',body:{}})).status, account.role === 'Staff' ? 403 : 422);
  assert.equal((await send('/api/v1/tasks')).status, 200);
  assert.equal((await send('/api/v1/tasks', {method:'POST', body:{}})).status, account.role === 'Staff' ? 403 : 422);
  assert.equal((await send('/api/v1/tasks/staff')).status, account.role === 'Staff' ? 403 : 200);
  const notices = await send('/api/v1/notifications');
  assert.equal(notices.status, 200);
  assert.ok(Array.isArray((await notices.json()).data));
  const unread = await send('/api/v1/notifications/unread-count');
  assert.equal(unread.status, 200);
  assert.equal(typeof (await unread.json()).data.unread_count, 'number');
  for (const route of ['/automations','/automations/catalog','/integrations','/webhooks']) {
    const response = await send(`/api/v1${route}`);
    assert.equal(response.status, account.role === 'Admin' ? 200 : 403, `${account.role} ${route} access`);
    if (account.role === 'Admin') {
      const body = await response.json(); assert.equal(body.success,true);
      if (route === '/integrations') { assert.equal(typeof body.data.google_configured,'boolean'); assert.ok(Array.isArray(body.data.accounts)); assert.ok(body.data.accounts.every(item => !('encrypted_credentials' in item))); }
    }
  }
  for (const route of ['/automations','/webhooks']) {
    assert.equal((await send(`/api/v1${route}`,{method:'POST',body:{}})).status,account.role === 'Admin' ? 422 : 403);
  }
  assert.equal((await send('/api/v1/logout', { method: 'POST' })).status, 200);
  assert.equal((await send('/api/v1/me')).status, 401, `Session invalidated for ${account.role}`);
  console.log(`PASS ${account.role}: login, current user, CRM, dashboard, sales, scheduling, tasks, notifications, automation/integration access, permission boundaries, logout`);
}
console.log('PASS native HTTP checks: Laravel, Next.js, MySQL-backed sessions, CSRF, and role enforcement');
