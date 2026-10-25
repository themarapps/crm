const app = document.querySelector('#app');
const state = { user: null, csrf: '', team: [], filters: {}, taskFilters: {}, page: 1, currentCustomer: null, pendingImportFile: null, selectedCustomers: new Set(), selectedView: '', calendarMonth: '', selectedTaskDate: '' };
let serviceWorkerRegistration = null;
let serviceWorkerFailure = null;
let installPromptEvent = null;
window.addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); installPromptEvent = event; });
const labels = {
  new: 'جديد', contacted: 'تم التواصل', appointment_scheduled: 'تم تحديد موعد', interested: 'مهتم', not_interested: 'غير مهتم',
  active: 'نشط', trial: 'تجريبي', expired: 'منتهي', canceled: 'ملغي', past_due: 'متأخر',
  open: 'مفتوحة', done: 'مكتملة', admin: 'مسؤول', manager: 'مدير', member: 'عضو فريق',
};
const customerStages = ['new', 'contacted', 'appointment_scheduled', 'interested', 'not_interested'];
const routes = [
  ['dashboard', '/', 'لوحة التحكم', 'home'], ['customers', '/customers', 'العملاء', 'customers'],
  ['tasks', '/tasks', 'المهام والمواعيد', 'tasks'], ['reports', '/reports', 'التقارير', 'reports'],
  ['import', '/import', 'استيراد CSV', 'import'], ['team', '/team', 'أعضاء الفريق', 'team'],
  ['settings', '/settings', 'إعدادات الربط', 'settings'],
];
const icons = {
  brand: '<circle cx="12" cy="12" r="3"/><circle cx="5" cy="5" r="1.5"/><circle cx="19" cy="5" r="1.5"/><circle cx="5" cy="19" r="1.5"/><circle cx="19" cy="19" r="1.5"/><path d="m10 10-4-4m8 0-4 4m0 4-4 4m8 0-4-4m0-4 4-4m-4 12 4-4"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-13 4 2 2 4-4"/>',
  notifications: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
  install: '<path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 14v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5"/>',
  personAdd: '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2m5-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm11-3v6m3-3h-6"/>',
  alert: '<path d="m10.3 3.9-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3.1l-8-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/>',
  verified: '<path d="M12 3 14 5.5l3.2-.2.8 3.1 2.7 1.7-1.1 3 1.1 3-2.7 1.7-.8 3.1-3.2-.2L12 23l-2-2.5-3.2.2-.8-3.1-2.7-1.7 1.1-3-1.1-3 2.7-1.7.8-3.1 3.2.2L12 3Z"/><path d="m9 13 2 2 4-4"/>',
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

function canEditCustomers() {
  return Number(state.user?.can_edit_customers ?? 1) === 1;
}

function canViewAllCustomers() {
  return Number(state.user?.can_view_all_customers ?? (state.user?.role === 'admin' || state.user?.role === 'manager' ? 1 : 0)) === 1;
}

function savedViews() {
  try { return JSON.parse(localStorage.getItem(`crm-customer-views-${state.user?.id}`) || '[]'); }
  catch { return []; }
}

function storeSavedViews(views) {
  localStorage.setItem(`crm-customer-views-${state.user?.id}`, JSON.stringify(views));
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
  void registerServiceWorker();
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

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    serviceWorkerFailure = new Error('هذا المتصفح لا يدعم تشغيل التطبيق المثبت.');
    return;
  }
  try {
    serviceWorkerRegistration = await navigator.serviceWorker.register('/service-worker.js');
    await updateNotificationButton();
  } catch (error) {
    serviceWorkerFailure = error;
  }
}

async function updateNotificationButton() {
  const button = document.querySelector('[data-action="enable-notifications"]');
  if (!button || !serviceWorkerRegistration || !('PushManager' in window) || !('Notification' in window)) return;
  const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
  const enabled = Boolean(subscription && 'Notification' in window && Notification.permission === 'granted');
  button.title = enabled ? 'الإشعارات مفعّلة' : 'تفعيل إشعارات المتابعة';
  button.setAttribute('aria-label', button.title);
  button.classList.toggle('active', enabled);
}

async function enableBrowserNotifications() {
  if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) {
    window.alert('هذا المتصفح لا يدعم إشعارات الويب.');
    return;
  }
  const isAppleMobile = /iPhone|iPad|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isInstalled = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  if (isAppleMobile && !isInstalled) {
    window.alert('على iPhone وiPad، أضف التطبيق إلى الشاشة الرئيسية أولًا، ثم افتحه من الأيقونة وفعّل الإشعارات.');
    return;
  }
  if (Notification.permission === 'denied') {
    window.alert('إشعارات الموقع محظورة من المتصفح. اسمح بها من إعدادات الموقع ثم أعد المحاولة.');
    return;
  }
  const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
  if (permission !== 'granted') return;
  try {
    const config = await request('/api/notifications/config');
    if (!config.enabled || !config.public_key) throw new Error('إشعارات المتصفح غير مهيأة على الخادم بعد.');
    if (!serviceWorkerRegistration) await registerServiceWorker();
    if (!serviceWorkerRegistration) throw new Error(serviceWorkerFailure?.message || 'تعذر تشغيل خدمة إشعارات المتصفح.');
    const current = await serviceWorkerRegistration.pushManager.getSubscription();
    const subscription = current || await serviceWorkerRegistration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: decodeVapidKey(config.public_key) });
    await request('/api/notifications/subscription', { method: 'POST', body: JSON.stringify(subscription.toJSON()) });
    await updateNotificationButton();
    window.alert('تم تفعيل إشعارات المتابعة على هذا المتصفح.');
  } catch (error) {
    showError(error);
  }
}

function decodeVapidKey(encodedKey) {
  const padding = '='.repeat((4 - encodedKey.length % 4) % 4);
  const base64 = (encodedKey + padding).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
}

async function installPwa() {
  if (installPromptEvent) {
    await installPromptEvent.prompt();
    await installPromptEvent.userChoice;
    installPromptEvent = null;
    return;
  }
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
    window.alert('التطبيق مثبت بالفعل على هذا الجهاز.');
    return;
  }
  window.alert('لتثبيت التطبيق، افتح قائمة المتصفح واختر «إضافة إلى الشاشة الرئيسية» أو «تثبيت التطبيق». على iPhone وiPad افتحه في Safari ثم اختر «إضافة إلى الشاشة الرئيسية».');
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
    state.selectedCustomers.clear();
    location.hash = '#/dashboard';
    render();
  } catch (error) { showError(error); submitButton.disabled = false; }
}

function shell(content, activeKey, title) {
  const visibleRoutes = routes.filter(([key]) => !((['team', 'settings'].includes(key) && state.user.role !== 'admin') || (['import', 'reports'].includes(key) && state.user.role === 'member')));
  const navigation = visibleRoutes.map(([key, path, label, iconName]) => `<a class="${key === activeKey ? 'active' : ''}" href="#${path}" aria-label="${label}" title="${label}">${icon(iconName)}<span class="nav-label">${label}</span></a>`).join('');
  const mobile = visibleRoutes.map(([key, path, label, iconName]) => `<a class="${key === activeKey ? 'active' : ''}" href="#${path}">${icon(iconName)}${label}</a>`).join('');
  const memberInitial = escapeHtml((state.user.name || 'م').slice(0, 1));
  return `<div class="shell"><aside class="sidebar"><div class="sidebar-top"><a class="brand" href="#/" aria-label="Smaguide CRM - لوحة التحكم"><span class="brand-mark">${icon('brand')}</span><span class="brand-copy"><strong>Smaguide CRM</strong><small>إدارة اشتراكات ومتابعات العملاء</small></span></a><span class="brand-badge"><i></i>نسخة الفريق الداخلي</span><nav class="nav">${navigation}</nav></div><div class="side-bottom"><div class="user-mini"><span class="avatar">${memberInitial}</span><div class="user-info"><strong>${escapeHtml(state.user.name)}</strong><small>${labels[state.user.role] || 'عضو فريق'}</small></div><button class="icon-button logout-action" data-action="logout" type="button" aria-label="تسجيل الخروج" title="تسجيل الخروج">${icon('logout')}</button></div></div></aside>
  <section class="main"><header class="topbar"><form class="global-search" id="global-search" role="search"><label class="sr-only" for="global-customer-search">بحث سريع عن العملاء</label>${icon('search')}<input id="global-customer-search" name="q" type="search" placeholder="بحث سريع عن العملاء بالاسم، البريد أو رقم الجوال..."></form><div class="top-actions"><button class="icon-button" data-action="enable-notifications" type="button" aria-label="تفعيل الإشعارات" title="تفعيل الإشعارات">${icon('notifications')}</button><button class="icon-button" data-action="install-pwa" type="button" aria-label="تثبيت التطبيق" title="تثبيت التطبيق">${icon('install')}</button><a class="icon-button calendar-shortcut" href="#/tasks" aria-label="المهام والمواعيد" title="المهام والمواعيد">${icon('calendar')}</a>${canEditCustomers() ? `<button class="btn primary desktop-action" data-action="new-customer" type="button" aria-label="إضافة عميل جديد">${icon('personAdd')}<span class="quick-create-label">إضافة عميل جديد</span></button>` : ''}<span class="avatar top-avatar" aria-label="${escapeHtml(state.user.name)}" title="${escapeHtml(state.user.name)}">${memberInitial}</span></div></header><main class="content" aria-label="${title}">${content}</main></section><nav class="mobile-nav">${mobile}</nav></div>`;
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
  if (['active', 'done', 'appointment_scheduled', 'interested'].includes(status)) type = 'active';
  if (['trial', 'open', 'contacted'].includes(status)) type = 'warning';
  if (['expired', 'canceled', 'past_due', 'not_interested'].includes(status)) type = 'danger';
  return `<span class="badge ${type}">${escapeHtml(labels[status] || safe)}</span>`;
}

