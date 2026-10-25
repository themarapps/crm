const app = document.querySelector('#app');
const state = { user: null, csrf: '', team: [], filters: {}, page: 1, currentCustomer: null, pendingImportFile: null };
const labels = {
  new: 'جديد', contacted: 'تم التواصل', interested: 'مهتم', not_interested: 'غير مهتم',
  active: 'نشط', trial: 'تجريبي', expired: 'منتهي', canceled: 'ملغي', past_due: 'متأخر',
  open: 'مفتوحة', done: 'مكتملة', admin: 'مسؤول', manager: 'مدير', member: 'عضو فريق',
};
const routes = [
  ['dashboard', '/', 'الرئيسية', 'home'], ['customers', '/customers', 'العملاء', 'customers'],
  ['tasks', '/tasks', 'المهام', 'tasks'], ['reports', '/reports', 'التقارير', 'reports'],
  ['import', '/import', 'استيراد', 'import'], ['team', '/team', 'الفريق', 'team'],
  ['settings', '/settings', 'الربط', 'settings'],
];
const icons = {
  home: '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  customers: '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 5"/>',
  tasks: '<path d="m5 12 4 4L19 6"/><path d="M21 12v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10"/>',
  import: '<path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
  team: '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2"/><path d="M3 20a6 6 0 0 1 12 0M15 16a5 5 0 0 1 6 4"/>',
  reports: '<path d="M4 19V5m0 14h16"/><path d="M8 15v-4m4 4V7m4 8v-6m4 6V4"/>',
  settings: '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="m19.4 15 .1.1a1.7 1.7 0 0 1-2.4 2.4l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a1.7 1.7 0 0 1-3.4 0v-.2a1.7 1.7 0 0 0-2.9-1.2l-.1.1a1.7 1.7 0 1 1-2.4-2.4l.1-.1A1.7 1.7 0 0 0 4.2 12h-.2a1.7 1.7 0 0 1 0-3.4h.2a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a1.7 1.7 0 1 1 2.4-2.4l.1.1a1.7 1.7 0 0 0 2.9-1.2v-.2a1.7 1.7 0 0 1 3.4 0v.2a1.7 1.7 0 0 0 2.9 1.2l.1-.1a1.7 1.7 0 1 1 2.4 2.4l-.1.1a1.7 1.7 0 0 0 1.2 2.9h.2a1.7 1.7 0 0 1 0 3.4h-.2a1.7 1.7 0 0 0-1.2 2.9Z"/>',
  logout: '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
};

function icon(name) {
  return `<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name]}</svg>`;
}

function routePath() {
  return location.hash.replace(/^#\/?/, '') || 'dashboard';
}

function escapeHtml(input) {
  return String(input ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[character]);
}

async function request(path, options = {}) {
  const headers = new Headers(options.headers || {});
  if (state.csrf && options.method && options.method !== 'GET') headers.set('X-CSRF-Token', state.csrf);
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
  const response = await fetch(path, { ...options, headers, credentials: 'same-origin' });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || Object.values(body.fields || {})[0] || 'تعذر تنفيذ الطلب.');
  return body;
}

function showError(error) {
  const region = document.querySelector('[data-error]');
  if (region) region.innerHTML = `<div class="error" role="alert">${escapeHtml(error.message)}</div>`;
  else window.alert(error.message);
}

async function initialize() {
  try {
    const csrf = await request('/api/csrf');
    state.csrf = csrf.csrf_token;
    try {
      const session = await request('/api/me');
      state.user = session.user;
      state.csrf = session.csrf_token;
    } catch (error) {
      if (!location.hash || location.hash === '#/') location.hash = '#/dashboard';
    }
    render();
  } catch (error) {
    app.innerHTML = `<div class="login-wrap"><section class="login-card"><h1>تعذر الاتصال</h1><p class="muted">${escapeHtml(error.message)}</p></section></div>`;
  }
}

function renderLogin() {
  app.innerHTML = loginMarkup();
  document.querySelector('#login-form').addEventListener('submit', submitLogin);
}

function loginMarkup() {
  return `<div class="login-wrap"><form class="login-card" id="login-form">
    <div class="brand-mark">CRM</div><h1>تسجيل الدخول</h1><p class="muted">سجّل الدخول لمتابعة العملاء والاشتراكات.</p>
    <div data-error></div><div class="field"><label for="login-email">البريد الإلكتروني</label><input id="login-email" name="email" type="email" autocomplete="username" required></div>
    <div class="field"><label for="login-password">كلمة المرور</label><input id="login-password" name="password" type="password" autocomplete="current-password" required></div>
    <button class="btn primary" style="width:100%;margin-top:20px" type="submit">دخول</button>
  </form></div>`;
}

async function submitLogin(event) {
  event.preventDefault();
  const submitButton = event.currentTarget.querySelector('button');
  submitButton.disabled = true;
  try {
    const formFields = Object.fromEntries(new FormData(event.currentTarget));
    const session = await request('/api/auth/login', { method: 'POST', body: JSON.stringify(formFields) });
    state.user = session.user;
    state.csrf = session.csrf_token;
    location.hash = '#/dashboard';
    render();
  } catch (error) { showError(error); submitButton.disabled = false; }
}

