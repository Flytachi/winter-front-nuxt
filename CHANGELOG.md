# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — unreleased

### Added

- `Nuxt` — a Nuxt frontend of a Winter application (`Components::frontend()`), served by
  the application's own HTTP server after its routes:
  - `Nuxt::spa()` — a single-page app (`ssr: false`): `generate` → `.output/public`, its
    `200.html` for every route of the app;
  - `Nuxt::ssg()` — a pre-rendered site: a page of its own or 404;
  - where it answers: `path:`, a port of its own with `routes:` and `exclusive:`; `name:`,
    `critical:`, `overlap:`; `/_nuxt` cached for good.
- `PackageManager` — pnpm, npm, yarn or bun, told by the lock file (several: the one
  `packageManager` in `package.json` names) or given; the build stage `call service docker`
  writes installs with it (`--frozen-lockfile`, `npm ci`) and runs `generate`. The
  production image has no Node.
- Hints at `call run`: no `ssr: false` for a single-page app, `ssr: false` for a
  pre-rendered site, `app.baseURL` other than `path:`, not built yet, several lock files or
  none, no folder or no `nuxt.config`. Only literal values are argued with. A build with
  no project beside it (the image keeps the build only) is not argued with.
- `call run dev` runs `nuxt dev` in either mode (`bin/nuxt-dev`), through the application's
  own address, its live reload (HMR) included: Node checked (in Docker: rebuild with
  `DEV=true`), the dependencies installed when the lock file changed since the last install
  (and said why), pnpm's store kept in `node_modules`, then `nuxt dev` in its place on the
  port the kernel gave. No "not built yet" under `call run dev`. Rendering on every request
  (SSR) is not run by the application: a Node service of its own, beside it.
- `NuxtOutput` — the lines of `nuxt dev` and Nitro in the application's log: colours left
  out, errors and warnings at their level, stack frames joined to their line.
- The image: Node, npm and corepack only in a development build (`DEV=true`).