function dateLabel(dateText) {
  if (!dateText) return '—';
  const date = new Date(dateText.includes('T') ? dateText : `${dateText.replace(' ', 'T')}Z`);
  return Number.isNaN(date.valueOf()) ? escapeHtml(dateText) : new Intl.DateTimeFormat('ar-SA', { calendar: 'gregory', dateStyle: 'medium' }).format(date);
}

function dateTimeLabel(dateText) {
  if (!dateText) return 'وقت التسجيل غير متوفر';
  const hasTime = /\d{1,2}:\d{2}/.test(dateText);
  const formatted = escapeHtml(dateTimeDisplay(dateText));
  return hasTime ? `تاريخ ووقت التسجيل: ${formatted}` : `تاريخ التسجيل: ${formatted} · الوقت غير متوفر`;
}

function dateTimeDisplay(dateText) {
  if (!dateText) return '—';
  const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(dateText);
  const normalized = dateText.includes('T') ? dateText : dateText.replace(' ', 'T');
  const date = new Date(hasTimezone ? normalized : `${normalized}Z`);
  if (Number.isNaN(date.valueOf())) return dateText;
  const options = { calendar: 'gregory', dateStyle: 'medium', timeZone: hasTimezone ? 'Asia/Riyadh' : 'UTC' };
  if (/\d{1,2}:\d{2}/.test(dateText)) options.timeStyle = 'short';
  return new Intl.DateTimeFormat('ar-SA', options).format(date);
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
  const [dashboard, customers] = await Promise.all([request('/api/dashboard'), request('/api/customers?per_page=6&sort=registered_at&direction=desc')]);
  const counts = dashboard.counts;
  const activeRate = counts.customers ? Math.round((counts.active_subscriptions / counts.customers) * 100) : 0;
  const overdueRows = dashboard.overdue_tasks.slice(0, 4).map((task) => dashboardTaskRow(task, 'overdue')).join('');
  const upcomingRows = dashboard.upcoming_tasks.slice(0, 6).map((task) => dashboardTaskRow(task)).join('');
  const inactiveRows = dashboard.inactive_customers.slice(0, 4).map((customer) => `<a class="dashboard-inactive-row" href="#/customers/${customer.id}"><span class="avatar">${escapeHtml((customer.name || 'ع').slice(0, 1))}</span><span class="dashboard-inactive-copy"><strong>${escapeHtml(customer.name)}</strong><small>آخر تواصل مسجل ${dateLabel(customer.last_contact_at)}</small></span><span class="badge">${escapeHtml(labels[customer.crm_stage] || customer.crm_stage)}</span></a>`).join('');
  const customerRows = customers.data.map(dashboardCustomerRow).join('');
  const dashboardActions = `<div class="dashboard-head-actions">${state.user.role !== 'member' ? '<a class="btn secondary-action" href="#/import">' + icon('import') + '<span>استيراد CSV</span></a>' : ''}${canEditCustomers() ? `<button class="btn primary" data-action="new-customer" type="button">${icon('personAdd')}<span>إضافة عميل جديد</span></button>` : ''}</div>`;
  app.innerHTML = shell(`${pageHead('<span>لوحة التحكم الرئيسية</span><span class="live-badge"><i></i>مُحدَّث الآن</span>', 'متابعة أداء العملاء والاشتراكات ومهام الفريق اليومية.', dashboardActions)}
    <section class="stats dashboard-stats">${dashboardStat({ label: 'إجمالي العملاء المسجلين', value: counts.customers, iconName: 'customers', tone: 'plum', note: 'قاعدة عملائك' })}${dashboardStat({ label: 'الاشتراكات النشطة', value: counts.active_subscriptions, iconName: 'verified', tone: 'green', note: `${activeRate}% من العملاء`, progress: activeRate })}${dashboardStat({ label: 'عملاء في مرحلة جديد', value: counts.new_customers, iconName: 'personAdd', tone: 'blue', note: 'بحاجة إلى بدء التواصل' })}${dashboardStat({ label: 'المتابعات المتأخرة', value: counts.overdue_tasks, iconName: 'alert', tone: counts.overdue_tasks ? 'red' : 'green', note: counts.overdue_tasks ? 'تحتاج إلى إجراء' : 'لا توجد متابعات متأخرة' })}</section>
    <div class="dashboard-grid"><section class="panel dashboard-tasks"><div class="dashboard-panel-head"><span class="section-icon plum">${icon('calendar')}</span><div class="dashboard-panel-title"><h2>المتابعات القادمة</h2><small>${dashboard.upcoming_tasks.length ? `أقرب ${dashboard.upcoming_tasks.length} مهام مجدولة` : 'تابع مواعيد الفريق اليومية'}</small></div><a class="btn small" href="#/tasks">عرض التقويم</a></div><div class="dashboard-task-list">${upcomingRows || '<div class="dashboard-empty"><span class="section-icon soft">' + icon('calendar') + '</span><strong>لا توجد متابعات قادمة</strong><span>أضف موعدًا لمتابعة عميلك في الوقت المناسب.</span><a class="btn secondary-action" href="#/tasks">فتح المهام والمواعيد</a></div>'}</div><a class="dashboard-panel-link" href="#/tasks">عرض كل مهام ومواعيد الفريق <span aria-hidden="true">←</span></a></section>
      <div class="dashboard-side"><section class="panel dashboard-alerts"><div class="dashboard-panel-head"><span class="section-icon ${counts.overdue_tasks ? 'red' : 'green'}">${icon(counts.overdue_tasks ? 'alert' : 'verified')}</span><div class="dashboard-panel-title"><h2>المتابعات المتأخرة</h2><small>${counts.overdue_tasks ? `${counts.overdue_tasks} متابعة تحتاج إلى إجراء` : 'حالة المتابعات منتظمة'}</small></div><span class="badge ${counts.overdue_tasks ? 'danger' : 'active'}">${counts.overdue_tasks}</span></div><div class="dashboard-side-list">${overdueRows || '<div class="dashboard-quiet-empty">لا توجد مهام متأخرة حاليًا.</div>'}</div>${counts.overdue_tasks ? '<a class="dashboard-panel-link" href="#/tasks">معالجة المتابعات <span aria-hidden="true">←</span></a>' : ''}</section>
      <section class="panel dashboard-inactive"><div class="dashboard-panel-head"><span class="section-icon blue">${icon('customers')}</span><div class="dashboard-panel-title"><h2>عملاء بلا تواصل</h2><small>لم يُسجل تواصل منذ 14 يومًا</small></div><span class="badge">آخر الحالات</span></div><div class="dashboard-side-list">${inactiveRows || '<div class="dashboard-quiet-empty">لا يوجد عملاء متأخرون عن التواصل.</div>'}</div><a class="dashboard-panel-link" href="#/customers">عرض العملاء <span aria-hidden="true">←</span></a></section></div></div>
    <section class="panel dashboard-customer-panel"><div class="dashboard-panel-head"><span class="section-icon green">${icon('customers')}</span><div class="dashboard-panel-title"><h2>العملاء المضافون حديثًا</h2><small>أحدث العملاء المسجلين في النظام</small></div><a class="btn small" href="#/customers">عرض دليل العملاء</a></div><div class="table-wrap"><table><thead><tr><th>اسم العميل</th><th>باقة المنصة</th><th>مرحلة CRM</th><th>تاريخ التسجيل</th><th>اشتراك المنصة</th></tr></thead><tbody>${customerRows || '<tr><td colspan="5"><div class="empty">أضف أول عميل لبدء المتابعة.</div></td></tr>'}</tbody></table></div></section>`, 'dashboard', 'لوحة التحكم');
  bindShell();
}

function dashboardStat({ label, value, iconName, tone, note, progress }) {
  const footer = progress === undefined ? `<div class="metric-note ${tone === 'red' ? 'danger-text' : ''}">${escapeHtml(note)}</div>` : `<div class="metric-progress" role="img" aria-label="${escapeHtml(note)}"><span style="width:${Math.max(0, Math.min(100, Number(progress)))}%"></span></div><small class="metric-progress-label">${escapeHtml(note)}</small>`;
  return `<article class="stat dashboard-stat tone-${tone}"><div class="metric-main"><div><span class="stat-label">${escapeHtml(label)}</span><strong class="stat-number">${escapeHtml(value)}</strong></div><span class="metric-icon ${tone}">${icon(iconName)}</span></div><div class="metric-footer">${footer}</div></article>`;
}

function dashboardTaskRow(task, status = 'upcoming') {
  const overdue = status === 'overdue';
  const dueDate = taskDateTimeLabel(task.due_at);
  return `<article class="dashboard-task-row"><span class="task-marker ${overdue ? 'overdue' : ''}">${icon(overdue ? 'alert' : 'calendar')}</span><div class="dashboard-task-copy"><strong>${escapeHtml(task.title)}</strong><small>${escapeHtml(task.customer_name)} · ${escapeHtml(task.assignee_name || 'غير معين')}</small></div><span class="task-due ${overdue ? 'overdue' : ''}">${dueDate}</span><a class="btn task-open" href="#/customers/${task.customer_id}">العميل</a></article>`;
}