function shell(content, activeKey, title) {
  const visibleRoutes = routes.filter(([key]) => !((['team', 'settings'].includes(key) && state.user.role !== 'admin') || (['import', 'reports'].includes(key) && state.user.role === 'member')));
  const navigation = visibleRoutes.map(([key, path, label, iconName]) => `<a class="${key === activeKey ? 'active' : ''}" href="#${path}">${icon(iconName)}<span class="nav-label">${label}</span></a>`).join('');
  const mobile = visibleRoutes.map(([key, path, label, iconName]) => `<a class="${key === activeKey ? 'active' : ''}" href="#${path}">${icon(iconName)}${label}</a>`).join('');
  return `<div class="shell"><aside class="sidebar"><div class="brand"><span class="brand-mark">CRM</span><span class="brand-text">إدارة العملاء</span></div><nav class="nav">${navigation}</nav><div class="side-bottom"><button class="side-action" data-action="logout">${icon('logout')}<span class="side-label">تسجيل الخروج</span></button><div class="user-mini"><span class="avatar">${escapeHtml((state.user.name || 'م').slice(0, 1))}</span><div class="user-info"><strong>${escapeHtml(state.user.name)}</strong><small>${labels[state.user.role] || 'عضو فريق'}</small></div></div></div></aside>
  <section class="main"><header class="topbar"><div class="top-title">${title}</div><div class="top-actions"><button class="btn primary desktop-action" data-action="new-customer">+ عميل جديد</button><span class="avatar">${escapeHtml((state.user.name || 'م').slice(0, 1))}</span></div></header><main class="content">${content}</main></section><nav class="mobile-nav">${mobile}</nav></div>`;
}

function pageHead(title, description, action = '') {
  return `<div class="page-head"><div><h1>${title}</h1><p>${description}</p></div>${action}</div>`;
}

function statCard(title, metric, hint = '') {
  return `<section class="stat"><div class="stat-label">${title}</div><div class="stat-number">${escapeHtml(metric)}</div>${hint ? `<div class="stat-note">${hint}</div>` : ''}</section>`;
}

function badge(status) {
  const safe = escapeHtml(status || '—');
  let type = 'neutral';
  if (['active', 'done', 'interested'].includes(status)) type = 'active';
  if (['trial', 'open', 'contacted'].includes(status)) type = 'warning';
  if (['expired', 'canceled', 'past_due', 'not_interested'].includes(status)) type = 'danger';
  return `<span class="badge ${type}">${escapeHtml(labels[status] || safe)}</span>`;
}

function dateLabel(dateText) {
  if (!dateText) return '—';
  const date = new Date(dateText.includes('T') ? dateText : `${dateText.replace(' ', 'T')}Z`);
  return Number.isNaN(date.valueOf()) ? escapeHtml(dateText) : new Intl.DateTimeFormat('ar-SA', { dateStyle: 'medium' }).format(date);
}

async function render() {
  if (!state.user) return renderLogin();
  const route = routePath().split('/');
  try {
    if (route[0] === 'dashboard') return await renderDashboard();
    if (route[0] === 'customers' && route[1]) return await renderCustomer(route[1]);
    if (route[0] === 'customers') return await renderCustomers();
    if (route[0] === 'tasks') return await renderTasks();
    if (route[0] === 'reports' && state.user.role !== 'member') return await renderReports();
    if (route[0] === 'import' && state.user.role !== 'member') return renderImport();
    if (route[0] === 'team' && state.user.role === 'admin') return await renderTeam();
    if (route[0] === 'settings' && state.user.role === 'admin') return await renderIntegrationSettings();
    location.hash = '#/dashboard';
  } catch (error) { showError(error); }
}

async function renderDashboard() {
  const [dashboard, customers] = await Promise.all([request('/api/dashboard'), request('/api/customers?per_page=6')]);
  const counts = dashboard.counts;
  const overdueRows = dashboard.overdue_tasks.length ? dashboard.overdue_tasks.map((task) => `<div class="task-row"><div><strong>${escapeHtml(task.title)}</strong><small>${escapeHtml(task.customer_name)} · ${dateLabel(task.due_at)}</small></div><a class="btn small" href="#/customers/${task.customer_id}">فتح العميل</a></div>`).join('') : '';
  const taskRows = dashboard.upcoming_tasks.length ? dashboard.upcoming_tasks.map((task) => `<div class="task-row"><div><strong>${escapeHtml(task.title)}</strong><div class="person"><small>${escapeHtml(task.customer_name)} · ${dateLabel(task.due_at)}</small></div></div><a class="btn small" href="#/customers/${task.customer_id}">العميل</a></div>`).join('') : '<div class="empty">لا توجد متابعات قادمة.</div>';
  const customerRows = customers.data.length ? customers.data.map(customerRow).join('') : '<div class="empty">أضف أول عميل لبدء المتابعة.</div>';
  app.innerHTML = shell(`${pageHead('الرئيسية', 'ملخص سريع للعملاء والمهام اليومية.')}${overdueRows ? `<section class="panel overdue-panel"><div class="panel-head"><h2>متابعات تحتاج إجراء</h2><span class="badge danger">${counts.overdue_tasks} متأخرة</span></div><div class="panel-body">${overdueRows}</div></section>` : ''}
    <div class="stats">${statCard('إجمالي العملاء', counts.customers)}${statCard('اشتراكات نشطة', counts.active_subscriptions)}${statCard('عملاء جدد', counts.new_customers)}${statCard('متابعات متأخرة', counts.overdue_tasks, counts.today_tasks ? `${counts.today_tasks} متابعة اليوم` : '')}</div>
    <div class="grid-two"><section class="panel"><div class="panel-head"><h2>المتابعات القادمة</h2><a class="btn small" href="#/tasks">كل المهام</a></div><div class="panel-body">${taskRows}</div></section><section class="panel"><div class="panel-head"><h2>أحدث العملاء</h2><a class="btn small" href="#/customers">عرض الكل</a></div><div class="panel-body">${customerRows}</div></section></div>`, 'dashboard', 'نظرة عامة');
  bindShell();
}

