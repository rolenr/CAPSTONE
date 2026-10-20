// Shared application chrome. Portal-specific content stays in each page.
const AppLayout = (() => {
    const page = document.body.dataset.page;
    const publicPage = ['login', 'register'].includes(page);
    const portals = [
        { page: 'admin', label: 'Administration', roles: ['ADMIN'] },
        { page: 'cashier', label: 'Cashier POS', roles: ['CASHIER', 'ADMIN'] },
        { page: 'vendor', label: 'Tenant Validator', roles: ['VENDOR', 'ADMIN'] },
        { page: 'dashboard', label: 'Dashboard', roles: ['DRIVER', 'ADMIN'] },
        { page: 'live-map', label: 'Live Map', roles: ['DRIVER', 'ADMIN'] },
        { page: 'wallet', label: 'Digital Wallet', roles: ['DRIVER', 'ADMIN'] },
        { page: 'reserve', label: 'Reserve Slot', roles: ['DRIVER', 'ADMIN'] },
        { page: 'violations', label: 'Violations Tracker', roles: ['DRIVER', 'ADMIN'] }
    ];
    const header = document.querySelector('[data-app-header]');
    const footer = document.querySelector('[data-app-footer]');
    const sidebar = document.getElementById('adminSidebar');

    function renderNavigation(items) {
        const nav = header?.querySelector('.main-nav');
        if (!nav) return;
        nav.replaceChildren(...items.map(item => {
            const link = document.createElement('a');
            link.href = `${item.page}.html`;
            link.textContent = item.label;
            if (item.page === page) {
                link.className = 'active';
                link.setAttribute('aria-current', 'page');
            }
            return link;
        }));
    }

    if (header) {
        header.innerHTML = `
            <div class="header-inner">
                ${sidebar ? `<button type="button" class="sidebar-toggle" aria-controls="adminSidebar" aria-expanded="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                        <path d="M9 4v16"></path>
                    </svg>
                    <span>Hide sidebar</span>
                </button>` : ''}
                <a href="login.html" class="brand-wrap" data-brand-home>
                    <span class="brand-icon" aria-hidden="true">P</span>
                    <span class="brand-title">Parking Ltd.</span>
                    <span class="brand-badge" data-session-role>Facility Portal</span>
                </a>
                <nav class="main-nav" aria-label="Main navigation"></nav>
                <div class="header-meta" ${publicPage ? 'hidden' : ''}>
                    <span class="user-profile-chip" data-session-email>Checking session…</span>
                    <button type="button" class="btn-signout" data-signout>Sign Out</button>
                </div>
            </div>`;
        if (publicPage) renderNavigation([
            { page: 'login', label: 'Sign In' },
            { page: 'register', label: 'Register Vehicle' }
        ]);
    }
    if (footer) footer.textContent = 'Parking Ltd. · Automated Parking Management';

    if (sidebar) {
        const toggle = header?.querySelector('.sidebar-toggle');
        const preferenceKey = 'parking.admin.sidebarCollapsed';
        if (toggle) {
            function setSidebarCollapsed(collapsed, remember = false) {
                if (collapsed && sidebar.contains(document.activeElement)) toggle.focus();
                sidebar.hidden = collapsed;
                toggle.setAttribute('aria-expanded', String(!collapsed));
                toggle.querySelector('span').textContent = collapsed ? 'Show sidebar' : 'Hide sidebar';
                toggle.title = collapsed ? 'Show administration sidebar' : 'Hide administration sidebar';
                if (remember) {
                    try { localStorage.setItem(preferenceKey, String(collapsed)); } catch (_) { /* The toggle works without storage. */ }
                }
                updateHeaderHeight();
            }
            let collapsed = window.matchMedia('(max-width: 1080px)').matches;
            try {
                const saved = localStorage.getItem(preferenceKey);
                if (saved !== null) collapsed = saved === 'true';
            } catch (_) { /* Use the responsive default when storage is unavailable. */ }
            setSidebarCollapsed(collapsed);
            toggle.addEventListener('click', () => setSidebarCollapsed(!sidebar.hidden, true));
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && !sidebar.hidden) {
                    setSidebarCollapsed(true, true);
                    toggle.focus();
                }
            });
        }
    }

    function updateIdentity(user) {
        renderNavigation(portals.filter(portal => portal.roles.includes(user.role)));
        document.querySelectorAll('[data-session-email]').forEach(el => {
            el.textContent = user.email || 'Signed-in account';
            el.title = user.email || '';
        });
        document.querySelectorAll('[data-session-role]').forEach(el => { el.textContent = user.role; });
        document.querySelectorAll('[data-session-plate]').forEach(el => { el.textContent = user.plate_number || 'No linked vehicle'; });
        document.querySelectorAll('[data-session-avatar]').forEach(el => {
            el.textContent = (user.email || 'P').slice(0, 2).toUpperCase();
        });
        document.querySelectorAll('[data-role-only]').forEach(el => { el.hidden = el.dataset.roleOnly !== user.role; });
        const home = portals.find(portal => portal.roles.includes(user.role));
        const brandHome = header?.querySelector('[data-brand-home]');
        if (brandHome && home) brandHome.href = `${home.page}.html`;
        document.getElementById('userPlateDisplay')?.replaceChildren(document.createTextNode(user.plate_number || 'No linked vehicle'));
        const plateSearch = document.getElementById('searchPlate');
        if (plateSearch) {
            plateSearch.value = user.plate_number || '';
            plateSearch.readOnly = user.role !== 'ADMIN';
        }
        const bookingPlate = document.getElementById('bookingPlate');
        if (bookingPlate) bookingPlate.value = user.plate_number || '';
        const vendorStaff = document.getElementById('vendor-staff-input');
        if (vendorStaff) {
            vendorStaff.value = user.email || '';
            vendorStaff.readOnly = true;
        }
        updateHeaderHeight();
    }

    function updateHeaderHeight() {
        if (header) document.documentElement.style.setProperty('--app-header-height', `${header.getBoundingClientRect().height}px`);
    }
    if (header && typeof ResizeObserver !== 'undefined') new ResizeObserver(updateHeaderHeight).observe(header);
    updateHeaderHeight();

    return { updateIdentity };
})();