function dashboardCustomerRow(customer) {
  return `<tr><td data-label="اسم العميل"><a class="table-person dashboard-customer-link" href="#/customers/${customer.id}"><span class="avatar">${escapeHtml((customer.name || 'ع').slice(0, 1))}</span><span><strong>${escapeHtml(customer.name)}</strong><small>${escapeHtml(customer.email)}</small></span></a></td><td data-label="باقة المنصة">${escapeHtml(customer.plan || '—')}</td><td data-label="مرحلة CRM">${badge(customer.crm_stage)}</td><td data-label="تاريخ التسجيل">${dateTimeLabel(customer.registered_at)}</td><td data-label="اشتراك المنصة">${badge(customer.subscription_status)}</td></tr>`;
}

function taskDateTimeLabel(dateText) {
  if (!dateText) return 'موعد غير محدد';
  const date = new Date(dateText.includes('T') ? dateText : `${dateText.replace(' ', 'T')}Z`);
  if (Number.isNaN(date.valueOf())) return escapeHtml(dateText);
  return new Intl.DateTimeFormat('ar-SA', { calendar: 'gregory', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Riyadh' }).format(date);
}

async function renderReports() {
  const report = await request('/api/reports/summary');
  const reportGroup = (title, rows) => `<section class="panel report-card"><div class="panel-head"><div><h2>${title}</h2><small class="muted">${rows.length} تصنيفات</small></div></div><div class="panel-body report-list">${rows.length ? rows.map((row) => {
    const share = report.total ? Math.round((Number(row.total) / Number(report.total)) * 100) : 0;
    return `<div class="report-row"><div class="report-row-copy"><span>${escapeHtml(labels[row.label] || row.label || 'غير محدد')}</span><small>${share}% من العملاء</small><span class="report-meter" role="img" aria-label="${share}% من العملاء"><i style="width:${Math.max(0, Math.min(100, share))}%"></i></span></div><strong>${escapeHtml(row.total)}</strong></div>`;
  }).join('') : '<div class="empty">لا توجد بيانات بعد.</div>'}</div></section>`;
  const performanceRows = report.team_performance.length ? report.team_performance.map((row) => `<tr><td>${escapeHtml(row.owner_name)}</td><td>${escapeHtml(row.customers)}</td><td>${escapeHtml(row.overdue_tasks)}</td><td><span class="conversion-value">${escapeHtml(row.advanced_customers)}</span><span class="conversion-rate">${escapeHtml(row.conversion_rate)}%</span></td></tr>`).join('') : '<tr><td colspan="4"><div class="empty">لا توجد بيانات أداء بعد.</div></td></tr>';
  const reportActions = '<a class="btn secondary-action" href="/api/customers/export.csv">تصدير العملاء CSV</a>';
  app.innerHTML = shell(`${pageHead('التقارير', 'توزيع منفصل بين مراحل CRM وحالات اشتراك المنصة والباقات.', reportActions)}<div class="stats dashboard-stats report-stats">${dashboardStat({ label: 'إجمالي العملاء', value: report.total, iconName: 'customers', tone: 'plum', note: 'كل العملاء المسجلين' })}${dashboardStat({ label: 'اشتراكات المنصة النشطة', value: report.active_subscriptions, iconName: 'verified', tone: 'green', note: 'الاشتراكات بحالة نشطة' })}</div><div class="report-groups">${reportGroup('مراحل CRM', report.stages)}${reportGroup('حالات اشتراك المنصة', report.subscriptions)}${reportGroup('باقات المنصة', report.plans)}</div><section class="panel report-performance"><div class="panel-head"><div><h2>أداء الفريق</h2><small class="muted">التحويل = العملاء في المراحل المتقدمة ÷ إجمالي العملاء لكل مسؤول</small></div></div><div class="table-wrap"><table><thead><tr><th>المسؤول</th><th>العملاء</th><th>المتابعات المتأخرة</th><th>العملاء في المراحل المتقدمة · نسبة التحويل</th></tr></thead><tbody>${performanceRows}</tbody></table></div></section>`, 'reports', 'التقارير');
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
    ? '<div class="notice integration-note"><span class="section-icon green">' + icon('verified') + '</span><span><strong>بيانات الربط محمية</strong><small>تُحفظ بيانات الدخول مشفرة، ولا تظهر كلمة المرور بعد الحفظ. المزامنة تحدّث بيانات المصدر مع الإبقاء على مراحل المتابعة والملاحظات والمهام.</small></span></div>'
    : '<div class="error integration-note"><span class="section-icon red">' + icon('alert') + '</span><span><strong>الإعداد مطلوب</strong><small>يلزم إعداد مفتاح التشفير على الخادم قبل حفظ بيانات الدخول.</small></span></div>';
  const status = settings.credentials_configured ? 'بيانات محفوظة' : 'غير مكتمل';
  const userPlaceholder = settings.credentials_configured ? 'اتركه فارغًا للإبقاء على الحالي' : 'اسم المستخدم';
  const passwordPlaceholder = settings.credentials_configured ? 'اتركها فارغة للإبقاء على الحالية' : 'كلمة المرور';
  const deleteButton = settings.credentials_configured
    ? '<button class="btn danger" type="button" data-source-delete>حذف بيانات الربط</button>'
    : '';
  return `${pageHead('الربط بمصدر العملاء', 'احفظ بيانات الدخول للمصدر الذي تُجلب منه سجلات العملاء.')}${notice}
    <section class="panel integration-panel"><div class="panel-head"><div><h2>بيانات الاتصال</h2><small class="muted">عنوان المصدر وحساب المزامنة</small></div><span class="badge ${settings.credentials_configured ? 'active' : 'neutral'}">${status}</span></div><div class="panel-body">
    <form id="integration-form"><div class="form-grid"><div class="field full"><label for="source-url">الرابط الأساسي للمصدر</label><input id="source-url" name="source_url" type="url" value="${escapeHtml(settings.source_url)}" placeholder="https://example.com" required></div>
    <div class="field"><label for="source-username">اسم المستخدم</label><input id="source-username" name="username" autocomplete="username" placeholder="${userPlaceholder}"></div>
    <div class="field"><label for="source-password">كلمة المرور</label><input id="source-password" name="password" type="password" autocomplete="new-password" placeholder="${passwordPlaceholder}"></div></div>
    <div id="integration-status" aria-live="polite"></div><div id="sync-status" aria-live="polite"></div><div class="form-actions"><button class="btn primary" type="submit" ${settings.encryption_ready ? '' : 'disabled'}>حفظ بيانات الربط</button><button class="btn" type="button" data-source-sync ${settings.credentials_configured ? '' : 'disabled'}>مزامنة الآن</button>${deleteButton}</div></form></div></section>`;
}

function bindIntegrationForm() {
  document.querySelector('#integration-form').addEventListener('submit', saveIntegrationSettings);
  document.querySelector('[data-source-sync]').addEventListener('click', syncSourceCustomers);
  document.querySelector('[data-source-delete]')?.addEventListener('click', deleteIntegrationSettings);
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
    form.querySelector('[data-source-sync]').disabled = false;
    document.querySelector('#integration-status').innerHTML = '<div class="notice">تم حفظ بيانات الربط بشكل مشفر.</div>';
  } catch (error) {
    document.querySelector('#integration-status').innerHTML = `<div class="error" role="alert">${escapeHtml(error.message)}</div>`;
  } finally {
    button.disabled = false;
  }
}

async function deleteIntegrationSettings(event) {
  const button = event.currentTarget;
  const confirmed = window.confirm('سيتم حذف رابط المصدر وبيانات الدخول المحفوظة. لن تُحذف سجلات العملاء الحالية، لكن المزامنة الدورية ستتوقف حتى حفظ بيانات ربط جديدة. هل تريد المتابعة؟');
  if (!confirmed) return;
  button.disabled = true;
  try {
    await request('/api/settings/source', { method: 'DELETE', body: '{}' });
    await renderIntegrationSettings();
  } catch (error) {
    document.querySelector('#integration-status').innerHTML = `<div class="error" role="alert">${escapeHtml(error.message)}</div>`;
    button.disabled = false;
  }
}

async function syncSourceCustomers(event) {
  const button = event.currentTarget;
  const status = document.querySelector('#sync-status');
  button.disabled = true;
  button.textContent = 'جارٍ جلب العملاء…';
  status.innerHTML = '<div class="notice">جارٍ الاتصال بالمصدر وقراءة العملاء. قد تستغرق العملية بعض الوقت.</div>';
  try {
    const result = await request('/api/settings/source/sync', { method: 'POST', body: '{}' });
    const details = result.errors.length ? `<div class="muted">ملاحظات: ${result.errors.map(escapeHtml).join('، ')}</div>` : '';
    status.innerHTML = `<div class="notice">اكتملت المزامنة: ${escapeHtml(result.created)} جديد، ${escapeHtml(result.updated)} تحديث، ${escapeHtml(result.unchanged)} دون تغيير، ${escapeHtml(result.skipped)} متخطى من أصل ${escapeHtml(result.total)}.${details}</div>`;
  } catch (error) {
    status.innerHTML = `<div class="error" role="alert">${escapeHtml(error.message)}</div>`;
  } finally {
    button.disabled = false;
    button.textContent = 'مزامنة الآن';
  }
}

async function renderCustomers() {
  const list = await loadCustomerList();
  state.team = list.team.data;
  app.innerHTML = shell(customerListMarkup(list), 'customers', 'العملاء');
  bindShell();
  bindCustomerList(list);
}

async function loadCustomerList() {
  const params = new URLSearchParams({ page: String(state.page), per_page: '25', ...state.filters });
  const [response, options, team] = await Promise.all([
    request(`/api/customers?${params}`), request('/api/customers/options'), request('/api/team'),
  ]);
  return { response, options, team, params };
}

function customerListMarkup(list) {
  const views = savedViews();
  const viewOptions = views.map((view) => `<option value="${escapeHtml(view.name)}" ${state.selectedView === view.name ? 'selected' : ''}>${escapeHtml(view.name)}</option>`).join('');
  const exportLink = ['admin', 'manager'].includes(state.user.role) ? `<a class="btn" href="/api/customers/export.csv?${list.params}">تصدير CSV</a>` : '';
  const createButton = canEditCustomers() ? '<button class="btn primary" data-action="new-customer">+ إضافة عميل</button>' : '';
  return `${pageHead('العملاء', `إجمالي ${list.response.total} عميل`, `${exportLink}${createButton}`)}
    <section class="panel customer-list-panel"><div class="toolbar saved-view-bar"><div class="saved-view-intro"><strong>عروضك المحفوظة</strong><small>احفظ مجموعة فلاتر لتصل إليها بسرعة لاحقًا</small></div><div class="field saved-view-select"><label for="saved-view">العروض المحفوظة على هذا الجهاز</label><select id="saved-view"><option value="">اختر عرضًا</option>${viewOptions}</select></div><div class="saved-view-actions"><button class="btn" type="button" data-action="save-view">حفظ العرض الحالي</button><button class="btn" type="button" data-action="rename-view" ${!state.selectedView ? 'disabled' : ''}>إعادة تسمية</button><button class="btn danger-outline" type="button" data-action="delete-view" ${!state.selectedView ? 'disabled' : ''}>حذف</button></div></div>
    ${customerFiltersMarkup(list.options, list.team.data)}${bulkToolbarMarkup(list.team.data)}${customerTableMarkup(list.response, list.team.data)}</section>`;
}

function customerFiltersMarkup(options, members) {
  const planOptions = options.plans.map((plan) => `<option value="${escapeHtml(plan)}" ${state.filters.plan === plan ? 'selected' : ''}>${escapeHtml(plan)}</option>`).join('');
  const subscriptionOptions = options.subscription_statuses.map((status) => `<option value="${escapeHtml(status)}" ${state.filters.subscription_status === status ? 'selected' : ''}>${escapeHtml(labels[status] || status)}</option>`).join('');
  const paymentOptions = options.payment_statuses.map((status) => `<option value="${escapeHtml(status)}" ${state.filters.payment_status === status ? 'selected' : ''}>${escapeHtml(labels[status] || status)}</option>`).join('');
  const stageOptions = options.stages.map((stage) => `<option value="${escapeHtml(stage)}" ${state.filters.stage === stage ? 'selected' : ''}>${escapeHtml(labels[stage] || stage)}</option>`).join('');
  const memberOptions = members.map((member) => `<option value="${member.id}" ${state.filters.assigned_to === String(member.id) ? 'selected' : ''}>${escapeHtml(member.name)}</option>`).join('');
  const sourceOptions = options.lead_sources.map((source) => `<option value="${escapeHtml(source)}" ${state.filters.lead_source === source ? 'selected' : ''}>${escapeHtml(source)}</option>`).join('');
  const campaignOptions = options.campaigns.map((campaign) => `<option value="${escapeHtml(campaign)}" ${state.filters.campaign === campaign ? 'selected' : ''}>${escapeHtml(campaign)}</option>`).join('');
  const tagOptions = options.tags.map((tag) => `<option value="${tag.id}" ${state.filters.tag === String(tag.id) ? 'selected' : ''}>${escapeHtml(tag.name)}</option>`).join('');
  const activeCount = ['q', 'subscription_status', 'payment_status', 'plan', 'stage', 'assigned_to', 'lead_source', 'campaign', 'tag'].filter((key) => String(state.filters[key] || '').trim()).length;
  return `<form class="customer-filters" id="filters"><div class="filter-heading"><div><h2>تصفية العملاء</h2><p>استخدم معيارًا واحدًا أو أكثر لتضييق النتائج.</p></div><span class="filter-count">عدد الفلاتر النشطة: ${activeCount}</span></div>
    <div class="customer-filter-fields"><div class="field filter-search-field"><label for="filter-q">بحث بالاسم أو البريد أو الهاتف</label><input class="search" id="filter-q" name="q" value="${escapeHtml(state.filters.q || '')}" placeholder="اكتب اسم العميل أو بريده أو رقم هاتفه"></div>
    <div class="field"><label for="filter-subscription">اشتراك المنصة</label><select id="filter-subscription" name="subscription_status"><option value="">كل الحالات</option>${subscriptionOptions}</select></div><div class="field"><label for="filter-payment">دفع المنصة</label><select id="filter-payment" name="payment_status"><option value="">كل حالات الدفع</option>${paymentOptions}</select></div><div class="field"><label for="filter-plan">باقة المنصة</label><select id="filter-plan" name="plan"><option value="">كل الباقات</option>${planOptions}</select></div><div class="field"><label for="filter-stage">مرحلة CRM</label><select id="filter-stage" name="stage"><option value="">كل المراحل</option>${stageOptions}</select></div><div class="field"><label for="filter-assignee">مسؤول CRM</label><select id="filter-assignee" name="assigned_to"><option value="">كل الفريق</option>${memberOptions}</select></div><div class="field"><label for="filter-source">مصدر الاكتساب · CRM</label><select id="filter-source" name="lead_source"><option value="">كل المصادر</option>${sourceOptions}</select></div><div class="field"><label for="filter-campaign">حملة CRM</label><select id="filter-campaign" name="campaign"><option value="">كل الحملات</option>${campaignOptions}</select></div><div class="field"><label for="filter-tag">وسم CRM</label><select id="filter-tag" name="tag"><option value="">كل الوسوم</option>${tagOptions}</select></div></div>
    <div class="filter-footer"><div class="filter-sort-fields">${customerSortingMarkup()}</div><div class="filter-actions"><button class="btn primary" type="submit">تطبيق الفلاتر</button><button class="btn" type="button" data-action="clear-filters">مسح الفلاتر</button></div></div></form>`;
}

function customerSortingMarkup() {
  const fields = { name: 'الاسم', plan: 'باقة المنصة', subscription_status: 'اشتراك المنصة', crm_stage: 'مرحلة CRM', assigned_to: 'مسؤول CRM', registered_at: 'تاريخ التسجيل', updated_at: 'آخر تحديث' };
  const options = Object.entries(fields).map(([key, label]) => `<option value="${key}" ${(state.filters.sort || 'updated_at') === key ? 'selected' : ''}>${label}</option>`).join('');
  return `<div class="field"><label for="filter-sort">الترتيب حسب</label><select id="filter-sort" name="sort">${options}</select></div><div class="field"><label for="filter-direction">الاتجاه</label><select id="filter-direction" name="direction"><option value="desc" ${(state.filters.direction || 'desc') === 'desc' ? 'selected' : ''}>تنازلي</option><option value="asc" ${state.filters.direction === 'asc' ? 'selected' : ''}>تصاعدي</option></select></div>`;
}

function bulkToolbarMarkup(members) {
  if (!canEditCustomers()) return '';
  const memberOptions = members.filter((member) => member.is_active).map((member) => `<option value="${member.id}">${escapeHtml(member.name)}</option>`).join('');
  const assignmentField = canViewAllCustomers() ? `<div class="field"><label for="bulk-assignee">المسؤول الجديد</label><select id="bulk-assignee"><option value="">بدون تغيير</option><option value="none">غير معين</option>${memberOptions}</select></div>` : '';
  const stageOptions = customerStages.map((stage) => `<option value="${stage}">${labels[stage]}</option>`).join('');
  return `<div class="toolbar bulk-toolbar"><label><input id="select-visible-customers" type="checkbox"> تحديد الصفحة</label><span>${state.selectedCustomers.size} محدد</span><div class="field"><label for="bulk-stage">المرحلة الجديدة</label><select id="bulk-stage"><option value="">بدون تغيير</option>${stageOptions}</select></div>${assignmentField}<button class="btn primary" type="button" data-action="bulk-update" ${state.selectedCustomers.size ? '' : 'disabled'}>تطبيق على المحدد</button></div>`;
}

function customerTableMarkup(response, members) {
  const headers = { name: 'العميل', phone: 'الهاتف', plan: 'باقة المنصة', subscription_status: 'اشتراك المنصة', crm_stage: 'مرحلة CRM', assigned_to: 'مسؤول CRM', registered_at: 'تاريخ التسجيل' };
  const sortableHeaders = Object.entries(headers).map(([key, label]) => `<th><button class="table-sort" type="button" data-sort="${key}">${label}${state.filters.sort === key ? (state.filters.direction === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>`).join('');
  const rows = response.data.length ? response.data.map((customer) => customerRowMarkup(customer, members)).join('') : '<tr><td colspan="8"><div class="empty">لا توجد نتائج مطابقة. جرّب تغيير الفلاتر.</div></td></tr>';
  return `<div class="table-wrap"><table><thead><tr><th>تحديد</th>${sortableHeaders}</tr></thead><tbody>${rows}</tbody></table></div><div class="pagination"><span>صفحة ${response.page} · ${response.total} عميل</span><span><button class="btn small" data-action="previous" ${state.page <= 1 ? 'disabled' : ''}>السابق</button> <button class="btn small" data-action="next" ${state.page * response.per_page >= response.total ? 'disabled' : ''}>التالي</button></span></div>`;
}

function customerRowMarkup(customer, members) {
  const owner = members.find((member) => Number(member.id) === Number(customer.assigned_to))?.name || 'غير معين';
  const checked = state.selectedCustomers.has(Number(customer.id)) ? 'checked' : '';
  return `<tr><td data-label="تحديد"><input type="checkbox" class="customer-select" value="${customer.id}" ${checked} aria-label="تحديد ${escapeHtml(customer.name)}"></td><td data-label="العميل"><a class="table-person" href="#/customers/${customer.id}" style="color:inherit;text-decoration:none"><span class="avatar">${escapeHtml((customer.name || 'ع').slice(0, 1))}</span><span><strong>${escapeHtml(customer.name)}</strong><small>${escapeHtml(customer.email)}</small></span></a></td><td data-label="الهاتف">${escapeHtml(customer.phone || '—')}</td><td data-label="باقة المنصة">${escapeHtml(customer.plan || '—')}</td><td data-label="اشتراك المنصة">${badge(customer.subscription_status)}</td><td data-label="مرحلة CRM">${badge(customer.crm_stage)}</td><td data-label="مسؤول CRM">${escapeHtml(owner)}</td><td data-label="التسجيل">${dateLabel(customer.registered_at)}</td></tr>`;
}

function bindCustomerList(list) {
  const views = savedViews();
  document.querySelector('#filters').addEventListener('submit', (event) => {
    event.preventDefault(); state.filters = Object.fromEntries(new FormData(event.currentTarget));
    state.selectedView = ''; state.selectedCustomers.clear(); state.page = 1; render();
  });
  document.querySelector('#saved-view').addEventListener('change', (event) => applySavedView(event.currentTarget.value, views));
  document.querySelectorAll('[data-sort]').forEach((button) => button.addEventListener('click', () => sortCustomers(button.dataset.sort)));
  document.querySelectorAll('.customer-select').forEach((input) => input.addEventListener('change', () => toggleCustomerSelection(input)));
  document.querySelector('#select-visible-customers')?.addEventListener('change', (event) => togglePageSelection(list.response.data, event.currentTarget.checked));
}

function applySavedView(name, views) {
  state.selectedView = name;
  const view = views.find((savedView) => savedView.name === name);
  if (!view) return;
  state.filters = { ...view.filters }; state.selectedCustomers.clear(); state.page = 1; render();
}

function sortCustomers(sort) {
  state.filters.direction = state.filters.sort === sort && state.filters.direction !== 'asc' ? 'asc' : 'desc';
  state.filters.sort = sort; state.page = 1; render();
}

function toggleCustomerSelection(input) {
  const customerId = Number(input.value);
  if (input.checked) state.selectedCustomers.add(customerId); else state.selectedCustomers.delete(customerId);
  render();
}

function togglePageSelection(customers, selected) {
  customers.forEach((customer) => selected ? state.selectedCustomers.add(Number(customer.id)) : state.selectedCustomers.delete(Number(customer.id)));
  render();
}

async function renderCustomer(customerId) {
  const response = await request(`/api/customers/${customerId}`);
  state.team = (await request('/api/team')).data;
  const customer = response.customer;
  customer.tags = response.tags || [];
  state.currentCustomer = customer;
  const notes = response.notes.length ? response.notes.map((note) => `<div class="task-row"><div><strong>${escapeHtml(note.author)}</strong><p>${escapeHtml(note.body)}</p><small class="muted">${dateLabel(note.created_at)}</small></div></div>`).join('') : '<div class="empty">لا توجد ملاحظات بعد.</div>';
  const tasks = response.tasks.length ? response.tasks.map(taskCard).join('') : '<div class="empty">لا توجد مهام للعميل.</div>';
  const activity = response.activity.length ? response.activity.map((entry) => `<div class="task-row"><div><strong>${escapeHtml(entry.summary)}</strong><small>${escapeHtml(entry.actor_name || 'النظام')} · ${dateLabel(entry.created_at)}</small></div></div>`).join('') : '<div class="empty">سيظهر هنا سجل تحديثات العميل ومتابعاته.</div>';
  app.innerHTML = shell(`${pageHead('ملف العميل', `تمت الإضافة ${dateLabel(customer.registered_at)}`, canEditCustomers() ? '<button class="btn" data-action="edit-customer">تعديل البيانات</button>' : '')}
    ${customerProfileMarkup(customer, response)}
    <div class="content-grid customer-content-grid"><div><section class="panel customer-notes-panel"><div class="panel-head"><div><h2>الملاحظات</h2><small class="muted">سجل التواصل مع العميل</small></div></div><div class="panel-body">${canEditCustomers() ? `<form id="note-form"><div class="field"><label for="note-body">ملاحظة جديدة</label><textarea id="note-body" name="body" required maxlength="10000" placeholder="اكتب ملخص المكالمة أو أي معلومة مهمة"></textarea></div><button class="btn primary note-save-button" type="submit">حفظ الملاحظة</button><div data-error></div></form>` : ''}${notes}</div></section></div><div><section class="panel customer-tasks-panel"><div class="panel-head"><div><h2>المهام</h2><small class="muted">المتابعات الخاصة بالعميل</small></div>${canEditCustomers() ? '<button class="btn small" data-action="new-task">+ مهمة</button>' : ''}</div><div class="panel-body">${tasks}</div></section></div></div><section class="panel activity-panel customer-activity-panel"><div class="panel-head"><div><h2>سجل نشاط العميل</h2><small class="muted">تحديثات ومتابعات مرتبطة بهذا الحساب</small></div></div><div class="panel-body">${activity}</div></section>`, 'customers', 'ملف العميل');
  bindShell();
  bindCustomerStage(customerId, customer.crm_stage);
  document.querySelector('#note-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try { await request(`/api/customers/${customerId}/notes`, { method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) }); await renderCustomer(customerId); }
    catch (error) { showError(error); }
  });
}