function customerRow(customer) {
  return `<a class="customer-row" href="#/customers/${customer.id}" style="color:inherit;text-decoration:none"><div class="person"><span class="avatar">${escapeHtml((customer.name || 'ع').slice(0, 1))}</span><div><strong>${escapeHtml(customer.name)}</strong><small>${escapeHtml(customer.email)}</small></div></div>${badge(customer.subscription_status)}</a>`;
}

async function renderReports() {
  const report = await request('/api/reports/summary');
  const reportGroup = (title, rows) => `<section class="panel"><div class="panel-head"><h2>${title}</h2></div><div class="panel-body report-list">${rows.length ? rows.map((row) => `<div class="report-row"><span>${escapeHtml(labels[row.label] || row.label || 'غير محدد')}</span><strong>${escapeHtml(row.total)}</strong></div>`).join('') : '<div class="empty">لا توجد بيانات بعد.</div>'}</div></section>`;
  app.innerHTML = shell(`${pageHead('التقارير', 'ملخص توزيع العملاء حسب مرحلة المتابعة والاشتراك والباقة.', '<a class="btn" href="/api/customers/export.csv">تصدير العملاء CSV</a>')}<div class="stats">${statCard('إجمالي العملاء', report.total)}${statCard('اشتراكات نشطة', report.active_subscriptions)}</div><div class="grid-two">${reportGroup('مراحل المتابعة', report.stages)}${reportGroup('حالات الاشتراك', report.subscriptions)}${reportGroup('الباقات', report.plans)}</div>`, 'reports', 'التقارير');
  bindShell();
}

async function renderIntegrationSettings() {
  const settings = await request('/api/settings/source');
  app.innerHTML = shell(integrationSettingsMarkup(settings), 'settings', 'إعدادات الربط');
  bindShell();
  bindIntegrationForm();
}

function integrationSettingsMarkup(settings) {
  const notice = settings.encryption_ready
    ? '<div class="notice integration-note">بيانات الدخول تحفظ مشفرة، ولا يعرض النظام كلمة المرور بعد الحفظ. هذه الصفحة تحفظ بيانات الاتصال؛ تفعيل المزامنة يحتاج ربط آلية المصدر.</div>'
    : '<div class="error integration-note">يلزم إعداد مفتاح التشفير على الخادم قبل حفظ بيانات الدخول.</div>';
  const status = settings.credentials_configured ? 'بيانات محفوظة' : 'غير مكتمل';
  const userPlaceholder = settings.credentials_configured ? 'اتركه فارغًا للإبقاء على الحالي' : 'اسم المستخدم';
  const passwordPlaceholder = settings.credentials_configured ? 'اتركها فارغة للإبقاء على الحالية' : 'كلمة المرور';
  return `${pageHead('الربط بمصدر العملاء', 'احفظ بيانات الدخول للمصدر الذي تُجلب منه سجلات العملاء.')}${notice}
    <section class="panel integration-panel"><div class="panel-head"><h2>بيانات الاتصال</h2><span class="badge ${settings.credentials_configured ? 'active' : 'neutral'}">${status}</span></div><div class="panel-body">
    <form id="integration-form"><div class="form-grid"><div class="field full"><label for="source-url">الرابط الأساسي للمصدر</label><input id="source-url" name="source_url" type="url" value="${escapeHtml(settings.source_url)}" placeholder="https://example.com" required></div>
    <div class="field"><label for="source-username">اسم المستخدم</label><input id="source-username" name="username" autocomplete="username" placeholder="${userPlaceholder}"></div>
    <div class="field"><label for="source-password">كلمة المرور</label><input id="source-password" name="password" type="password" autocomplete="new-password" placeholder="${passwordPlaceholder}"></div></div>
    <div id="integration-status" aria-live="polite"></div><div class="form-actions"><button class="btn primary" type="submit" ${settings.encryption_ready ? '' : 'disabled'}>حفظ بيانات الربط</button></div></form></div></section>`;
}

function bindIntegrationForm() {
  document.querySelector('#integration-form').addEventListener('submit', saveIntegrationSettings);
}

