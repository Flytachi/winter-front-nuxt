# Winter Front · Nuxt

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-front-nuxt.svg)](https://packagist.org/packages/flytachi/winter-front-nuxt)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-front-nuxt.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-front-nuxt)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Tests](https://github.com/flytachi/winter-front-nuxt/actions/workflows/tests.yml/badge.svg)](https://github.com/flytachi/winter-front-nuxt/actions/workflows/tests.yml)
[![Docs](https://img.shields.io/badge/docs-winterframe.net-blue.svg)](https://winterframe.net/docs/frontend)

A Nuxt frontend in a Winter application, declared in one line. The application's own HTTP
server sends its built files beside the API — no nginx, no proxy, no Node in production
for a single-page app or a pre-rendered site — or forwards to the Nitro server it runs for
SSR; `call run dev` runs `nuxt dev` behind the same address, and `call service docker`
builds it into the image.

```php
$components->frontend(Nuxt::spa('frontend'));
```

📖 **[The frontend in the documentation](https://winterframe.net/docs/frontend)**

---

## Installation

```bash
composer require flytachi/winter-front-nuxt
```

Requires PHP **8.4+** and `flytachi/winter-kernel` **4.6+**. Nuxt **3** or **4** in a folder
of the project.

---

## Quick start

**1. Declare it** in the application — the first argument is the frontend's folder, from
the project root:

```php
use Flytachi\Winter\Front\Nuxt\Nuxt;

protected static function components(Components $components): void
{
    $components->frontend(Nuxt::spa('frontend'));
}
```

**2. Configure Nuxt** — a single-page app:

```ts
// frontend/nuxt.config.ts
export default defineNuxtConfig({
  ssr: false,
  nitro: { compressPublicAssets: true },
})
```

**3. Build it:**

```bash
cd frontend && pnpm install && pnpm run generate
```

`call run` serves it: `http://localhost:8000/users/7` is the app, `/api/...` the
controllers. Until it is built the API works, the frontend is `FAILED` in
`call app status` and a page answers 503; once `.output/public` is there it is up within a
few seconds — no restart.

**4. Put it in the image:**

```bash
call service docker
```

A Node stage of its own builds it; the image gets `.output/public` only.

---

## Modes

| | Factory | `nuxt.config.ts` | Built with | In production |
| --- | --- | --- | --- | --- |
| Single-page app | `Nuxt::spa()` | `ssr: false` | `generate` | files only; an unknown page gets `200.html` |
| Pre-rendered site | `Nuxt::ssg()` | no `ssr` key; `prerender` | `generate` | files only; an unknown page is 404 |
| Rendered on request (SSR) | `Nuxt::ssr()` | no `ssr` key | `build` | the Nitro server, run by the application |

```ts
// Nuxt::ssg() — every page built in advance (SEO), the links followed from /
export default defineNuxtConfig({
  nitro: { compressPublicAssets: true, prerender: { crawlLinks: true, routes: ['/'] } },
})
```

### SSR

`Nuxt::ssr('frontend')` runs `node frontend/.output/server/index.mjs` under the supervisor,
on a loopback port of its own; what no controller took — any method, Nitro's own server
routes too — is forwarded to it, and `.output/public` is sent by the HTTP server itself.
While Node starts or after it crashed, a page answers `503` with `Retry-After`; the
supervisor restarts it.

During SSR `useFetch('/api/…')` is answered by Nitro, not by the controllers. Hand it on
with a server route — it reads `WINTER_BACKEND` when it runs: a loopback address with the
controllers the frontend's port shows (a prefix closed there stays closed), where nothing
comes back to Node:

```ts
// frontend/server/routes/api/[...].ts
export default defineEventHandler((event) =>
  proxyRequest(event, process.env.WINTER_BACKEND + event.path))
```

A `routeRules` proxy does not do: Nuxt writes its target into the build, before the
address is known.

Node is in the image; SSR needs more memory and CPU than files — Node shares the cores
with PHP. Cache pages where you can (`routeRules` with `swr` or `isr`). An answer reaches
the client once Node has finished it: a stream from a server route (server-sent events)
is not passed on as it comes.

---

## Development: `call run dev`

`call run dev` runs `nuxt dev` in every mode, and the application's own address serves it —
pages, `/_nuxt/…`, the live reload (HMR) — beside the controllers, with the same
`path:`/`port:`/`routes:` as in production. Its lines go to the log under the frontend's
name.

Before it starts, the dependencies are installed when the lock file changed since the last
install here (`pnpm-lock.yaml changed since the last install: pnpm install …`) — and not
otherwise. pnpm keeps its store in `node_modules`.

In Docker the image needs Node, and `node_modules` a volume of its own — the host's are
built for the host:

```yaml
# docker-compose.yml — call service docker prints it for your folders
services:
  app:
    build:
      args:
        DEV: ${DEV:-false}
    volumes:
      - ./:/app/
      - frontend-node-modules:/app/frontend/node_modules
volumes:
  frontend-node-modules:
```

`DEV=true docker compose up --build` builds an image with Node, npm and corepack;
`docker compose up --build` without it, the production one — with no Node for a
single-page app or a pre-rendered site. A `.php` change restarts the application and
`nuxt dev` with it (a couple of seconds); a `.vue` change is the live reload only.

---

## Where it answers

```php
Nuxt::spa('frontend');                                          // at /, beside the API
Nuxt::spa('admin', path: '/admin');                             // under a path
Nuxt::spa('admin', port: 9091, routes: '/admin');               // a port of its own
Nuxt::spa('admin', port: 9091, routes: ['/admin', '/auth']);
Nuxt::spa('admin', port: 9091, routes: '/admin', exclusive: false);
```

- **A controller always wins.** The frontend gets a GET or HEAD no route took. A `POST` to
  a typo is the API's 404, never the page; so is a browser's `fetch()` of a path no route
  has.
- **`path:`** — the app must know it too: `app: { baseURL: '/admin/' }`. Nuxt then puts
  that path in front of `useFetch('/api/...')` as well; for the API at the root say
  `useFetch('/api/users', { baseURL: '/' })`.
- **`port:` with `routes:`** — on its port the frontend answers everything but its
  `routes`, which reach the controllers; those prefixes are closed on the HTTP port, so an
  admin API is not reachable from outside by accident. `exclusive: false` keeps them open
  there too.
- **`name:`** — how logs and `call app status` name it; by default the folder's name.

---

## The package manager

Told by the lock file in the folder; the image's build stage runs:

| Lock file | Install | Build |
| --- | --- | --- |
| `pnpm-lock.yaml` | `pnpm install --frozen-lockfile` | `pnpm run generate` |
| `package-lock.json` | `npm ci` | `npm run generate` |
| `yarn.lock` | `yarn install --frozen-lockfile` (`--immutable` for yarn 2+) | `yarn run generate` |
| `bun.lock` | `bun install --frozen-lockfile` | `bun run generate` |

pnpm and yarn come through corepack, at the version `packageManager` in `package.json`
names. Several lock files: the one `packageManager` names, or say it:

```php
Nuxt::spa('frontend', packageManager: PackageManager::Npm);
```

pnpm 10+ runs no install scripts unless they are allowed — Nuxt needs esbuild's:

```yaml
# frontend/pnpm-workspace.yaml — pnpm 11
allowBuilds:
  esbuild: true
# pnpm 10: onlyBuiltDependencies: [esbuild]
```

The install step gets `package.json`, the lock file, `.npmrc`, `pnpm-workspace.yaml` and
`.yarnrc.yml` — not folders. An install that needs more (`patches/` of pnpm's
`patchedDependencies`, yarn's `.yarn/releases`, `patch-package` in `postinstall`) fails
there: for now such a build is written into the Dockerfile by hand.

The image's `.dockerignore` must leave out `**/node_modules`, `**/.nuxt` and `**/.output`:
`call service docker` says so when it does not.

---

## What it says at start

`call run` logs, as warnings, what does not match the mode:

```
the frontend "frontend": nuxt.config.ts has no ssr: false — generate prerenders every page; set it for a single-page app, or use Nuxt::ssg()
the frontend "admin": app.baseURL is not "/admin/" — the pages ask for /_nuxt/ instead of /admin/_nuxt/; set app: { baseURL: '/admin/' }
the frontend "frontend": not built yet: run "pnpm run generate" in frontend/
```

Only literal values are read: `ssr: process.env.SPA !== '1'` is taken as it is.
`NUXT_APP_BASE_URL` in the environment counts as `baseURL`. For `Nuxt::ssr()`: nothing in
the folder naming `WINTER_BACKEND`. Under `call run dev` nothing is said about the build. A
warning never stops the start.

---

## Testing

```bash
composer test
```

## License

MIT — see [LICENSE](LICENSE).