function customerProfileMarkup(customer, response) {
  const owner = state.team.find((member) => Number(member.id) === Number(customer.assigned_to))?.name || 'غير معين';
  const sourceData = [
    detailField('معرّف المنصة', customer.external_id),
    detailField('نوع الحساب', customer.account_type),
    detailField('الباقة', customer.plan),
    `<div class="detail-field"><small>حالة الاشتراك · المنصة</small>${badge(customer.subscription_status)}</div>`,
    detailField('حالة الدفع · المنصة', customer.payment_status),
    detailField('دورة الفوترة', customer.billing_cycle),
    detailField('مرحلة الحساب · المنصة', customer.source_stage),
    detailField('البريد مؤكد · المنصة', customer.email_verified),
    detailField('الشركة', customer.company_url),
    detailField('الخدمات', customer.services),
    detailField('عدد الخدمات', customer.service_count),
    detailField('آخر مبلغ', [customer.last_amount, customer.currency].filter(Boolean).join(' ')),
    detailField('تاريخ التسجيل', dateTimeLabel(customer.registered_at)),
    detailField('آخر نشاط في المنصة', dateTimeDisplay(customer.last_activity_at)),
    detailField('عدد أخطاء الحساب', customer.error_count),
  ].join('');
  const tags = (response.tags || []).length
    ? response.tags.map((tag) => `<span class="tag-chip">${escapeHtml(tag.name)}</span>`).join('')
    : '<span class="muted">—</span>';
  const crmData = `${customerStageField(customer)}${detailField('المسؤول داخل CRM', owner)}${detailField('آخر تواصل داخل CRM', dateTimeDisplay(customer.crm_last_activity_at))}${detailField('مصدر الاكتساب · CRM', customer.lead_source)}${detailField('الحملة · CRM', customer.campaign)}<div class="detail-field"><small>وسوم CRM</small><div class="tag-list">${tags}</div></div>`;
  return `<section class="panel customer-profile-panel"><div class="detail-hero"><div class="person"><span class="avatar">${escapeHtml((customer.name || 'ع').slice(0, 1))}</span><div><small class="customer-origin-label">بيانات الحساب الأساسية · المنصة</small><h2>${escapeHtml(customer.name)}</h2><p>${escapeHtml(customer.email)}${customer.phone ? ` · ${escapeHtml(customer.phone)}` : ''}</p></div></div></div><div class="customer-data-sections"><section class="customer-data-section platform-data"><div class="customer-data-heading"><div><h3>بيانات المنصة</h3><p>بيانات الاشتراك والحساب الواردة من المصدر</p></div><span>تتحدث مع المزامنة</span></div><div class="detail-grid">${sourceData}</div></section><section class="customer-data-section crm-data"><div class="customer-data-heading"><div><h3>بياناتنا في CRM</h3><p>معلومات المتابعة التي يديرها فريقنا</p></div><span>خاصة بالفريق</span></div><div class="detail-grid">${crmData}</div></section></div></section>`;
}