async function saveIntegrationSettings(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    await request('/api/settings/source', { method: 'PUT', body: JSON.stringify(Object.fromEntries(new FormData(form))) });
    form.elements.password.value = '';
    form.elements.username.value = '';
    document.querySelector('#integration-status').innerHTML = '<div class="notice">تم حفظ بيانات الربط بشكل مشفر.</div>';
  } catch (error) {
    document.querySelector('#integration-status').innerHTML = `<div class="error" role="alert">${escapeHtml(error.message)}</div>`;
  } finally {
    button.disabled = false;
  }
}

async function renderCustomers() {
  const params = new URLSearchParams({ page: String(state.page), per_page: '25' });
  Object.entries(state.filters).forEach(([key, value]) => value && params.set(key, value));
  const [response, options, team] = await Promise.all([request(`/api/customers?${params}`), request('/api/customers/options'), request('/api/team')]);
  state.team = team.data;
  const rows = response.data.length ? response.data.map((customer) => `<tr><td data-label="العميل"><a class="table-person" href="#/customers/${customer.id}" style="color:inherit;text-decoration:none"><span class="avatar">${escapeHtml((customer.name || 'ع').slice(0, 1))}</span><span><strong>${escapeHtml(customer.name)}</strong><small>${escapeHtml(customer.email)}</small></span></a></td><td data-label="الهاتف">${escapeHtml(customer.phone || '—')}</td><td data-label="الباقة">${escapeHtml(customer.plan || '—')}</td><td data-label="الاشتراك">${badge(customer.subscription_status)}</td><td data-label="مرحلة المتابعة">${badge(customer.crm_stage)}</td><td data-label="المسؤول">${escapeHtml(team.data.find((member) => Number(member.id) === Number(customer.assigned_to))?.name || 'غير معين')}</td><td data-label="التسجيل">${dateLabel(customer.registered_at)}</td></tr>`).join('') : '<tr><td colspan="7"><div class="empty">لا توجد نتائج مطابقة. جرّب تغيير الفلاتر.</div></td></tr>';
  const planOptions = options.plans.map((plan) => `<option value="${escapeHtml(plan)}" ${state.filters.plan === plan ? 'selected' : ''}>${escapeHtml(plan)}</option>`).join('');
  const statusOptions = options.subscription_statuses.map((status) => `<option value="${escapeHtml(status)}" ${state.filters.subscription_status === status ? 'selected' : ''}>${escapeHtml(labels[status] || status)}</option>`).join('');
  const stageOptions = options.stages.map((stage) => `<option value="${escapeHtml(stage)}" ${state.filters.stage === stage ? 'selected' : ''}>${escapeHtml(labels[stage] || stage)}</option>`).join('');
  const assigneeOptions = team.data.map((member) => `<option value="${member.id}" ${state.filters.assigned_to === String(member.id) ? 'selected' : ''}>${escapeHtml(member.name)}</option>`).join('');
  const exportLink = ['admin', 'manager'].includes(state.user.role) ? `<a class="btn" href="/api/customers/export.csv?${params.toString()}">تصدير CSV</a>` : '';
  app.innerHTML = shell(`${pageHead('العملاء', `إجمالي ${response.total} عميل`, `${exportLink}<button class="btn primary" data-action="new-customer">+ إضافة عميل</button>`)}
    <section class="panel"><form class="toolbar" id="filters"><div class="field search-field"><label for="filter-q">بحث</label><input class="search" id="filter-q" name="q" value="${escapeHtml(state.filters.q || '')}" placeholder="الاسم أو البريد أو الهاتف"></div><div class="field"><label>الاشتراك</label><select name="subscription_status"><option value="">كل الحالات</option>${statusOptions}</select></div><div class="field"><label>الباقة</label><select name="plan"><option value="">كل الباقات</option>${planOptions}</select></div><div class="field"><label>مرحلة المتابعة</label><select name="stage"><option value="">كل المراحل</option>${stageOptions}</select></div><div class="field"><label>المسؤول</label><select name="assigned_to"><option value="">كل الفريق</option>${assigneeOptions}</select></div><button class="btn" type="submit">تطبيق</button><button class="btn" type="button" data-action="clear-filters">مسح</button></form>
    <div class="table-wrap"><table><thead><tr><th>العميل</th><th>الهاتف</th><th>الباقة</th><th>الاشتراك</th><th>مرحلة المتابعة</th><th>المسؤول</th><th>تاريخ التسجيل</th></tr></thead><tbody>${rows}</tbody></table></div><div class="pagination"><span>صفحة ${response.page} · ${response.total} عميل</span><span><button class="btn small" data-action="previous" ${state.page <= 1 ? 'disabled' : ''}>السابق</button> <button class="btn small" data-action="next" ${state.page * response.per_page >= response.total ? 'disabled' : ''}>التالي</button></span></div></section>`, 'customers', 'العملاء');
  bindShell();
  document.querySelector('#filters').addEventListener('submit', (event) => { event.preventDefault(); state.filters = Object.fromEntries(new FormData(event.currentTarget)); state.page = 1; render(); });
}

