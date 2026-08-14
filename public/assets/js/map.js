/* ───────────────────────────────────────────────
   Publish Go — Mapa operacional (Leaflet + OpenStreetMap)
   Tiles de satélite/tráfego Google (gratuitos, não-oficiais),
   marcadores de motoboy em tempo real, clustering, rotas e ETA.
   ─────────────────────────────────────────────── */
(function () {
    'use strict';
    const PG = window.PG = window.PG || {};

    const SAT_TILES = 'https://mt0.google.com/vt/lyrs=y,traffic&x={x}&y={y}&z={z}&s=Galil';
    const OSM_TILES = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

    function divIcon(html, cls, size = 38) {
        return L.divIcon({ html, className: 'pg-leaflet-icon', iconSize: [size, size], iconAnchor: [size / 2, size / 2] });
    }

    class PGMap {
        constructor(el, opts = {}) {
            const center = opts.center || [-23.561414, -46.655881];
            this.map = L.map(el, { zoomControl: false, attributionControl: true, preferCanvas: true })
                .setView(center, opts.zoom || 14);
            L.control.zoom({ position: 'bottomright' }).addTo(this.map);

            this.satLayer = L.tileLayer(SAT_TILES, { maxZoom: 21, subdomains: ['0', '1', '2', '3'], attribution: '&copy; Google • OpenStreetMap' });
            this.osmLayer = L.tileLayer(OSM_TILES, { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
            this.satLayer.addTo(this.map);

            this.couriers = {};                 // id -> marker
            this.routeLine = null;
            this.cluster = (typeof L.markerClusterGroup === 'function')
                ? L.markerClusterGroup({
                    iconCreateFunction: (c) => divIcon('<div><span>' + c.getChildCount() + '</span></div>', 'marker-cluster marker-cluster-pg', 40),
                    showCoverageOnHover: false, maxClusterRadius: 55,
                })
                : null;
            if (this.cluster) this.map.addLayer(this.cluster);

            setTimeout(() => this.map.invalidateSize(), 200);
        }

        toggleLayer() {
            if (this.map.hasLayer(this.satLayer)) { this.map.removeLayer(this.satLayer); this.osmLayer.addTo(this.map); }
            else { this.map.removeLayer(this.osmLayer); this.satLayer.addTo(this.map); }
        }

        setStore(lat, lng, name) {
            if (lat == null || lng == null) return;
            const icon = divIcon('<div class="pg-store-marker">' + PG.icon('bolt', 'w-5 h-5') + '</div>', '', 40);
            if (this.storeMarker) this.storeMarker.setLatLng([lat, lng]);
            else this.storeMarker = L.marker([lat, lng], { icon, zIndexOffset: 500 }).addTo(this.map).bindPopup('<b>' + (name || 'Estabelecimento') + '</b>');
        }

        setDestination(lat, lng, label) {
            if (lat == null || lng == null) { if (this.destMarker) { this.map.removeLayer(this.destMarker); this.destMarker = null; } return; }
            const icon = divIcon('<div class="pg-dest-marker">' + PG.icon('check', 'w-4 h-4') + '</div>', '', 34);
            if (this.destMarker) this.destMarker.setLatLng([lat, lng]);
            else this.destMarker = L.marker([lat, lng], { icon, zIndexOffset: 400 }).addTo(this.map);
            if (label) this.destMarker.bindPopup(label);
        }

        upsertCourier(c) {
            if (c.lat == null || c.lng == null) return;
            const html = '<div class="pg-courier-marker" style="transform:rotate(' + (45) + 'deg)"><span>🛵</span></div>';
            let marker = this.couriers[c.id];
            if (marker) {
                marker.setLatLng([c.lat, c.lng]);
            } else {
                marker = L.marker([c.lat, c.lng], { icon: divIcon(html, '', 38), zIndexOffset: 300 });
                marker.bindTooltip(c.name || ('Motoboy #' + c.id), { direction: 'top', offset: [0, -16] });
                this.couriers[c.id] = marker;
                if (this.cluster) this.cluster.addLayer(marker); else marker.addTo(this.map);
            }
            marker._pgStatus = c.status;
            return marker;
        }

        removeCourier(id) {
            const m = this.couriers[id];
            if (!m) return;
            if (this.cluster) this.cluster.removeLayer(m); else this.map.removeLayer(m);
            delete this.couriers[id];
        }

        syncCouriers(list) {
            const seen = {};
            list.forEach(c => { seen[c.id] = true; this.upsertCourier(c); });
            Object.keys(this.couriers).forEach(id => { if (!seen[id]) this.removeCourier(id); });
        }

        drawRoute(points) {
            if (this.routeLine) { this.map.removeLayer(this.routeLine); this.routeLine = null; }
            if (!points || points.length < 2) return;
            const latlngs = points.map(p => [p.lat, p.lng]);
            this.routeLine = L.polyline(latlngs, {
                color: getComputedStyle(document.documentElement).getPropertyValue('--pg-primary').trim() || '#ef4444',
                weight: 5, opacity: .85, dashArray: '1,10', lineCap: 'round',
            }).addTo(this.map);
            this.map.fitBounds(this.routeLine.getBounds().pad(0.25));
        }

        fitAll() {
            const pts = [];
            if (this.storeMarker) pts.push(this.storeMarker.getLatLng());
            Object.values(this.couriers).forEach(m => pts.push(m.getLatLng()));
            if (this.destMarker) pts.push(this.destMarker.getLatLng());
            if (pts.length === 1) this.map.setView(pts[0], 15);
            else if (pts.length > 1) this.map.fitBounds(L.latLngBounds(pts).pad(0.2));
        }

        /**
         * Traça a rota real pelas ruas (OSRM, gratuito/open-source) e retorna
         * distância, tempo e instruções — navegação dentro do próprio app.
         */
        async routeRoad(from, to) {
            const url = 'https://router.project-osrm.org/route/v1/driving/'
                + from.lng + ',' + from.lat + ';' + to.lng + ',' + to.lat
                + '?overview=full&geometries=geojson&steps=true';
            let data;
            try { const res = await fetch(url); data = await res.json(); } catch (e) { return null; }
            if (!data || !data.routes || !data.routes.length) return null;
            const r = data.routes[0];
            const coords = r.geometry.coordinates.map(c => [c[1], c[0]]);
            const primary = getComputedStyle(document.documentElement).getPropertyValue('--pg-primary').trim() || '#2563eb';
            if (this.roadLine) this.map.removeLayer(this.roadLine);
            if (this.roadCasing) this.map.removeLayer(this.roadCasing);
            this.roadCasing = L.polyline(coords, { color: '#000', weight: 9, opacity: .25, lineCap: 'round', lineJoin: 'round' }).addTo(this.map);
            this.roadLine = L.polyline(coords, { color: primary, weight: 5, opacity: .95, lineCap: 'round', lineJoin: 'round' }).addTo(this.map);
            this.map.fitBounds(this.roadLine.getBounds().pad(0.2));
            const steps = (r.legs[0]?.steps || []).map(s => ({
                text: PGMap.maneuverPt(s.maneuver, s.name),
                distance: Math.round(s.distance),
            })).filter(s => s.text);
            return { distance: r.distance, duration: r.duration, steps };
        }

        clearRoad() {
            if (this.roadLine) { this.map.removeLayer(this.roadLine); this.roadLine = null; }
            if (this.roadCasing) { this.map.removeLayer(this.roadCasing); this.roadCasing = null; }
        }

        static maneuverPt(m, road) {
            const dir = { left: 'à esquerda', right: 'à direita', 'slight left': 'levemente à esquerda', 'slight right': 'levemente à direita', 'sharp left': 'acentuada à esquerda', 'sharp right': 'acentuada à direita', straight: 'em frente', uturn: 'retorne' }[m.modifier] || '';
            const onRoad = road ? (' na ' + road) : '';
            switch (m.type) {
                case 'depart': return 'Siga' + onRoad;
                case 'arrive': return 'Você chegou ao destino';
                case 'turn': return 'Vire ' + dir + onRoad;
                case 'roundabout': case 'rotary': return 'Entre na rotatória' + onRoad;
                case 'merge': return 'Acesse' + onRoad;
                case 'fork': return 'Mantenha-se ' + (dir || 'em frente') + onRoad;
                case 'end of road': return 'No fim da via, vire ' + dir + onRoad;
                case 'continue': return 'Continue ' + (dir || 'em frente') + onRoad;
                default: return road ? ('Siga na ' + road) : '';
            }
        }

        invalidate() { this.map.invalidateSize(); }
    }

    PG.createMap = (el, opts) => new PGMap(el, opts);
})();