function detailField(title, fieldContent) {
  return `<div class="detail-field"><small>${title}</small><strong>${escapeHtml(fieldContent || '—')}</strong></div>`;
}

function customerStageField(customer) {
  if (!canEditCustomers()) return detailField('حالة العميل', labels[customer.crm_stage] || customer.crm_stage);
  const options = selectOptions(customerStages.map((id) => ({ id, name: labels[id] })), customer.crm_stage || 'new');
  return `<div class="detail-field"><small>حالة العميل</small><div class="detail-stage-control"><select id="customer-stage" aria-label="حالة العميل">${options}</select><button class="btn small" id="save-customer-stage" type="button" disabled>حفظ</button></div><span class="muted customer-stage-feedback" id="customer-stage-feedback" aria-live="polite"></span></div>`;
}

function bindCustomerStage(customerId, currentStage) {
  const select = document.querySelector('#customer-stage');
  const save = document.querySelector('#save-customer-stage');
  const feedback = document.querySelector('#customer-stage-feedback');
  select?.addEventListener('change', () => {
    save.disabled = select.value === currentStage;
    feedback.textContent = '';
    feedback.className = 'muted customer-stage-feedback';
  });
  save?.addEventListener('click', () => saveCustomerStage(customerId, currentStage));
}

async function saveCustomerStage(customerId, currentStage) {
  const select = document.querySelector('#customer-stage');
  const save = document.querySelector('#save-customer-stage');
  const feedback = document.querySelector('#customer-stage-feedback');
  save.disabled = true;
  select.disabled = true;
  try {
    await request('/api/customers/bulk', { method: 'POST', body: JSON.stringify({ ids: [Number(customerId)], crm_stage: select.value }) });
  } catch (error) {
    select.disabled = false;
    save.disabled = select.value === currentStage;
    feedback.textContent = error.message;
    feedback.className = 'error customer-stage-feedback';
    return;
  }
  await renderCustomer(customerId);
  document.querySelector('#customer-stage-feedback').textContent = 'تم تحديث حالة العميل.';
}

