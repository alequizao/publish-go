# 🛵 Publish Go

Plataforma **whitelabel** de gestão de delivery e logística inteligente — para estabelecimentos e motoboys. Pedidos, despacho, mapa operacional em tempo real, roteirização e financeiro num só lugar.

> **Online agora:** https://publishdev.com.br/publishgo/

### Hierarquia & acessos
```
Publish Go (Central / Super-Admin)  →  cadastra e gerencia as EMPRESAS
        └── Empresa (Estabelecimento) →  cadastra seus MOTOBOYS, despacha pedidos
                  └── Motoboy          →  app próprio: aceita corridas e entrega
```

| Nível | URL | Login demo |
|------|-----|-----------|
| **Central (Super-Admin)** | `/publishgo/` → `app/admin.html` | (usuário e senha definidos na instalação) |
| **Estabelecimento** | `/publishgo/` → `app/dashboard.html` | (usuário e senha definidos na instalação) |
| **Motoboy (app)** | `/publishgo/app/courier.html` | (telefone e senha cadastrados) |

> O login em `/publishgo/` direciona automaticamente para a Central ou o painel do
> estabelecimento conforme o papel. Motoboys novos se cadastram em
> `app/courier-register.html?empresa=<slug>` (link gerado na tela **Motoboys**).

---

## ✨ O que já está implementado (Fase 1)

**Backend (PHP 8.3, MVC, API REST)**
- Arquitetura MVC própria (sem framework pesado), PSR-4 via Composer.
- Autenticação **JWT** (HS256, access + refresh) sem dependências externas.
- Multi-tenant (whitelabel) por `company_id` em todas as queries.
- Middlewares: CORS, **rate limiting**, autenticação, CSRF (para fluxos de sessão).
- Validação + sanitização server-side; PDO com **prepared statements** (anti SQL-Injection).
- Respostas JSON padronizadas `{ ok, data, error }`.

**Tempo real**
- Servidor **WebSocket em PHP puro** (Workerman), canais por empresa.
- A API publica eventos (`order.created`, `order.status`, `courier.location`, `delivery.dispatched`).
- **Fallback automático de polling** quando o WebSocket não está disponível (ex.: HTTPS sem proxy `wss`).

**Painel do Estabelecimento (frontend)**
- TailwindCSS + AlpineJS, **dark mode**, design premium (glassmorphism, skeletons, animações).
- **Dashboard** com KPIs, gráfico ao vivo (Chart.js) e operação em tempo real.
- **Pedidos**: criar/editar/cancelar, fila com prioridade, múltiplos itens, busca e filtros.
- **Entregas/Mapa**: Leaflet + tiles de satélite, motoboys ao vivo, **clustering**, rota, distância (Haversine) e **ETA**, despacho **manual e automático** (motoboy mais próximo), roteirização multi-ponto.
- **PWA** instalável: `manifest.json` + Service Worker (cache offline, push, background sync).

**Banco de dados (MySQL)**
- Schema normalizado com FKs e índices: `companies, users, couriers, orders, order_items, deliveries, transactions, audit_logs`.
- Migrations versionadas + seed com dados de demonstração realistas.

---

## 🚀 Instalação

```bash
cd /www/wwwroot/publishdev.com.br/publishgo

# 1) Dependências
composer install

# 2) Configuração
cp .env.example .env        # ajuste DB, JWT_SECRET, WS_*, APP_BASE

# 3) Banco (cria as tabelas no schema 'publishgo')
php bin/migrate.php

# 4) Dados de demonstração (opcional)
php database/seed.php

# 5) Ícones do PWA (já gerados; regenere se trocar a marca)
php bin/make-icons.php
```

### Variáveis principais (`.env`)
| Chave | Descrição |
|------|-----------|
| `DB_DATABASE/USERNAME/PASSWORD` | definidos no `.env` (não versionado) |
| `JWT_SECRET` | **troque** por uma chave longa e aleatória |
| `APP_BASE` | prefixo da URL — `/publishgo` (vazio = raiz do domínio) |
| `WS_PORT` | porta do WebSocket (público) — interno usa `WS_PORT+1` |
| `WS_INTERNAL_SECRET` | segredo da ponte API → WS |

---

## ▶️ Execução

### Já configurado neste servidor (aaPanel + Apache)
O app é servido em **subpasta** sob o docroot compartilhado `/www/wwwroot/publishdev.com.br`.
O arquivo [`.htaccess`](.htaccess) na raiz de `publishgo/`:
- mapeia `/publishgo/...` para o webroot real (`public/`);
- roteia a API para o front controller;
- executa o PHP no **PHP-FPM 8.3** apenas neste diretório (sem afetar os demais apps do domínio, que usam 7.4);
- protege `.env`, `src/`, `vendor/`.

Acesse: **https://publishdev.com.br/publishgo/**

