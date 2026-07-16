# API Roadmap — Headless JSON API for phpBB

Long-term plan for exposing phpBB functionality to a custom frontend application while keeping the existing `comunidad/portal` extension and the phpBB ACP intact.

## Motivation

phpBB 3.3 ships without a first-party REST API. The user-facing layer is rendered server-side and tightly coupled to the template system, which makes it hard to ship a custom UI (SPA, mobile, integrations) without forking or scraping. This project has reached the point where that constraint is binding: there is a custom AI-driven portal extension in production, and the next step is a fully decoupled frontend.

The plan adds a dedicated `comunidad/api` extension that exposes phpBB's user-facing functionality over JSON, while keeping the phpBB ACP, the existing `comunidad/portal` extension (with its AI/assistant features), and the dockerized MariaDB deployment untouched.

Three constraints shape the plan:

1. **Reuse what phpBB already does well.** Sessions, ACL, the `submit_post` core flow, the `phpbb_bookmarks` / `phpbb_watch` / `phpbb_notifications` tables — none of this needs to be reinvented. The extension is a thin HTTP layer over the existing core, not a parallel forum engine.
2. **Pick the simplest auth that works.** Same-origin only, native phpBB session cookie plus a `X-CSRF-Token` header on writes. This avoids a new tokens table, avoids CORS plumbing, and means anyone already comfortable with phpBB's session model has nothing new to learn. Bearer tokens (as in EBTURK/headless) were considered and rejected for the MVP — they add value when crossing origins or supporting headless clients, neither of which is a current need.
3. **Ship in vertical slices, not horizontal layers.** Auth first (it unlocks ACL-aware reads), then reads, then writes, then engagement, then the heavy features. Each phase ends in a usable artifact and a PR-sized surface that can be reviewed and rolled back independently.

A custom build was chosen over adopting an existing extension because none of the surveyed options (EBTURK/headless, danieltj/Rest API, senky/phpbb-ext-api) target phpBB 3.3.x in a maintained, drop-in way. The headless extension was the most useful reference: its service/middleware separation and `auth_enforcer` allowlist pattern are imported as design ideas, not as code.

## Vision

A SPA can replace the user-facing forum UI (reading, posting, engagement, search) by consuming a documented JSON API. The ACP, the existing portal/AI extension, and the dockerized deployment remain untouched. The API is an independent extension (`comunidad/api`) under the same `comunidad/` vendor, with its own lifecycle.

## Architectural decisions (locked in)

| Decision             | Choice                                                                                                                                                                                                                                                                                                                                      | Why                                                                                                                                                             |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Same-origin only     | SPA and phpBB on the same host                                                                                                                                                                                                                                                                                                              | Simplest auth (cookie-based), no CORS layer needed for now                                                                                                      |
| Auth mechanism       | phpBB native session + `X-CSRF-Token` header on writes                                                                                                                                                                                                                                                                                      | Reuses `phpbb_sessions`, `check_form_key`, no new tables                                                                                                        |
| Response shape       | `{ success, data, error, meta }`                                                                                                                                                                                                                                                                                                            | Consistent, predictable, easy to consume                                                                                                                        |
| Versioning           | `/api/v1/*` namespace                                                                                                                                                                                                                                                                                                                       | Standard, room to evolve without breaking clients                                                                                                               |
| ACL enforcement      | Reuse phpBB's `$auth->acl()` after session resolution                                                                                                                                                                                                                                                                                       | Single source of truth for permissions                                                                                                                          |
| Documentation        | Static OpenAPI YAML at `/api/v1/docs`, served from the extension                                                                                                                                                                                                                                                                            | Cheaper to maintain than a dynamic generator; revisit if drift becomes a problem                                                                                |
| Extension separation | New extension `comunidad/api`, independent of `comunidad/portal`                                                                                                                                                                                                                                                                            | Independent lifecycle, testing, deployment                                                                                                                      |
| API ↔ Portal scope   | **Strict separation**: `comunidad/api` exposes only user-forum features (auth, forums, topics, posts, engagement, search, PMs, attachments). The `comunidad/portal` extension keeps its `/portal` UI and its AI/assistant endpoints. A SPA consumes the API for the forum; portal/AI features remain in the portal extension's controllers. | Clear ownership, smaller API surface, no cross-extension coupling                                                                                               |
| Test DB (E2E)        | **SQLite** (in-memory or temp file per run)                                                                                                                                                                                                                                                                                                 | Fast, isolated, no docker needed for unit/functional suites. Production stays on MariaDB. Avoid MariaDB-specific SQL in the extension so SQLite remains viable. |