async function renderCustomer(customerId) {
  const response = await request(`/api/customers/${customerId}`);
  state.team = (await request('/api/team')).data;
  const customer = response.customer;
  state.currentCustomer = customer;
  const notes = response.notes.length ? response.notes.map((note) => `<div class="task-row"><div><strong>${escapeHtml(note.author)}</strong><p>${escapeHtml(note.body)}</p><small class="muted">${dateLabel(note.created_at)}</small></div></div>`).join('') : '<div class="empty">لا توجد ملاحظات بعد.</div>';
  const tasks = response.tasks.length ? response.tasks.map(taskCard).join('') : '<div class="empty">لا توجد مهام للعميل.</div>';
  const activity = response.activity.length ? response.activity.map((entry) => `<div class="task-row"><div><strong>${escapeHtml(entry.summary)}</strong><small>${escapeHtml(entry.actor_name || 'النظام')} · ${dateLabel(entry.created_at)}</small></div></div>`).join('') : '<div class="empty">سيظهر هنا سجل تحديثات العميل ومتابعاته.</div>';
  app.innerHTML = shell(`${pageHead('ملف العميل', `تمت الإضافة ${dateLabel(customer.registered_at)}`, `<button class="btn" data-action="edit-customer">تعديل البيانات</button>`)}
    <section class="panel"><div class="detail-hero"><div class="person"><span class="avatar">${escapeHtml((customer.name || 'ع').slice(0, 1))}</span><div><h2>${escapeHtml(customer.name)}</h2><p>${escapeHtml(customer.email)}${customer.phone ? ` · ${escapeHtml(customer.phone)}` : ''}</p></div></div>${badge(customer.subscription_status)}</div><div class="panel-body detail-grid">${detailField('الباقة', customer.plan)}${detailField('دورة الفوترة', customer.billing_cycle)}${detailField('حالة الدفع', customer.payment_status)}${detailField('مرحلة المتابعة', labels[customer.crm_stage] || customer.crm_stage)}${detailField('المسؤول', state.team.find((member) => Number(member.id) === Number(customer.assigned_to))?.name || 'غير معين')}${detailField('الشركة', customer.company_url)}${detailField('الخدمات', customer.services)}${detailField('تاريخ التسجيل', dateLabel(customer.registered_at))}</div></section>
    <div class="content-grid"><div><section class="panel"><div class="panel-head"><h2>الملاحظات</h2></div><div class="panel-body"><form id="note-form"><div class="field"><label for="note-body">ملاحظة جديدة</label><textarea id="note-body" name="body" required maxlength="10000" placeholder="اكتب ملخص المكالمة أو أي معلومة مهمة"></textarea></div><button class="btn primary" style="margin-top:10px" type="submit">حفظ الملاحظة</button><div data-error></div></form>${notes}</div></section></div><div><section class="panel"><div class="panel-head"><h2>المهام</h2><button class="btn small" data-action="new-task">+ مهمة</button></div><div class="panel-body">${tasks}</div></section></div></div><section class="panel activity-panel"><div class="panel-head"><h2>سجل نشاط العميل</h2></div><div class="panel-body">${activity}</div></section>`, 'customers', 'ملف العميل');
  bindShell();
  document.querySelector('#note-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try { await request(`/api/customers/${customerId}/notes`, { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) }); await renderCustomer(customerId); }
    catch (error) { showError(error); }
  });
}

function detailField(title, fieldContent) {
  return `<div class="detail-field"><small>${title}</small><strong>${escapeHtml(fieldContent || '—')}</strong></div>`;
}

function taskCard(task) {
  return `<div class="task-row"><div><strong>${escapeHtml(task.title)}</strong><small>${dateLabel(task.due_at)} · ${escapeHtml(task.assignee_name || 'بدون مسؤول')}</small></div>${task.status === 'open' ? `<button class="btn small" data-action="complete-task" data-id="${task.id}">إنهاء</button>` : badge('done')}</div>`;
}

async function renderTasks() {
  const [response, team] = await Promise.all([request('/api/tasks'), request('/api/team')]);
  state.team = team.data;
  const groups = { overdue: [], today: [], upcoming: [], done: [] };
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Riyadh' });
  response.data.forEach((task) => {
    if (task.status === 'done') return groups.done.push(task);
    const due = new Date(task.due_at.replace(' ', 'T') + 'Z').toLocaleDateString('en-CA', { timeZone: 'Asia/Riyadh' });
    const key = due < today ? 'overdue' : due === today ? 'today' : 'upcoming';
    groups[key].push(task);
  });
  const section = (key, title) => `<section class="panel"><div class="panel-head"><h2>${title}</h2><span class="badge">${groups[key].length}</span></div><div class="panel-body">${groups[key].length ? groups[key].map(taskCard).join('') : '<div class="empty">لا توجد مهام هنا.</div>'}</div></section>`;
  app.innerHTML = shell(`${pageHead('المهام والمتابعات', 'تابع المواعيد المتأخرة والقادمة.', '<button class="btn primary" data-action="new-task">+ إضافة مهمة</button>')}<div class="task-sections">${section('overdue', 'متأخرة')}${section('today', 'اليوم')}${section('upcoming', 'قادمة')}${section('done', 'مكتملة')}</div>`, 'tasks', 'المهام');
  bindShell();
}