### Servidor WebSocket (tempo real)
```bash
php bin/ws-server.php start -d     # daemon
php bin/ws-server.php status
php bin/ws-server.php stop
```
Em produção, use o systemd incluído:
```bash
cp deploy/publishgo-ws.service /etc/systemd/system/
systemctl enable --now publishgo-ws
```

> **HTTPS + WebSocket:** navegadores em páginas `https://` exigem `wss://`. A porta 8181 é `ws://` puro,
> então sobre HTTPS o painel usa automaticamente o **polling** (atualiza a cada 5s). Para tempo real
> via WebSocket sobre HTTPS, exponha a porta 8181 atrás de um proxy `wss` (Apache/Nginx) e ajuste `WS_PUBLIC_URL`.

### Simulador de mapa (demonstração)
Move os motoboys online e publica posições no WebSocket — o mapa "ganha vida":
```bash
php bin/simulate.php           # loop contínuo (~2s)
php bin/simulate.php once      # uma iteração
```

### Servidor de desenvolvimento (alternativa ao Apache)
```bash
php -S 0.0.0.0:8080 -t public public/router.php
# Painel: http://localhost:8080/  •  API: http://localhost:8080/api/health
```

### Docker (opcional)
```bash
docker compose up --build
# Painel: http://localhost:8080  •  WS: ws://localhost:8181
```

---

## 🔌 API REST (resumo)

Base: `/publishgo/api` · Autenticação: `Authorization: Bearer <access_token>`

| Método | Rota | Descrição |
|--------|------|-----------|
| POST | `/auth/register` | cria empresa + usuário |
| POST | `/auth/login` | login (email/senha) |
| POST | `/auth/refresh` | renova o access token |
| GET | `/auth/me` | usuário + empresa |
| GET | `/dashboard/summary` · `/dashboard/chart` | KPIs e séries |
| GET/POST | `/orders` | listar / criar |
| GET/PUT/DELETE | `/orders/{id}` | detalhe / editar / cancelar |
| PATCH | `/orders/{id}/status` | mudar status |
| GET | `/couriers` · `/couriers/online` | motoboys |
| POST | `/couriers/{id}/location` | atualizar posição |
| POST | `/deliveries/dispatch` | despacho manual/auto |
| GET | `/deliveries/{id}/track` | rastreamento + ETA |
| POST | `/deliveries/optimize-route` | roteirização multi-ponto |
| GET/PUT | `/company` | whitelabel (tema, logo, taxas) |

**Exemplo:**
```bash
TOKEN=$(curl -s https://publishdev.com.br/publishgo/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@publishgo.com.br","password":"publishgo"}' | jq -r .data.access_token)

curl https://publishdev.com.br/publishgo/api/dashboard/summary -H "Authorization: Bearer $TOKEN"
```

---

## 🗂️ Estrutura

```
publishgo/
├── public/            # webroot (front controller + painel + PWA)
│   ├── index.php      # bootstrap da API
│   ├── app/           # login, dashboard, orders, deliveries
│   ├── assets/        # css, js (app/realtime/map), icons
│   ├── manifest.json  sw.js  offline.html
├── src/
│   ├── Core/          # App, Router, Request, Response, Database, Jwt, Validator, Migration, Env
│   ├── Middleware/    # Auth, RateLimit, Cors, Csrf
│   ├── Models/        # Company, User, Courier, Order, Delivery, ...
│   ├── Controllers/   # Auth, Dashboard, Order, Courier, Delivery, Company
│   └── Services/      # Dispatch, Geo, Realtime
├── routes/api.php
├── database/migrations/  database/seed.php
├── bin/               # migrate, ws-server, simulate, make-icons
├── deploy/            # systemd units
└── docker-compose.yml docker/nginx.conf
```

---

## 🗺️ Roadmap (próximas fases)
App do motoboy (cadastro/selfie, aceitar corrida, GPS contínuo, ganhos, saque, ranking, chat, assinatura) · Painel admin global · Financeiro completo (extrato, repasses, export PDF/Excel) · Integração iFood/WhatsApp · Push real (VAPID) · Impressão térmica / QRCode · Mapa de calor e relatórios avançados.

A base desta fase já deixa modelos, tabelas e hooks prontos para esses módulos.

---

## 👨‍💻 Desenvolvedor

Sistema **desenvolvido sob encomenda** por **Alex Junior (alequizao)** — Analista e
Desenvolvedor de Sistemas em Maceió, Alagoas, Brasil. Programador na **Publish Digital**.

- **E-mail:** alequizao.dev@gmail.com
- **WhatsApp:** [(82) 98871-7072](https://wa.me/5582988717072)
- **Instagram:** [@alequizao](https://instagram.com/alequizao)
- **GitHub:** [@alequizao](https://github.com/alequizao) · [perfil completo](https://github.com/alequizao/alequizao)
- **Site:** [alequizao.com](https://alequizao.com)

---

© Código proprietário, desenvolvido sob encomenda.
