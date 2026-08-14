/* ───────────────────────────────────────────────
   Publish Go — Núcleo do frontend
   Cliente de API (com refresh de token), auth, toasts,
   tema, formatadores, shell de navegação e ícones.
   ─────────────────────────────────────────────── */
(function () {
    'use strict';

    const BASE = (window.PG_BASE || '').replace(/\/$/, '');
    const API_BASE = BASE + '/api';
    const STORAGE = 'pg_auth';

    const PG = window.PG = window.PG || {};
    PG.base = BASE;
    PG.url = (p) => BASE + p;

    /* ── Estado de autenticação (persistido em localStorage) ── */
    PG.auth = {
        get data() {
            try { return JSON.parse(localStorage.getItem(STORAGE) || 'null'); }
            catch (e) { return null; }
        },
        set(data) { localStorage.setItem(STORAGE, JSON.stringify(data)); },
        clear() { localStorage.removeItem(STORAGE); },
        get token() { return this.data?.access_token || null; },
        get refresh() { return this.data?.refresh_token || null; },
        get user() { return this.data?.user || null; },
        get company() { return this.data?.company || null; },
        get isAuthed() { return !!this.token; },
    };

    /* ── Cliente HTTP ── */
    async function request(method, path, body, retry = true) {
        const headers = { 'Content-Type': 'application/json' };
        if (PG.auth.token) headers['Authorization'] = 'Bearer ' + PG.auth.token;

        let res;
        try {
            res = await fetch(API_BASE + path, {
                method,
                headers,
                body: body !== undefined ? JSON.stringify(body) : undefined,
            });
        } catch (networkErr) {
            throw { message: 'Falha de conexão com o servidor.', status: 0 };
        }

        // Token expirado → tenta refresh uma vez.
        if (res.status === 401 && retry && PG.auth.refresh && path !== '/auth/refresh') {
            const ok = await tryRefresh();
            if (ok) return request(method, path, body, false);
            PG.auth.clear();
            if (!location.pathname.endsWith('/app/index.html') && location.pathname !== '/app' && location.pathname !== '/') {
                location.href = PG.url('/app/index.html');
            }
        }

        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }

        if (!res.ok || (json && json.ok === false)) {
            const err = (json && json.error) || { message: 'Erro inesperado (' + res.status + ').' };
            err.status = res.status;
            throw err;
        }
        return json ? json.data : null;
    }

    async function tryRefresh() {
        try {
            const res = await fetch(API_BASE + '/auth/refresh', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ refresh_token: PG.auth.refresh }),
            });
            const json = await res.json();
            if (res.ok && json.ok) { PG.auth.set(json.data); return true; }
        } catch (e) { /* ignore */ }
        return false;
    }

    PG.api = {
        get: (p) => request('GET', p),
        post: (p, b) => request('POST', p, b),
        put: (p, b) => request('PUT', p, b),
        patch: (p, b) => request('PATCH', p, b),
        del: (p) => request('DELETE', p),
    };

    /* ── Guarda de rota ── */
    PG.requireAuth = function () {
        if (!PG.auth.isAuthed) { location.href = PG.url('/app/index.html'); return false; }
        return true;
    };

    /* ── Versão exibida no rodapé da sidebar ── */
    PG.VERSION = '1.0.0';

    /* ── Tema (dark/light) ── */
    PG.theme = {
        get current() { return localStorage.getItem('pg_theme') || 'light'; },
        apply(t) {
            const theme = t || this.current;
            document.documentElement.classList.toggle('dark', theme === 'dark');
            localStorage.setItem('pg_theme', theme);
        },
        toggle() { this.apply(this.current === 'dark' ? 'light' : 'dark'); },
    };
    PG.theme.apply();

    /* ── Identidade visual da empresa (whitelabel) ── */
    PG.applyBrand = function (company) {
        if (!company) return;
        const root = document.documentElement.style;
        if (company.primary_color) root.setProperty('--pg-primary', company.primary_color);
        if (company.accent_color) root.setProperty('--pg-accent', company.accent_color);
        document.querySelectorAll('[data-brand-name]').forEach(el => el.textContent = company.name || 'Publish Go');
        document.querySelectorAll('[data-brand-logo]').forEach(el => {
            if (company.logo_url) { el.src = company.logo_url; el.classList.remove('hidden'); }
        });
    };

    /* ── Toasts ── */
    PG.toast = function (message, type = 'info') {
        let host = document.getElementById('pg-toasts');
        if (!host) { host = document.createElement('div'); host.id = 'pg-toasts'; document.body.appendChild(host); }
        const colors = {
            success: '#22c55e', error: '#ef4444', info: 'var(--pg-primary)', warn: '#f59e0b',
        };
        const icons = {
            success: 'M5 13l4 4L19 7', error: 'M6 18L18 6M6 6l12 12',
            info: 'M13 16h-1v-4h-1m1-4h.01', warn: 'M12 9v2m0 4h.01M10.29 3.86l-8.48 14.7A1 1 0 002.67 20h16.66a1 1 0 00.86-1.44L11.71 3.86a1 1 0 00-1.72 0z',
        };
        const el = document.createElement('div');
        el.className = 'pg-toast pg-glass';
        el.innerHTML = `
            <span style="color:${colors[type] || colors.info}" class="shrink-0 mt-.5">
              <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="${icons[type] || icons.info}"/></svg>
            </span>
            <span class="text-sm leading-snug">${message}</span>`;
        host.appendChild(el);
        setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 300); }, 3800);
    };

    /* ── Formatadores ── */
    PG.fmt = {
        money(v) { return (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); },
        number(v) { return (Number(v) || 0).toLocaleString('pt-BR'); },
        timeAgo(dateStr) {
            const d = new Date((dateStr || '').replace(' ', 'T'));
            const s = Math.floor((Date.now() - d.getTime()) / 1000);
            if (isNaN(s)) return '';
            if (s < 60) return 'agora';
            if (s < 3600) return Math.floor(s / 60) + ' min';
            if (s < 86400) return Math.floor(s / 3600) + ' h';
            return Math.floor(s / 86400) + ' d';
        },
        time(dateStr) {
            const d = new Date((dateStr || '').replace(' ', 'T'));
            return isNaN(d) ? '' : d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        },
    };

    PG.STATUS_LABELS = {
        received: 'Recebido', preparing: 'Em preparo', ready: 'Pronto',
        dispatched: 'Despachado', picked: 'A caminho', delivered: 'Entregue', canceled: 'Cancelado',
    };
    PG.PRIORITY_LABELS = { low: 'Baixa', normal: 'Normal', high: 'Alta', urgent: 'Urgente' };

    /* ── Ícones SVG (Heroicons outline) ── */
    const ICONS = {
        dashboard: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        orders: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
        map: 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
        couriers: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4 0M17 7a3 3 0 11-6 0 3 3 0 016 0z',
        finance: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        sun: 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z',
        moon: 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z',
        logout: 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1',
        plus: 'M12 4v16m8-8H4', bell: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
        menu: 'M4 6h16M4 12h16M4 18h16', close: 'M6 18L18 6M6 6l12 12',
        chevron: 'M19 9l-7 7-7-7',
        search: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
        clock: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        check: 'M5 13l4 4L19 7', bolt: 'M13 10V3L4 14h7v7l9-11h-7z',
        cog: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
        catalog: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        tag: 'M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 9V4a1 1 0 011-1z',
        report: 'M9 17v-6m4 6V7m4 10v-4M3 4h18v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4z',
        store: 'M3 9l1-5h16l1 5M4 9h16v10a1 1 0 01-1 1H5a1 1 0 01-1-1V9zm5 4h6',
        external: 'M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14',
    };
    PG.icon = (name, cls = 'w-5 h-5') =>
        `<svg class="${cls}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="${ICONS[name] || ICONS.dashboard}"/></svg>`;

    /* ── Shell de navegação (sidebar + topbar) ── */
    PG.renderShell = function (active) {
        const nav = [
            ['dashboard', 'Dashboard', PG.url('/app/dashboard.html')],
            ['orders', 'Pedidos', PG.url('/app/orders.html')],
            ['catalog', 'Catálogo', PG.url('/app/catalog.html')],
            ['tag', 'Promoções', PG.url('/app/promotions.html')],
            ['map', 'Entregas', PG.url('/app/deliveries.html')],
            ['couriers', 'Motoboys', PG.url('/app/couriers.html')],
            ['report', 'Relatórios', PG.url('/app/reports.html')],
            ['cog', 'Configurações', PG.url('/app/settings.html')],
        ];
        const user = PG.auth.user || {};
        const company = PG.auth.company || {};
        const initials = (user.name || 'U').split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase();

        const links = nav.map(([icon, label, href]) =>
            `<a href="${href}" class="pg-nav ${active === icon ? 'active' : ''}">${PG.icon(icon)}<span>${label}</span></a>`
        ).join('');

        const aside = `
        <aside id="pg-sidebar" class="fixed lg:sticky top-0 z-40 h-screen w-64 shrink-0 -translate-x-full lg:translate-x-0 transition-transform duration-300 pg-glass lg:shadow-none lg:border-0 lg:border-r flex flex-col p-4" style="border-color:var(--pg-border)">
            <div class="flex items-center gap-3 px-2 py-3">
                <div class="w-10 h-10 rounded-xl grid place-items-center font-bold shrink-0" style="background:var(--pg-primary);color:#2B2B2B">PG</div>
                <div class="min-w-0">
                    <div class="font-bold leading-tight truncate" data-brand-name>${company.name || 'Publish Go'}</div>
                    <div class="text-xs" style="color:var(--pg-muted)">Publish Go • Delivery</div>
                </div>
            </div>
            <nav class="mt-4 flex flex-col gap-1">${links}</nav>
            <div class="mt-auto pt-4 space-y-2" style="border-top:1px solid var(--pg-border)">
                <div class="flex items-center gap-3 px-2 pt-3">
                    <div class="w-9 h-9 rounded-full grid place-items-center text-sm font-semibold shrink-0" style="background:var(--pg-primary);color:#2B2B2B">${initials}</div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold truncate">${user.name || 'Usuário'}</div>
                        <div class="text-xs truncate" style="color:var(--pg-muted)">${user.email || ''}</div>
                    </div>
                </div>
                <button onclick="PG.logout()" class="pg-nav w-full">${PG.icon('logout', 'w-5 h-5')}<span>Sair</span></button>
                <div class="px-2 text-xs" style="color:var(--pg-muted)">Versão ${PG.VERSION}</div>
            </div>
        </aside>
        <div id="pg-backdrop" onclick="PG.toggleSidebar(false)" class="fixed inset-0 z-30 bg-black/40 lg:hidden hidden"></div>`;

        const greeting = {dashboard:'Visão geral',orders:'Pedidos',catalog:'Catálogo',tag:'Promoções',map:'Entregas & Mapa',couriers:'Motoboys',report:'Relatórios',cog:'Configurações'}[active] || '';
        const topbar = `
        <header class="sticky top-0 z-20 pg-glass lg:border-0 lg:border-b px-4 lg:px-8 py-3 flex items-center gap-3" style="border-color:var(--pg-border)">
            <button onclick="PG.toggleSidebar(true)" class="pg-btn pg-btn-ghost !p-2 lg:hidden">${PG.icon('menu')}</button>
            <div class="flex-1 min-w-0">
                <h1 id="pg-page-title" class="text-lg lg:text-xl font-bold tracking-tight truncate">${greeting}</h1>
            </div>
            <div class="flex items-center gap-2">
                <span class="pg-badge st-delivered pg-live mr-1 hidden sm:inline-flex">Tempo real</span>
                <button onclick="PG.theme.toggle()" class="pg-btn pg-btn-ghost !p-2" title="Tema">
                    <span class="dark:hidden">${PG.icon('moon')}</span><span class="hidden dark:inline">${PG.icon('sun')}</span>
                </button>
                <button class="pg-btn pg-btn-ghost !p-2 relative" title="Notificações">${PG.icon('bell')}</button>
                <div class="relative">
                    <button onclick="PG.toggleUserMenu()" class="pg-btn pg-btn-ghost !py-1.5 !px-2 flex items-center gap-2" title="Conta">
                        <span class="w-7 h-7 rounded-full grid place-items-center text-xs font-semibold shrink-0" style="background:var(--pg-primary);color:#2B2B2B">${initials}</span>
                        <span class="hidden sm:block text-sm font-medium max-w-[10rem] truncate">${company.name || user.name || 'Conta'}</span>
                        ${PG.icon('chevron', 'w-4 h-4')}
                    </button>
                    <div id="pg-user-menu" class="hidden absolute right-0 mt-2 w-56 pg-card p-1.5 z-30">
                        <div class="px-3 py-2 border-b" style="border-color:var(--pg-border)">
                            <div class="text-sm font-semibold truncate">${user.name || 'Usuário'}</div>
                            <div class="text-xs truncate" style="color:var(--pg-muted)">${user.email || ''}</div>
                        </div>
                        <a href="${PG.url('/app/settings.html')}" class="pg-nav w-full mt-1">${PG.icon('cog', 'w-5 h-5')}<span>Configurações</span></a>
                        <button onclick="PG.logout()" class="pg-nav w-full">${PG.icon('logout', 'w-5 h-5')}<span>Sair</span></button>
                    </div>
                </div>
            </div>
        </header>`;

        return { aside, topbar };
    };

    /* Monta o shell (sidebar + topbar) de forma idempotente e à prova de null,
       seguro para reinicializações da SPA. */
    PG.mountShell = function (active) {
        const { aside, topbar } = PG.renderShell(active);
        const a = document.getElementById('shell-aside');
        if (a) a.outerHTML = aside;
        const t = document.getElementById('shell-top');
        if (t) t.outerHTML = topbar;
    };

    PG.toggleUserMenu = function () {
        const m = document.getElementById('pg-user-menu');
        if (!m) return;
        const willOpen = m.classList.contains('hidden');
        m.classList.toggle('hidden', !willOpen);
        if (willOpen) {
            const close = (e) => {
                if (!m.contains(e.target) && !e.target.closest('[onclick="PG.toggleUserMenu()"]')) {
                    m.classList.add('hidden');
                    document.removeEventListener('click', close);
                }
            };
            setTimeout(() => document.addEventListener('click', close), 0);
        }
    };

    PG.toggleSidebar = function (open) {
        const sb = document.getElementById('pg-sidebar');
        const bd = document.getElementById('pg-backdrop');
        if (!sb) return;
        sb.classList.toggle('-translate-x-full', !open);
        bd.classList.toggle('hidden', !open);
    };

    PG.logout = function () {
        PG.auth.clear();
        location.href = PG.url('/app/index.html');
    };

    /* Som de novo pedido (oscilador WebAudio — sem assets externos). */
    PG.playChime = function () {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const notes = [880, 1175];
            notes.forEach((f, i) => {
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sine'; o.frequency.value = f;
                o.connect(g); g.connect(ctx.destination);
                const t = ctx.currentTime + i * 0.16;
                g.gain.setValueAtTime(0.0001, t);
                g.gain.exponentialRampToValueAtTime(0.22, t + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + 0.32);
                o.start(t); o.stop(t + 0.34);
            });
        } catch (e) { /* navegador bloqueou autoplay */ }
    };

    /* ───────────────────────────────────────────────
       SPA — navegação AJAX sem reload (progressive enhancement)
       Intercepta links do painel, troca o conteúdo via fetch e
       reinicializa o Alpine. Em qualquer falha, faz navegação normal.
       ─────────────────────────────────────────────── */
    const SPA_PAGES = ['dashboard.html', 'orders.html', 'deliveries.html', 'couriers.html', 'settings.html'];
    let leaveHooks = [];

    PG.spa = {
        onLeave(fn) { leaveHooks.push(fn); },
        isSpaUrl(href) {
            try {
                const u = new URL(href, location.href);
                return u.origin === location.origin
                    && u.pathname.startsWith(PG.base + '/app/')
                    && SPA_PAGES.some(p => u.pathname.endsWith('/' + p));
            } catch (e) { return false; }
        },
        teardown() {
            leaveHooks.forEach(fn => { try { fn(); } catch (e) {} });
            leaveHooks = [];
            if (PG.realtime && PG.realtime.clear) PG.realtime.clear();
        },
        async ensureResources(doc) {
            const have = new Set(Array.from(document.querySelectorAll('script[src]')).map(s => s.src));
            for (const link of doc.querySelectorAll('link[rel="stylesheet"][href]')) {
                const abs = new URL(link.getAttribute('href'), location.href).href;
                if (!document.querySelector(`link[href="${abs}"]`) && !document.querySelector(`link[href="${link.getAttribute('href')}"]`)) {
                    const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = link.href; document.head.appendChild(l);
                }
            }
            for (const s of doc.querySelectorAll('script[src]')) {
                if (have.has(s.src)) continue;
                await new Promise((resolve) => {
                    const el = document.createElement('script');
                    el.src = s.src; el.onload = resolve; el.onerror = resolve;
                    document.head.appendChild(el);
                });
            }
        },
        async navigate(href, push = true) {
            if (!window.Alpine) throw new Error('alpine not ready'); // → fallback navegação normal
            const res = await fetch(href, { headers: { 'X-Requested-With': 'fetch' } });
            if (!res.ok) throw new Error('fetch failed');
            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');

            await this.ensureResources(doc);
            this.teardown();

            // Remove o conteúdo atual (preserva scripts persistentes, aurora e toasts).
            const keep = (el) => el.tagName === 'SCRIPT' || el.classList?.contains('pg-aurora') || el.id === 'pg-toasts';
            Array.from(document.body.children).forEach(el => { if (!keep(el)) el.remove(); });

            // Define as funções/handlers da nova view ANTES de inserir (Alpine auto-init).
            doc.body.querySelectorAll('script:not([src])').forEach(s => {
                const el = document.createElement('script');
                el.textContent = s.textContent;
                document.body.appendChild(el);
                el.remove();
            });

            // Insere o conteúdo da nova view. O observer do Alpine inicializa os
            // x-data; cada componente tem guard de init único (this.$el._pgInit).
            const anchor = document.querySelector('.pg-aurora');
            let cursor = anchor;
            Array.from(doc.body.children).forEach(el => {
                if (el.tagName === 'SCRIPT' || el.classList?.contains('pg-aurora') || el.id === 'pg-toasts') return;
                const node = document.importNode(el, true);
                if (cursor) { cursor.after(node); cursor = node; } else { document.body.appendChild(node); }
            });

            document.title = doc.title || document.title;
            if (push) history.pushState({ spa: true }, '', href);
            window.scrollTo(0, 0);
        },
    };

    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest('a[href]');
        if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
        const href = a.getAttribute('href');
        if (!href || !PG.spa.isSpaUrl(a.href)) return;
        e.preventDefault();
        PG.spa.navigate(a.href).catch(() => { location.href = a.href; });
    });

    window.addEventListener('popstate', () => {
        if (PG.spa.isSpaUrl(location.href)) {
            PG.spa.navigate(location.href, false).catch(() => location.reload());
        }
    });

    /* Pré-registra os stores do Alpine usados pelas views (sobrevive à SPA). */
    document.addEventListener('alpine:init', () => {
        if (!window.Alpine) return;
        if (!Alpine.store('modal')) Alpine.store('modal', { open: false });
        if (!Alpine.store('cmodal')) Alpine.store('cmodal', { open: false, editing: null });
        if (!Alpine.store('amodal')) Alpine.store('amodal', { open: false });
    });

    /* Registra o service worker (PWA). */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register(PG.url('/sw.js'), { scope: PG.base + '/' }).catch(() => {}));
    }
})();