function renderImport() {
  app.innerHTML = shell(`${pageHead('استيراد العملاء', 'أضف عملاء من ملف CSV مع الحفاظ على بيانات المتابعة الحالية.')}
    <section class="panel"><div class="panel-head"><h2>رفع ملف CSV</h2></div><div class="panel-body"><div class="notice">يشترط وجود الاسم والبريد الإلكتروني. تتم مطابقة السجلات بالبريد: السجل المطابق يُحدّث بيانات الحساب مع الاحتفاظ بمرحلة المتابعة والمسؤول والملاحظات والمهام، والبريد المكرر داخل الملف يُتخطى.</div><form id="import-form"><label class="dropzone" for="csv-file"><strong>اختر ملف CSV من جهازك</strong><div class="muted" style="font-size:12px;margin-top:7px">الحد الأقصى 5 ميجابايت و10,000 صف</div><input id="csv-file" name="file" type="file" accept=".csv,text/csv" required></label><div data-error></div><button class="btn primary" style="margin-top:14px" type="submit">معاينة الملف</button></form><div id="import-result"></div></div></section>`, 'import', 'استيراد البيانات');
  bindShell();
  state.pendingImportFile = null;
  const uploadForm = document.querySelector('#import-form');
  uploadForm.addEventListener('submit', previewImport);
  uploadForm.querySelector('input[type="file"]').addEventListener('change', () => {
    state.pendingImportFile = null;
    document.querySelector('#import-result').innerHTML = '';
  });
}

async function previewImport(event) {
  event.preventDefault();
  const uploadForm = event.currentTarget;
  const previewButton = uploadForm.querySelector('button');
  const uploadFile = uploadForm.querySelector('input[type="file"]').files[0];
  const summaryRegion = document.querySelector('#import-result');
  previewButton.disabled = true;
  state.pendingImportFile = null;
  summaryRegion.innerHTML = '<div class="loading">جارٍ تحليل الملف…</div>';
  try {
    const response = await request('/api/import/csv/preview', { method: 'POST', body: new FormData(uploadForm) });
    state.pendingImportFile = uploadFile;
    summaryRegion.innerHTML = importPreviewMarkup(response);
    summaryRegion.querySelector('[data-confirm-import]').addEventListener('click', confirmImport);
  } catch (error) { summaryRegion.innerHTML = `<div class="error" role="alert">${escapeHtml(error.message)}</div>`; }
  previewButton.disabled = false;
}

function importPreviewMarkup(preview) {
  const sampleRows = preview.sample.map((row) => `<tr><td data-label="الصف">${row.line}</td><td data-label="العميل">${escapeHtml(row.name || '—')}</td><td data-label="البريد">${escapeHtml(row.email || '—')}</td><td data-label="النتيجة">${escapeHtml(importStatusLabel(row.status))}${row.reason ? `<small class="muted">${escapeHtml(row.reason)}</small>` : ''}</td></tr>`).join('');
  return `<div class="stats" style="margin-top:18px">${statCard('إجمالي الصفوف', preview.total)}${statCard('عملاء جدد', preview.created)}${statCard('تحديثات', preview.updated)}${statCard('تخطي', preview.skipped)}</div><div class="panel"><div class="panel-head"><h2>عينة من الصفوف</h2><span class="muted">أول ${preview.sample.length} صفوف</span></div><div class="table-wrap"><table><thead><tr><th>الصف</th><th>العميل</th><th>البريد</th><th>النتيجة</th></tr></thead><tbody>${sampleRows}</tbody></table></div></div><button class="btn primary" data-confirm-import ${preview.created + preview.updated === 0 ? 'disabled' : ''}>تأكيد استيراد ${preview.created + preview.updated} سجل</button>`;
}

function importStatusLabel(status) {
  return { created: 'جديد', updated: 'سيتم تحديثه', skipped: 'سيتم تخطيه' }[status] || status;
}

async function confirmImport(event) {
  const confirmButton = event.currentTarget;
  const resultRegion = document.querySelector('#import-result');
  confirmButton.disabled = true;
  resultRegion.insertAdjacentHTML('afterbegin', '<div class="loading">جارٍ استيراد البيانات…</div>');
  const uploadBody = new FormData();
  uploadBody.append('file', state.pendingImportFile);
  try {
    const report = await request('/api/import/csv', { method: 'POST', body: uploadBody });
    state.pendingImportFile = null;
    resultRegion.innerHTML = importReportMarkup(report);
  } catch (error) { resultRegion.insertAdjacentHTML('afterbegin', `<div class="error" role="alert">${escapeHtml(error.message)}</div>`); confirmButton.disabled = false; }
}