function taskCard(task) {
  const customerLabel = task.customer_name ? `${escapeHtml(task.customer_name)} · ` : '';
  return `<div class="task-row"><div><strong>${escapeHtml(task.title)}</strong><small>${customerLabel}${dateLabel(task.due_at)} · ${escapeHtml(task.assignee_name || 'بدون مسؤول')}</small></div>${task.status === 'open' && canEditCustomers() ? `<button class="btn small" data-action="complete-task" data-id="${task.id}">إنهاء</button>` : task.status === 'done' ? badge('done') : ''}</div>`;
}

async function renderTasks() {
  ensureCalendarDate();
  const range = calendarRange(state.calendarMonth);
  const lists = await loadTaskLists(range);
  state.team = lists.team.data;
  const groups = taskSections(lists.tasks.data);
  app.innerHTML = shell(taskPageMarkup(groups, taskCalendarMarkup(lists.calendar.data)), 'tasks', 'المهام');
  bindShell();
  bindTaskFilters();
}

async function loadTaskLists(range) {
  const listParams = new URLSearchParams(state.taskFilters);
  const calendarParams = new URLSearchParams(state.taskFilters);
  calendarParams.set('from', range.from);
  calendarParams.set('to', range.to);
  const [tasks, calendar, team] = await Promise.all([
    request(`/api/tasks?${listParams}`), request(`/api/tasks?${calendarParams}`), request('/api/team'),
  ]);
  return { tasks, calendar, team };
}

function taskSections(tasks) {
  const groups = { overdue: [], today: [], upcoming: [], done: [] };
  const today = riyadhTodayKey();
  tasks.forEach((task) => {
    if (task.status === 'done') return groups.done.push(task);
    const due = taskDateKey(task.due_at);
    const key = due < today ? 'overdue' : due === today ? 'today' : 'upcoming';
    groups[key].push(task);
  });
  return groups;
}

function riyadhTodayKey() {
  return riyadhDateKey(new Date());
}

