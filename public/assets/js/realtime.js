/* ───────────────────────────────────────────────
   Publish Go — Camada de tempo real
   WebSocket com reconexão + fallback automático de polling.
   Uso:  PG.realtime.on('order.created', cb);  PG.realtime.connect();
   ─────────────────────────────────────────────── */
(function () {
    'use strict';
    const PG = window.PG = window.PG || {};

    const listeners = {};      // event -> [cb]
    let ws = null;
    let connected = false;
    let reconnectTimer = null;
    let pollTimer = null;
    let usingPolling = false;
    let backoff = 1000;

    // URL pública do WebSocket.
    // Em HTTPS, ws://host:8181 é bloqueado (mixed content) e wss://host:8181 não tem TLS,
    // então só tentamos WebSocket quando há um endpoint wss explícito (window.PG_WS_URL)
    // ou quando a página é http. Caso contrário, usamos polling diretamente.
    const WS_URL = window.PG_WS_URL
        || (location.protocol === 'https:' ? null : 'ws://' + location.hostname + ':8181');

    function emit(event, payload) {
        (listeners[event] || []).forEach(cb => { try { cb(payload); } catch (e) { console.error(e); } });
        (listeners['*'] || []).forEach(cb => { try { cb(event, payload); } catch (e) {} });
    }

    function connect() {
        if (!PG.auth || !PG.auth.token) return;
        // Sem endpoint WebSocket utilizável (ex.: HTTPS sem proxy wss) → polling.
        if (!WS_URL) { startPolling(); return; }
        // Evita múltiplas conexões ao re-entrar numa view (SPA).
        if (ws && (ws.readyState === 0 || ws.readyState === 1)) { emit('_status', { connected, mode: connected ? 'ws' : 'reconnecting' }); return; }
        try {
            ws = new WebSocket(WS_URL);
        } catch (e) { startPolling(); return; }

        const giveUp = setTimeout(() => { if (!connected) { try { ws.close(); } catch (e) {} startPolling(); } }, 2500);

        ws.onopen = () => {
            connected = true; backoff = 1000;
            clearTimeout(giveUp);
            stopPolling();
            ws.send(JSON.stringify({ type: 'auth', token: PG.auth.token }));
            emit('_status', { connected: true, mode: 'ws' });
            // keep-alive
            ws._ping = setInterval(() => { if (ws.readyState === 1) ws.send(JSON.stringify({ type: 'ping' })); }, 25000);
        };
        ws.onmessage = (msg) => {
            let data; try { data = JSON.parse(msg.data); } catch (e) { return; }
            if (data.type === 'event') emit(data.event, data.payload);
        };
        ws.onclose = () => {
            connected = false;
            clearInterval(ws && ws._ping);
            emit('_status', { connected: false, mode: usingPolling ? 'polling' : 'reconnecting' });
            scheduleReconnect();
        };
        ws.onerror = () => { try { ws.close(); } catch (e) {} };
    }

    function scheduleReconnect() {
        clearTimeout(reconnectTimer);
        startPolling(); // garante atualizações enquanto o WS não volta
        reconnectTimer = setTimeout(connect, backoff);
        backoff = Math.min(backoff * 1.7, 15000);
    }

    /* ── Fallback de polling inteligente ──
       Reexecuta os callbacks de "refresh" registrados pelas páginas. */
    function startPolling() {
        if (usingPolling) return;
        usingPolling = true;
        emit('_status', { connected: false, mode: 'polling' });
        const tick = () => emit('poll', { ts: Date.now() });
        pollTimer = setInterval(tick, 5000);
    }
    function stopPolling() {
        usingPolling = false;
        clearInterval(pollTimer);
        pollTimer = null;
    }

    PG.realtime = {
        on(event, cb) { (listeners[event] = listeners[event] || []).push(cb); return this; },
        off(event, cb) { listeners[event] = (listeners[event] || []).filter(f => f !== cb); },
        // Remove todos os listeners de dados (chamado ao trocar de view na SPA),
        // preservando a conexão WebSocket e o status interno.
        clear() { Object.keys(listeners).forEach(k => { if (k !== '_status') delete listeners[k]; }); },
        connect,
        get connected() { return connected; },
        get mode() { return connected ? 'ws' : (usingPolling ? 'polling' : 'offline'); },
    };
})();