## Phases

### Phase 0 — Foundation (next PR)

- `ext/comunidad/api/` skeleton: `composer.json`, `ext.php`, `config/routing.yml`, `config/services.yml`
- Services: `response_builder`, `auth_service`, `logger`
- Middleware: `auth_guard` (session-based), `auth_enforcer` (public-route allowlist), `rate_limiter`, `exception_handler`, `json_body_listener`
- Helper: `api_controller_trait`
- Controller: `auth` (login, logout, me, password/forgot, password/reset, password/change)
- No DB schema changes
- Functional tests against the local docker stack
- **Exit criteria**: SPA can log a user in, fetch `/auth/me`, log out

### Phase 1 — Read

- Controllers: `forum` (list, show), `topic` (list by forum, show), `post` (list by topic, show)
- Pagination via `meta.page` / `meta.per_page` / `meta.total`
- ACL-aware filtering at the SQL level (not just response-level)
- BBCode → HTML rendering on the server (so the SPA can display without re-implementing the parser)
- **Exit criteria**: SPA can render a forum list, drill into a topic, paginate posts, and respect per-forum permissions

### Phase 2 — Write

- Controllers: `topic` (create, update, delete), `post` (create, update, delete, report, quote)
- Reuse phpBB's `submit_post()` core function
- Form key validation on every state-changing request
- Soft-delete and edit-window behavior inherited from phpBB config
- **Exit criteria**: SPA can post a new topic, reply, edit own posts, soft-delete

### Phase 3 — Engagement

- Controllers: `bookmark`, `subscription`, `notification`
- Reuse phpBB's `phpbb_bookmarks`, `phpbb_watch`, `phpbb_notifications` tables
- Notification unread count for the SPA badge (polling every 30s; SSE later)
- **Exit criteria**: User can bookmark, subscribe, see a notification count, mark notifications read

### Phase 4 — Search & discovery

- `search` controller wrapping phpBB's native search
- `user` controller (profile, posts, topics, online status)
- `meta` controller (forum stats, online users, BBCode preview)
- **Exit criteria**: User can search, view another user's profile, see online users

### Phase 5 — Hard mode

- `message` (private messages: list, show, send, reply, folders)
- `attachment` (upload, delete, signed-URL fetch)
- `poll` (show, vote, results)
- **Exit criteria**: PMs and attachments work from the SPA; poll voting works

### Phase 6 — Post-MVP

- Markdown input (deferred — see open question)
- OpenAPI regeneration tooling (if static YAML drifts)
- Rate-limit tuning (per-user in addition to per-IP)
- Server-Sent Events for live updates (replacing polling)
- Optional: API keys for non-browser clients (bots, mobile)

## Non-goals (out of scope)

- Replacing or modifying the phpBB ACP. Admins continue to use `/adm/`.
- Exposing the AI/portal features (entity extraction, assistant) via this API. The `comunidad/portal` extension keeps those endpoints. If a SPA needs them, they get their own controller in the portal extension or a new bridge.
- Cross-origin support. Same-origin only. CORS can be added later if needed.
- GraphQL. REST + JSON only. Revisit if a SPA genuinely needs it.
- WebSocket. Start with polling for notifications, SSE later, WS only if SSE proves insufficient.

## Testing strategy

- **Recommended test DB**: SQLite. phpBB's `phpbb_functional_test_case` supports it natively, which means no docker, no MariaDB, and a fresh DB per run. Forces the extension to avoid MariaDB-specific SQL (stick to `\phpbb\db\driver\driver_interface` and ANSI-compatible queries).
- **Unit tests** for services (`response_builder`, `auth_service`) — pure logic, easy to cover.
- **Functional / E2E tests** for every controller using `phpbb_functional_test_case` against SQLite. Covers auth, ACL, full request/response cycle.
- **Integration smoke**: a scripted end-to-end (login → list forums → read topic → post reply → logout) against the docker stack with real MariaDB, runnable via `make test-api` or similar. Catches MariaDB-specific issues and docker integration issues that SQLite tests miss.

## References consulted

- Existing `extensions/comunidad/portal/` — for in-repo conventions (namespaces, migration style, ACP integration, AI feature shape).
- `reference/headless/` (EBTURK/headless) — for `response_builder`, `auth_enforcer` allowlist pattern, controller/service/middleware separation. Patterns copied, code not (phpBB 4 alpha + Bearer-token based, not compatible with our 3.3 + session setup).
- phpBB 3.3 development docs at `area51.phpbb.com/docs/dev/master/` for controller/routing/migration conventions.
