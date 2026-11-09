# Offerra — working agreements

## Deploy flow: local → git → prod

Always in this order. Never skip a step, never upload files to the panel directly.

When asked to implement or fix something, do this immediately — do not wait for a separate «пуш і деплой».

1. Write and verify the change locally (`npm run build`, `php -l`).
2. Commit, then push to GitHub.
3. Deploy by pulling on the panel: `node scripts/deploy.cjs`.

The panel at `/var/www/offerra` is a git checkout that mirrors `origin/main`.
`scripts/deploy.cjs` fetches, hard-resets to `origin/main`, installs dependencies
when the lockfiles changed, migrates, regenerates `ziggy.js`, rebuilds assets and
restarts the queue workers. It refuses to run when the panel checkout has local
edits, so nothing gets silently discarded.

The point of this order is recoverability: git is the single source of truth, so a
dead server can be replaced from `origin/main` alone. Uploading files straight to
prod breaks that guarantee — it creates state that exists nowhere else.

To convert a fresh server into a checkout: `node scripts/panel-git-init.cjs`
(inspect), then `--align` to sync it.

Deploy needs `PANEL_HOST` and `PANEL_PASS` in the environment.

## Never commit credentials

GitHub push protection rejects the entire push if a secret appears in any commit,
so one bad file blocks all deploys.

Real provider tokens have leaked into one-off scripts before
(`scripts/map-ego-*`, `scripts/send-lead-*`). Those patterns plus `scripts/_*`
runners are gitignored. Read credentials from the environment or from
`user_settings`, never from a literal.

## What git does *not* cover

Restoring a server from `origin/main` alone is not enough. These live only on the
panel and need their own backups:

- `.env` — app key, DB credentials, provider keys
- the PostgreSQL database — offers, users, settings, stats

`offers/` on the panel is empty: `OFFERRA_PURGE_LOCAL_AFTER_DEPLOY=true` deletes
each lander once it reaches its origin. The live files sit on the origin servers
and are re-deployable from the DB, but slowly.

Nightly encrypted restic dump (Postgres + `.env` + `storage/app`) goes to a
separate backup VPS over SFTP. The restic password lives in
`/root/offerra-backup.env` on the panel and in a password manager — never in
git. The backup box only stores ciphertext. Restore: `scripts/panel-backup-restore.sh`.

## Origin servers

Never SSH/SFTP from this machine to an origin IP — not even a new empty VPS.
Connect only to the panel (`PANEL_HOST`). From there, jump with `OriginSsh` or
`sshpass`. Origins must see the panel address, not the agent PC.

Servers belong to the admin, not to user settings. `OriginPool` assigns each new
offer to the `pool` server with the fewest other offers of the same brand, then
the least total load, so a multi-domain funnel is not stacked on one IP; `spare`
servers stay warm but idle. An offer is pinned to its server via
`infra_meta.deploy_host` and must not move unless an admin evacuates it
(`OriginEvacuationService`).

Check pool health with `php scripts/audit-origin-pool.php report` on the panel.
`unbound > 0` means offers the pool cannot see — they would be reassigned and
leave orphaned files behind.