function riyadhDateKey(date) {
  const parts = new Intl.DateTimeFormat('en', { timeZone: 'Asia/Riyadh', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  const field = (name) => parts.find((part) => part.type === name).value;
  return `${field('year')}-${field('month')}-${field('day')}`;
}

function taskPageMarkup(groups, calendarMarkup) {
  const createButton = canEditCustomers() ? '<button class="btn primary" data-action="new-task">+ إضافة مهمة</button>' : '';
  return `${pageHead('المهام والمتابعات', 'تابع المواعيد المتأخرة والقادمة حسب اليوم.', createButton)}${taskFiltersMarkup()}${calendarMarkup}${taskSectionsMarkup(groups)}`;
}

function taskFiltersMarkup() {
  const activeCount = Number(Boolean(state.taskFilters.status)) + Number(Boolean(state.taskFilters.due));
  return `<section class="panel task-filter-panel"><form class="toolbar task-filter-form" id="task-filters"><div class="task-filter-heading"><div><h2>تصفية المهام</h2><p>حدد الحالة والموعد لعرض المهام المطلوبة.</p></div><span class="filter-count">عدد الشروط النشطة: ${activeCount}</span></div><div class="field"><label for="task-status">حالة المهمة</label><select id="task-status" name="status"><option value="" ${!state.taskFilters.status ? 'selected' : ''}>كل الحالات</option><option value="open" ${state.taskFilters.status === 'open' ? 'selected' : ''}>مفتوحة</option><option value="done" ${state.taskFilters.status === 'done' ? 'selected' : ''}>مكتملة</option></select></div><div class="field"><label for="task-due">موعد المهمة</label><select id="task-due" name="due"><option value="" ${!state.taskFilters.due ? 'selected' : ''}>كل المواعيد</option><option value="today" ${state.taskFilters.due === 'today' ? 'selected' : ''}>اليوم</option><option value="overdue" ${state.taskFilters.due === 'overdue' ? 'selected' : ''}>متأخرة</option><option value="upcoming" ${state.taskFilters.due === 'upcoming' ? 'selected' : ''}>قادمة</option></select></div><div class="task-filter-actions"><button class="btn primary" type="submit">تطبيق</button><button class="btn" type="button" data-action="clear-task-filters">مسح</button></div></form></section>`;
}

function taskSectionsMarkup(groups) {
  return `<div class="task-sections">${taskSectionMarkup(groups.overdue, 'متأخرة', 'overdue')}${taskSectionMarkup(groups.today, 'اليوم', 'today')}${taskSectionMarkup(groups.upcoming, 'قادمة', 'upcoming')}${taskSectionMarkup(groups.done, 'مكتملة', 'done')}</div>`;
}

function taskSectionMarkup(tasks, title, status) {
  const taskRows = tasks.map(taskCard).join('') || '<div class="empty">لا توجد مهام هنا.</div>';
  return `<section class="panel task-status-panel task-status-${status}"><div class="panel-head"><h2>${title}</h2><span class="badge">${tasks.length}</span></div><div class="panel-body">${taskRows}</div></section>`;
}

function bindTaskFilters() {
  document.querySelector('#task-filters').addEventListener('submit', (event) => {
    event.preventDefault();
    state.taskFilters = Object.fromEntries(new FormData(event.currentTarget));
    if (state.taskFilters.status === 'done') state.taskFilters.due = '';
    render();
  });
}

function ensureCalendarDate() {
  if (state.calendarMonth) return;
  const [year, month, day] = riyadhDateKey(new Date()).split('-');
  state.calendarMonth = `${year}-${month}`;
  state.selectedTaskDate = `${year}-${month}-${day}`;
}

function calendarRange(month) {
  const [year, monthNumber] = month.split('-').map(Number);
  const firstDay = new Date(Date.UTC(year, monthNumber - 1, 1));
  const gridStart = new Date(Date.UTC(year, monthNumber - 1, 1 - firstDay.getUTCDay()));
  const gridEnd = new Date(gridStart.getTime() + 41 * 86400000);
  return { from: gridStart.toISOString().slice(0, 10), to: gridEnd.toISOString().slice(0, 10) };
}

function taskDateKey(dueAt) {
  return riyadhDateKey(new Date(dueAt.replace(' ', 'T') + 'Z'));
}

function taskCalendarMarkup(tasks) {
  const [year, month] = state.calendarMonth.split('-').map(Number);
  const firstDay = new Date(Date.UTC(year, month - 1, 1));
  const gridStart = new Date(Date.UTC(year, month - 1, 1 - firstDay.getUTCDay()));
  const grouped = tasks.reduce((byDay, task) => {
    const day = taskDateKey(task.due_at);
    if (!byDay[day]) byDay[day] = [];
    byDay[day].push(task);
    return byDay;
  }, {});
  const today = riyadhDateKey(new Date());
  const spokenDateFormatter = new Intl.DateTimeFormat('ar-SA', { calendar: 'gregory', weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
  const calendarContext = { month, grouped, today, spokenDateFormatter };
  const days = Array.from({ length: 42 }, (_, index) => calendarDayMarkup(gridStart, index, calendarContext));
  const dailyTasks = (grouped[state.selectedTaskDate] || []).map(taskCard).join('') || '<div class="empty">لا توجد مهام مجدولة لهذا اليوم.</div>';
  const monthName = new Intl.DateTimeFormat('ar-SA', { calendar: 'gregory', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, 1)));
  const selectedDate = new Date(`${state.selectedTaskDate}T00:00:00Z`);
  const selectedLabel = new Intl.DateTimeFormat('ar-SA', { calendar: 'gregory', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(selectedDate);
  return `<section class="panel task-calendar"><div class="panel-head"><h2>تقويم المهام</h2><div class="calendar-nav"><button class="btn small" data-action="calendar-previous" aria-label="الشهر السابق">‹</button><strong>${monthName}</strong><button class="btn small" data-action="calendar-next" aria-label="الشهر التالي">›</button></div></div><div class="panel-body"><div class="calendar-grid calendar-weekdays">${['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'].map((day) => `<span>${day}</span>`).join('')}</div><div class="calendar-grid">${days.join('')}</div><div class="calendar-day-tasks"><h3>مهام يوم ${escapeHtml(selectedLabel)}</h3>${dailyTasks}</div></div></section>`;
}

function calendarDayMarkup(gridStart, index, calendarContext) {
  const date = new Date(gridStart.getTime() + index * 86400000);
  const dateKey = date.toISOString().slice(0, 10);
  const taskCount = (calendarContext.grouped[dateKey] || []).length;
  const isToday = dateKey === calendarContext.today;
  const classes = ['calendar-day', dateKey.slice(5, 7) === String(calendarContext.month).padStart(2, '0') ? '' : 'outside-month', dateKey === state.selectedTaskDate ? 'selected' : '', isToday ? 'today' : ''].filter(Boolean).join(' ');
  const spokenDate = calendarContext.spokenDateFormatter.format(date);
  const taskLabel = `${taskCount} ${taskCount === 1 ? 'مهمة' : 'مهام'}`;
  return `<button class="${classes}" type="button" data-action="select-calendar-day" data-day="${dateKey}" aria-label="${spokenDate}، ${taskCount ? taskLabel : 'لا توجد مهام'}${isToday ? '، اليوم' : ''}" aria-pressed="${dateKey === state.selectedTaskDate}"><span>${date.getUTCDate()}</span>${taskCount ? `<small>${taskLabel}</small>` : '<small class="calendar-empty">—</small>'}</button>`;
}

function renderImport() {
  app.innerHTML = shell(`${pageHead('استيراد العملاء', 'أضف عملاء من ملف CSV مع الحفاظ على بيانات المتابعة الحالية.')}
    <section class="panel import-panel"><div class="panel-head"><div><h2>استيراد ملف CSV</h2><small class="muted">أضف أو حدّث سجلات العملاء دفعة واحدة</small></div><span class="import-format-badge">CSV</span></div><div class="panel-body"><div class="import-overview"><span class="section-icon blue">${icon('import')}</span><div><strong>بياناتك الحالية محفوظة</strong><p>تتم المطابقة بالبريد الإلكتروني؛ تحديث السجل لا يغيّر مراحل المتابعة أو المسؤول أو الملاحظات والمهام.</p></div></div><div class="import-steps" aria-label="خطوات الاستيراد"><div><span>1</span><strong>اختر الملف</strong></div><div><span>2</span><strong>راجع المعاينة</strong></div><div><span>3</span><strong>أكد الاستيراد</strong></div></div><form id="import-form"><label class="dropzone" for="csv-file"><span class="dropzone-icon">${icon('import')}</span><strong>اختر ملف CSV من جهازك</strong><span class="muted dropzone-limit">الحد الأقصى 5 ميجابايت و10,000 صف</span><input id="csv-file" name="file" type="file" accept=".csv,text/csv" required></label><div data-error></div><button class="btn primary import-preview-button" type="submit">معاينة الملف</button></form><div id="import-result"></div></div></section>`, 'import', 'استيراد البيانات');
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
  const [response, auditResponse] = await Promise.all([request('/api/team'), request('/api/audit')]);
  state.team = response.data;
  const canManage = state.user.role === 'admin';
  const activeCount = response.data.filter((member) => member.is_active).length;
  const rows = response.data.map((member) => `<tr><td data-label="عضو الفريق"><div class="table-person"><span class="avatar">${escapeHtml(member.name.slice(0, 1))}</span><strong>${escapeHtml(member.name)}</strong></div></td><td data-label="البريد">${escapeHtml(member.email)}</td><td data-label="الصلاحية">${badge(member.role)}</td><td data-label="الحالة">${member.is_active ? badge('active') : badge('expired')}</td><td data-label="إجراء">${canManage ? `<button class="btn small" data-action="edit-member" data-id="${member.id}">تعديل</button>` : '—'}</td></tr>`).join('');
  const auditRows = auditResponse.data.map((entry) => `<div class="task-row"><div><strong>${escapeHtml(entry.summary)}</strong><small>${escapeHtml(entry.actor_name)} · ${escapeHtml(entry.target_name || 'عضو فريق')} · ${dateLabel(entry.created_at)}</small></div></div>`).join('');
  app.innerHTML = shell(`${pageHead('الفريق', 'أعضاء الفريق المسؤولون عن متابعة العملاء.', canManage ? '<button class="btn primary" data-action="new-member">+ إضافة عضو</button>' : '')}<section class="panel team-list-panel"><div class="panel-head"><div><h2>أعضاء الفريق</h2><small class="muted">إدارة الوصول ومتابعة حالة الحسابات</small></div><span class="team-count"><strong>${response.data.length}</strong><span>إجمالي الأعضاء · ${activeCount} نشط</span></span></div><div class="table-wrap"><table><thead><tr><th>عضو الفريق</th><th>البريد</th><th>الصلاحية</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>${rows || '<tr><td colspan="5"><div class="empty">لا يوجد أعضاء.</div></td></tr>'}</tbody></table></div></section><section class="panel team-audit-panel activity-panel"><div class="panel-head"><div><h2>سجل تغييرات الفريق والصلاحيات</h2><small class="muted">التعديلات الإدارية المسجلة</small></div><span class="badge">${auditResponse.data.length} تغييرات</span></div><div class="panel-body">${auditRows || '<div class="empty">لا توجد تغييرات مسجلة.</div>'}</div></section>`, 'team', 'إدارة الفريق');
  bindShell();
}

function bindShell() {
  document.querySelectorAll('[data-action]').forEach((button) => button.addEventListener('click', () => runAction(button)));
  updateNotificationButton().catch(() => {});
  document.querySelector('#global-search')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const query = String(new FormData(event.currentTarget).get('q') || '').trim();
    state.filters = query ? { q: query } : {};
    state.selectedView = '';
    state.selectedCustomers.clear();
    state.page = 1;
    location.hash = '#/customers';
    render();
  });
}

async function runAction(button) {
  try {
    if (button.dataset.action === 'save-view') return saveCurrentView();
    if (button.dataset.action === 'rename-view') return renameCurrentView();
    if (button.dataset.action === 'delete-view') return deleteCurrentView();
    if (button.dataset.action === 'bulk-update') return bulkUpdateCustomers();
    if (button.dataset.action === 'logout') {
      const subscription = await serviceWorkerRegistration?.pushManager.getSubscription();
      await request('/api/auth/logout', { method: 'POST', body: JSON.stringify({ push_endpoint: subscription?.endpoint || null }) });
      if (subscription) await subscription.unsubscribe();
      state.user = null; state.csrf = ''; state.selectedCustomers.clear(); return render();
    }
    if (button.dataset.action === 'enable-notifications') return enableBrowserNotifications();
    if (button.dataset.action === 'install-pwa') return installPwa();
    if (button.dataset.action === 'new-customer') return customerDialog();
    if (button.dataset.action === 'edit-customer') return customerDialog(state.currentCustomer);
    if (button.dataset.action === 'new-task') return taskDialog();
    if (button.dataset.action === 'new-member') return memberDialog();
    if (button.dataset.action === 'edit-member') return memberDialog(state.team.find((member) => String(member.id) === button.dataset.id));
    if (button.dataset.action === 'clear-filters') { state.filters = {}; state.selectedView = ''; state.selectedCustomers.clear(); state.page = 1; return render(); }
    if (button.dataset.action === 'clear-task-filters') { state.taskFilters = {}; return render(); }
    if (button.dataset.action === 'calendar-previous') return moveCalendarMonth(-1);
    if (button.dataset.action === 'calendar-next') return moveCalendarMonth(1);
    if (button.dataset.action === 'select-calendar-day') { state.selectedTaskDate = button.dataset.day; return render(); }
    if (button.dataset.action === 'previous') { state.page = Math.max(1, state.page - 1); return render(); }
    if (button.dataset.action === 'next') { state.page++; return render(); }
    if (button.dataset.action === 'complete-task') return completeTask(button.dataset.id);
  } catch (error) { showError(error); }
}

function moveCalendarMonth(monthOffset) {
  const [year, month] = state.calendarMonth.split('-').map(Number);
  const firstDay = new Date(Date.UTC(year, month - 1 + monthOffset, 1));
  state.calendarMonth = `${firstDay.getUTCFullYear()}-${String(firstDay.getUTCMonth() + 1).padStart(2, '0')}`;
  state.selectedTaskDate = `${state.calendarMonth}-01`;
  render();
}

function saveCurrentView() {
  const name = window.prompt('اسم العرض المحفوظ');
  if (!name || !name.trim()) return;
  const views = savedViews();
  const viewName = name.trim().slice(0, 60);
  const existing = views.findIndex((view) => view.name === viewName);
  const view = { name: viewName, filters: { ...state.filters } };
  if (existing >= 0) views[existing] = view; else views.push(view);
  storeSavedViews(views);
  state.selectedView = viewName;
  render();
}

function renameCurrentView() {
  const views = savedViews();
  const selected = views.find((view) => view.name === state.selectedView);
  if (!selected) return;
  const name = window.prompt('الاسم الجديد للعرض', selected.name);
  if (!name || !name.trim()) return;
  const nextName = name.trim().slice(0, 60);
  if (views.some((view) => view.name === nextName && view !== selected)) return window.alert('يوجد عرض محفوظ بهذا الاسم.');
  selected.name = nextName;
  storeSavedViews(views);
  state.selectedView = nextName;
  render();
}

function deleteCurrentView() {
  if (!state.selectedView || !window.confirm('حذف العرض المحفوظ؟')) return;
  storeSavedViews(savedViews().filter((view) => view.name !== state.selectedView));
  state.selectedView = '';
  render();
}

async function bulkUpdateCustomers() {
  const stage = document.querySelector('#bulk-stage')?.value || '';
  const assigneeControl = document.querySelector('#bulk-assignee');
  const assignee = assigneeControl?.value || '';
  if (!state.selectedCustomers.size || (!stage && !assignee)) return showError(new Error('حدد عملاء واختر مرحلة أو مسؤولًا.'));
  const payload = { ids: [...state.selectedCustomers] };
  if (stage) payload.crm_stage = stage;
  if (assignee) payload.assigned_to = assignee === 'none' ? null : Number(assignee);
  const result = await request('/api/customers/bulk', { method: 'POST', body: JSON.stringify(payload) });
  state.selectedCustomers.clear();
  window.alert(`تم تطبيق التغيير على ${result.updated} عميل.`);
  render();
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

function customerFields(customer = {}, tagOptions = []) {
  const field = (name, label, type = 'text') => `<div class="field"><label for="f-${name}">${label}</label><input id="f-${name}" name="${name}" type="${type}" ${name === 'registered_at' ? 'lang="en-GB"' : ''} value="${escapeHtml(customer[name] ?? '')}" ${name === 'name' || name === 'email' ? 'required' : ''}></div>`;
  const platformFields = `${field('name', 'اسم العميل')}${field('email', 'البريد الإلكتروني', 'email')}${field('phone', 'رقم الهاتف', 'tel')}${field('account_type', 'نوع الحساب')}${field('company_url', 'رابط الشركة', 'url')}${field('plan', 'الباقة')}${field('subscription_status', 'حالة الاشتراك')}${field('billing_cycle', 'دورة الفوترة')}${field('payment_status', 'حالة الدفع')}${field('source_stage', 'مرحلة الحساب في المنصة')}${field('registered_at', 'تاريخ التسجيل', 'date')}${field('last_amount', 'آخر مبلغ', 'number')}`;
  const crmFields = `<div class="field"><label for="f-crm_stage">مرحلة CRM</label><select id="f-crm_stage" name="crm_stage">${selectOptions(customerStages.map((id) => ({ id, name: labels[id] })), customer.crm_stage || 'new')}</select></div><div class="field"><label for="f-assigned_to">المسؤول داخل CRM</label><select id="f-assigned_to" name="assigned_to"><option value="">غير معين</option>${selectOptions(state.team.filter((member) => member.is_active), customer.assigned_to)}</select></div>${field('lead_source', 'مصدر الاكتساب · CRM')}${field('campaign', 'الحملة · CRM')}<div class="field full"><label for="f-tags_text">وسوم CRM</label><input id="f-tags_text" name="tags_text" list="tag-suggestions" value="${escapeHtml((customer.tags || []).map((tag) => tag.name).join(', '))}" placeholder="مثال: أولوية عالية، حملة الصيف"><datalist id="tag-suggestions">${tagOptions.map((tag) => `<option value="${escapeHtml(tag.name)}"></option>`).join('')}</datalist><small class="muted">افصل بين الوسوم بفاصلة. حتى 20 وسمًا، بحد أقصى 40 حرفًا لكل وسم.</small></div>`;
  return `<fieldset class="customer-edit-section platform-data"><legend>بيانات المنصة</legend><p>تصل من مصدر الاشتراك وقد تتحدث بالمزامنة.</p><div class="form-grid">${platformFields}</div></fieldset><fieldset class="customer-edit-section crm-data"><legend>بياناتنا في CRM</legend><p>يديرها فريقنا، ولا تستبدلها مزامنة المنصة.</p><div class="form-grid">${crmFields}</div></fieldset><div data-error></div><div class="form-actions"><button class="btn primary" type="submit">حفظ العميل</button><button class="btn" type="button" data-cancel>إلغاء</button></div>`;
}

async function customerDialog(customer = null) {
  if (!state.team.length) state.team = (await request('/api/team')).data;
  const options = await request('/api/customers/options');
  const dialog = openDialog(customer ? 'تعديل بيانات العميل' : 'إضافة عميل', `<form id="customer-form">${customerFields(customer || {}, options.tags)}</form>`);
  dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
  dialog.querySelector('#customer-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const formFields = Object.fromEntries(new FormData(event.currentTarget));
    const fields = customer ? { ...customer, ...formFields } : formFields;
    fields.tags = (formFields.tags_text || '').split(/[,،\n]/).map((tag) => tag.trim()).filter(Boolean);
    delete fields.tags_text;
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
  if (customerId && !customers.data.some((customer) => String(customer.id) === customerId)) {
    const currentCustomer = await request(`/api/customers/${customerId}`);
    customers.data.unshift(currentCustomer.customer);
  }
  const selectedCustomer = customers.data.find((customer) => String(customer.id) === customerId);
  const customerField = customerId
    ? `<div class="field full"><label>العميل</label><input type="hidden" name="customer_id" value="${escapeHtml(customerId)}"><input value="${escapeHtml(selectedCustomer?.name || '')}" readonly aria-readonly="true"><small class="muted">لا يمكن تغيير العميل من داخل ملفه.</small></div>`
    : `<div class="field full"><label>العميل</label><select name="customer_id" required><option value="">اختر العميل</option>${selectOptions(customers.data, '', (person) => person.name, (person) => person.id)}</select></div>`;
  const dialog = openDialog('إضافة متابعة', `<form id="task-form"><div class="form-grid">${customerField}<div class="field full"><label>عنوان المهمة</label><input name="title" required maxlength="200" placeholder="مثال: متابعة تجديد الاشتراك"></div><div class="field"><label>موعد المتابعة</label><input name="due_at" type="datetime-local" lang="en-GB" required></div><div class="field"><label>المسؤول</label><select name="assignee_id"><option value="">بدون مسؤول</option>${selectOptions(team.data.filter((member) => member.is_active))}</select></div><div class="field full"><label>تفاصيل</label><textarea name="details" maxlength="10000"></textarea></div></div><div data-error></div><div class="form-actions"><button class="btn primary">حفظ المهمة</button></div></form>`);
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
  const viewAll = member ? Number(member.can_view_all_customers) === 1 : false;
  const editCustomers = member ? Number(member.can_edit_customers) === 1 : true;
  const dialog = openDialog(member ? 'تعديل عضو الفريق' : 'إضافة عضو للفريق', `
    <form id="member-form"><div class="form-grid">
      <div class="field"><label>الاسم</label><input name="name" value="${escapeHtml(member?.name || '')}" required maxlength="120"></div>
      <div class="field"><label>البريد الإلكتروني</label><input name="email" type="email" value="${escapeHtml(member?.email || '')}" required></div>
      <div class="field"><label>كلمة المرور ${member ? '(اختياري)' : ''}</label><input name="password" type="password" minlength="8" ${member ? '' : 'required'}><small class="muted">8 أحرف على الأقل</small></div>
      <div class="field"><label>الصلاحية</label><select name="role" id="member-role">${roleOptions}</select>${roleHelp}</div>
      ${member ? `<div class="field"><label>الحالة</label><select name="is_active"><option value="1" ${member.is_active ? 'selected' : ''}>نشط</option><option value="0" ${!member.is_active ? 'selected' : ''}>موقوف</option></select></div>` : ''}
      <div class="field full"><label>صلاحيات العملاء</label><label class="permission-choice"><input class="permission-check" type="checkbox" name="can_view_all_customers" ${viewAll ? 'checked' : ''}> عرض كل العملاء</label><label class="permission-choice"><input class="permission-check" type="checkbox" name="can_edit_customers" ${editCustomers ? 'checked' : ''}> تعديل بيانات العملاء وإضافة المتابعات والملاحظات</label></div>
    </div><div data-error></div><div class="form-actions"><button class="btn primary">حفظ</button></div></form>`);
  const memberForm = dialog.querySelector('#member-form');
  if (!member) memberForm.querySelector('#member-role').addEventListener('change', (event) => {
    memberForm.elements.can_view_all_customers.checked = ['admin', 'manager'].includes(event.currentTarget.value);
    memberForm.elements.can_edit_customers.checked = true;
  });
  memberForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    fields.is_active = fields.is_active !== '0';
    fields.can_view_all_customers = event.currentTarget.elements.can_view_all_customers.checked;
    fields.can_edit_customers = event.currentTarget.elements.can_edit_customers.checked;
    try { await request(member ? `/api/team/${member.id}` : '/api/team', { method: member ? 'PUT' : 'POST', body: JSON.stringify(fields) }); dialog.close(); await renderTeam(); }
    catch (error) { showError(error); }
  });
}

window.addEventListener('hashchange', render);
initialize();