function importReportMarkup(report) {
  const errors = report.errors.slice(0, 30).map((entry) => `<div class="task-row"><span>صف ${entry.row} · ${escapeHtml(entry.email || 'بريد غير صالح')}</span><small>${escapeHtml(entry.reason)}</small></div>`).join('');
  return `<div class="stats" style="margin-top:18px">${statCard('عملاء جدد', report.created)}${statCard('تم تحديثهم', report.updated)}${statCard('تم تخطيهم', report.skipped)}</div>${errors ? `<div class="panel"><div class="panel-head"><h2>صفوف لم تُستورد</h2></div><div class="panel-body">${errors}</div></div>` : '<div class="notice">اكتمل الاستيراد دون أخطاء.</div>'}`;
}

async function renderTeam() {
  const response = await request('/api/team');
  state.team = response.data;
  const canManage = state.user.role === 'admin';
  const rows = response.data.map((member) => `<tr><td data-label="عضو الفريق"><div class="table-person"><span class="avatar">${escapeHtml(member.name.slice(0, 1))}</span><strong>${escapeHtml(member.name)}</strong></div></td><td data-label="البريد">${escapeHtml(member.email)}</td><td data-label="الصلاحية">${badge(member.role)}</td><td data-label="الحالة">${member.is_active ? badge('active') : badge('expired')}</td><td data-label="إجراء">${canManage ? `<button class="btn small" data-action="edit-member" data-id="${member.id}">تعديل</button>` : '—'}</td></tr>`).join('');
  app.innerHTML = shell(`${pageHead('الفريق', 'أعضاء الفريق المسؤولون عن متابعة العملاء.', canManage ? '<button class="btn primary" data-action="new-member">+ إضافة عضو</button>' : '')}<section class="panel"><div class="table-wrap"><table><thead><tr><th>عضو الفريق</th><th>البريد</th><th>الصلاحية</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>${rows || '<tr><td colspan="5"><div class="empty">لا يوجد أعضاء.</div></td></tr>'}</tbody></table></div></section>`, 'team', 'إدارة الفريق');
  bindShell();
}

function bindShell() {
  document.querySelectorAll('[data-action]').forEach((button) => button.addEventListener('click', () => runAction(button)));
}

async function runAction(button) {
  try {
    if (button.dataset.action === 'logout') {
      await request('/api/auth/logout', { method: 'POST', body: '{}' }); state.user = null; state.csrf = ''; return render();
    }
    if (button.dataset.action === 'new-customer') return customerDialog();
    if (button.dataset.action === 'edit-customer') return customerDialog(state.currentCustomer);
    if (button.dataset.action === 'new-task') return taskDialog();
    if (button.dataset.action === 'new-member') return memberDialog();
    if (button.dataset.action === 'edit-member') return memberDialog(state.team.find((member) => String(member.id) === button.dataset.id));
    if (button.dataset.action === 'clear-filters') { state.filters = {}; state.page = 1; return render(); }
    if (button.dataset.action === 'previous') { state.page = Math.max(1, state.page - 1); return render(); }
    if (button.dataset.action === 'next') { state.page++; return render(); }
    if (button.dataset.action === 'complete-task') return completeTask(button.dataset.id);
  } catch (error) { showError(error); }
}

function openDialog(title, content) {
  document.querySelector('#active-dialog')?.remove();
  document.body.insertAdjacentHTML('beforeend', `<dialog class="dialog" id="active-dialog"><div class="dialog-head"><h2>${title}</h2><button class="close" type="button" aria-label="إغلاق">×</button></div><div class="dialog-body">${content}</div></dialog>`);
  const dialog = document.querySelector('#active-dialog');
  dialog.querySelector('.close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
  dialog.showModal();
  return dialog;
}

function selectOptions(options, selected, getOptionLabel = (option) => option.name, getOptionValue = (option) => option.id) {
  return options.map((option) => `<option value="${escapeHtml(getOptionValue(option))}" ${String(getOptionValue(option)) === String(selected ?? '') ? 'selected' : ''}>${escapeHtml(getOptionLabel(option))}</option>`).join('');
}

function customerFields(customer = {}) {
  const field = (name, label, type = 'text') => `<div class="field"><label for="f-${name}">${label}</label><input id="f-${name}" name="${name}" type="${type}" value="${escapeHtml(customer[name] ?? '')}" ${name === 'name' || name === 'email' ? 'required' : ''}></div>`;
  return `<div class="form-grid">${field('name', 'اسم العميل')}${field('email', 'البريد الإلكتروني', 'email')}${field('phone', 'رقم الهاتف', 'tel')}${field('company_url', 'رابط الشركة', 'url')}${field('plan', 'الباقة')}${field('subscription_status', 'حالة الاشتراك')}${field('billing_cycle', 'دورة الفوترة')}${field('payment_status', 'حالة الدفع')}
    <div class="field"><label for="f-crm_stage">مرحلة المتابعة</label><select id="f-crm_stage" name="crm_stage">${selectOptions(['new', 'contacted', 'interested', 'not_interested'].map((id) => ({ id, name: labels[id] })), customer.crm_stage || 'new')}</select></div>
    <div class="field"><label for="f-assigned_to">المسؤول</label><select id="f-assigned_to" name="assigned_to"><option value="">غير معين</option>${selectOptions(state.team.filter((member) => member.is_active), customer.assigned_to)}</select></div>${field('registered_at', 'تاريخ التسجيل', 'date')}${field('last_amount', 'آخر مبلغ', 'number')}</div><div data-error></div><div class="form-actions"><button class="btn primary" type="submit">حفظ العميل</button><button class="btn" type="button" data-cancel>إلغاء</button></div>`;
}

async function customerDialog(customer = null) {
  if (!state.team.length) state.team = (await request('/api/team')).data;
  const dialog = openDialog(customer ? 'تعديل بيانات العميل' : 'إضافة عميل', `<form id="customer-form">${customerFields(customer || {})}</form>`);
  dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
  dialog.querySelector('#customer-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    if (customer) Object.assign(fields, customer, Object.fromEntries(new FormData(event.currentTarget)));
    fields.assigned_to = fields.assigned_to || null;
    try {
      const response = await request(customer ? `/api/customers/${customer.id}` : '/api/customers', { method: customer ? 'PUT' : 'POST', body: JSON.stringify(fields) });
      dialog.close();
      location.hash = `#/customers/${response.customer.id}`;
      await render();
    } catch (error) { showError(error); }
  });
}

async function taskDialog() {
  const [customers, team] = await Promise.all([request('/api/customers?per_page=100'), request('/api/team')]);
  state.team = team.data;
  const routeParts = routePath().split('/');
  const customerId = routeParts[0] === 'customers' ? routeParts[1] : '';
  const dialog = openDialog('إضافة متابعة', `<form id="task-form"><div class="form-grid"><div class="field full"><label>العميل</label><select name="customer_id" required><option value="">اختر العميل</option>${selectOptions(customers.data, customerId, (person) => person.name, (person) => person.id)}</select></div><div class="field full"><label>عنوان المهمة</label><input name="title" required maxlength="200" placeholder="مثال: متابعة تجديد الاشتراك"></div><div class="field"><label>موعد المتابعة</label><input name="due_at" type="datetime-local" required></div><div class="field"><label>المسؤول</label><select name="assignee_id"><option value="">بدون مسؤول</option>${selectOptions(team.data.filter((member) => member.is_active))}</select></div><div class="field full"><label>تفاصيل</label><textarea name="details" maxlength="10000"></textarea></div></div><div data-error></div><div class="form-actions"><button class="btn primary">حفظ المهمة</button></div></form>`);
  dialog.querySelector('#task-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    fields.due_at = new Date(fields.due_at).toISOString();
    fields.assignee_id ||= null;
    try { await request('/api/tasks', { method: 'POST', body: JSON.stringify(fields) }); dialog.close(); await render(); }
    catch (error) { showError(error); }
  });
}

async function completeTask(taskId) {
  const tasks = await request('/api/tasks');
  const task = tasks.data.find((entry) => String(entry.id) === String(taskId));
  if (!task) return;
  await request(`/api/tasks/${taskId}`, { method: 'PUT', body: JSON.stringify({ ...task, status: 'done' }) });
  await render();
}

function memberDialog(member = null) {
  if (state.user.role !== 'admin') return;
  const roles = [
    ['member', 'عضو فريق'], ['manager', 'مدير'], ['admin', 'مسؤول'],
  ];
  const roleOptions = roles.map(([value, label]) => `<option value="${value}" ${member?.role === value || (!member && value === 'member') ? 'selected' : ''}>${label}</option>`).join('');
  const roleHelp = '<small class="muted">عضو الفريق يتابع العملاء المسندة إليه. المدير يدير العملاء والمهام والتقارير والاستيراد والتصدير. المسؤول يدير المستخدمين وكل العملاء.</small>';
  const dialog = openDialog(member ? 'تعديل عضو الفريق' : 'إضافة عضو للفريق', `
    <form id="member-form"><div class="form-grid">
      <div class="field"><label>الاسم</label><input name="name" value="${escapeHtml(member?.name || '')}" required maxlength="120"></div>
      <div class="field"><label>البريد الإلكتروني</label><input name="email" type="email" value="${escapeHtml(member?.email || '')}" required></div>
      <div class="field"><label>كلمة المرور ${member ? '(اختياري)' : ''}</label><input name="password" type="password" minlength="8" ${member ? '' : 'required'}><small class="muted">8 أحرف على الأقل</small></div>
      <div class="field"><label>الصلاحية</label><select name="role">${roleOptions}</select>${roleHelp}</div>
      ${member ? `<div class="field"><label>الحالة</label><select name="is_active"><option value="1" ${member.is_active ? 'selected' : ''}>نشط</option><option value="0" ${!member.is_active ? 'selected' : ''}>موقوف</option></select></div>` : ''}
    </div><div data-error></div><div class="form-actions"><button class="btn primary">حفظ</button></div></form>`);
  dialog.querySelector('#member-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    fields.is_active = fields.is_active !== '0';
    try { await request(member ? `/api/team/${member.id}` : '/api/team', { method: member ? 'PUT' : 'POST', body: JSON.stringify(fields) }); dialog.close(); await renderTeam(); }
    catch (error) { showError(error); }
  });
}

window.addEventListener('hashchange', render);
initialize();
