/**
 * Deploy the panel by pulling from git. The panel checkout mirrors origin/main;
 * nothing is uploaded from the local machine.
 *
 *   $env:PANEL_HOST="93.95.228.175"; $env:PANEL_PASS="..."
 *   node scripts/deploy.cjs
 *   node scripts/deploy.cjs --branch=main --force
 *
 * --force   discard local edits in the panel checkout (default: abort on them)
 * --branch  branch to deploy (default: main)
 * --skip-build / --skip-deps   skip the asset build / dependency install
 * --exec    extra command to run in the panel checkout after deploy
 */
const { Client } = require("ssh2");

const HOST = process.env.PANEL_HOST;
const PASS = process.env.PANEL_PASS;
const USER = process.env.PANEL_USER || "root";
const PORT = Number(process.env.PANEL_PORT || 22);
const REMOTE = process.env.PANEL_PATH || "/var/www/offerra";

const args = process.argv.slice(2);
const flag = (name) => args.includes(`--${name}`);
const opt = (name, fallback) => {
  const hit = args.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.split("=").slice(1).join("=") : fallback;
};

const BRANCH = opt("branch", "main");
const FORCE = flag("force");
const SKIP_BUILD = flag("skip-build");
const SKIP_DEPS = flag("skip-deps");
const EXEC = opt("exec", "");

if (!HOST || !PASS) {
  console.error("PANEL_HOST / PANEL_PASS required");
  process.exit(1);
}

function exec(conn, cmd, { timeoutMs = 300000, allowFail = false } = {}) {
  return new Promise((resolve, reject) => {
    conn.exec(cmd, { pty: true }, (err, stream) => {
      if (err) return reject(err);
      let out = "";
      const t = setTimeout(() => reject(new Error("timeout: " + cmd.slice(0, 90))), timeoutMs);
      stream.on("data", (d) => {
        out += d;
        process.stdout.write(d);
      });
      stream.stderr.on("data", (d) => {
        out += d;
        process.stderr.write(d);
      });
      stream.on("close", (code) => {
        clearTimeout(t);
        if (code !== 0 && !allowFail) {
          reject(new Error(`exit ${code} for: ${cmd.slice(0, 90)}\n${out.slice(-1500)}`));
        } else {
          resolve({ code, out });
        }
      });
    });
  });
}

function step(label) {
  console.log(`\n=== ${label} ===`);
}

async function main() {
  const conn = new Client();
  await new Promise((r, j) =>
    conn.on("ready", r).on("error", j).connect({
      host: HOST,
      port: PORT,
      username: USER,
      password: PASS,
      readyTimeout: 30000,
    }),
  );

  await exec(conn, `git config --global --add safe.directory ${REMOTE}`, {
    timeoutMs: 30000,
    allowFail: true,
  });

  step("checkout state");
  const isRepo = await exec(conn, `test -d ${REMOTE}/.git && echo yes || echo no`, { timeoutMs: 30000 });
  if (!isRepo.out.includes("yes")) {
    throw new Error(
      `${REMOTE} is not a git checkout. Run scripts/panel-git-init.cjs once to convert it.`,
    );
  }

  const dirty = await exec(conn, `cd ${REMOTE} && git status --porcelain`, { timeoutMs: 60000 });
  const dirtyLines = dirty.out
    .split("\n")
    .map((l) => l.replace(/\x1b\[[0-9;]*[A-Za-z]/g, "").trim())
    .filter(Boolean)
    .filter((l) => !l.startsWith("??"))
    .filter((l) => !l.endsWith("resources/js/ziggy.js"));
  if (dirtyLines.length > 0 && !FORCE) {
    throw new Error(
      `Panel checkout has ${dirtyLines.length} tracked change(s). Commit them to git or rerun with --force to discard.\n` +
        dirtyLines.slice(0, 20).join("\n"),
    );
  }

  step(`fetch + reset to origin/${BRANCH}`);
  const before = await exec(conn, `cd ${REMOTE} && git rev-parse HEAD`, { timeoutMs: 30000 });
  await exec(conn, `cd ${REMOTE} && git fetch --prune origin`, { timeoutMs: 180000 });
  await exec(conn, `cd ${REMOTE} && git reset --hard origin/${BRANCH}`, { timeoutMs: 120000 });
  const after = await exec(conn, `cd ${REMOTE} && git rev-parse HEAD`, { timeoutMs: 30000 });

  // git reset / artisan as root can leave offers/ and GSC files owned by root;
  // queue workers are www-data and then fail with EACCES on copy().
  await exec(
    conn,
    [
      `chown www-data:www-data ${REMOTE}/offers ${REMOTE}/bootstrap/cache ${REMOTE}/storage ${REMOTE}/storage/app ${REMOTE}/storage/logs ${REMOTE}/storage/framework`,
      `chmod 775 ${REMOTE}/offers ${REMOTE}/bootstrap/cache ${REMOTE}/storage ${REMOTE}/storage/app ${REMOTE}/storage/logs ${REMOTE}/storage/framework`,
      `find ${REMOTE}/storage/app/offer-verification ${REMOTE}/storage/app/user-verification -user root -exec chown www-data:www-data {} +`,
    ].join(" ; "),
    { timeoutMs: 120000, allowFail: true },
  );

  const from = before.out.trim().slice(0, 40);
  const to = after.out.trim().slice(0, 40);

  step("what changed");
  await exec(conn, `cd ${REMOTE} && git --no-pager log --oneline -5`, { timeoutMs: 30000 });
  const changed = await exec(
    conn,
    `cd ${REMOTE} && git --no-pager diff --name-only ${from} ${to} 2>/dev/null || echo ALL`,
    { timeoutMs: 60000, allowFail: true },
  );
  const touched = changed.out;
  const touches = (needle) => touched.includes("ALL") || touched.includes(needle);

  if (!SKIP_DEPS && touches("composer.lock")) {
    step("composer install");
    await exec(
      conn,
      `cd ${REMOTE} && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction`,
      { timeoutMs: 600000 },
    );
  }

  if (!SKIP_DEPS && touches("package-lock.json")) {
    step("npm ci");
    await exec(conn, `cd ${REMOTE} && npm ci`, { timeoutMs: 900000 });
  }

  step("migrate");
  await exec(conn, `cd ${REMOTE} && php artisan migrate --force`, { timeoutMs: 300000 });

  step("clear caches");
  await exec(
    conn,
    `cd ${REMOTE} && php artisan route:clear && php artisan config:clear && php artisan cache:clear && php artisan view:clear`,
    { timeoutMs: 120000 },
  );

  if (!SKIP_BUILD) {
    // ziggy.js is generated from the route table, so routes changes need it rebuilt
    // before vite runs or route() calls in the browser break.
    step("ziggy + asset build");
    await exec(conn, `cd ${REMOTE} && php artisan ziggy:generate resources/js/ziggy.js`, {
      timeoutMs: 120000,
    });
    await exec(conn, `cd ${REMOTE} && npm run build`, { timeoutMs: 900000 });
  }

  step("restart queue workers");
  // Default workers must drain `gsc` before mass CRM/inspect jobs on `default`.
  // DNS recheck and origin purge have their own queues so they cannot stall create.
  await exec(
    conn,
    `python3 - <<'PY'
from pathlib import Path

p = Path("/etc/supervisor/conf.d/offerra.conf")
t = p.read_text()
old = "--queue=default "
new = "--queue=gsc,default "
if new in t:
    print("supervisor: gsc,default already set")
elif old in t:
    p.write_text(t.replace(old, new))
    print("supervisor: default workers now listen gsc,default")
else:
    print("supervisor: default queue line not found")

dns = Path("/etc/supervisor/conf.d/offerra-dns.conf")
dns_body = """[program:offerra-dns-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/offerra/artisan queue:work database --queue=dns --sleep=1 --tries=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=6
redirect_stderr=true
stdout_logfile=/var/www/offerra/storage/logs/dns-worker.log
stopwaitsecs=3600
"""
if not dns.exists() or "--queue=dns" not in dns.read_text() or "numprocs=6" not in dns.read_text():
    dns.write_text(dns_body)
    print("supervisor: wrote offerra-dns.conf")
else:
    print("supervisor: dns workers already configured")

purge = Path("/etc/supervisor/conf.d/offerra-purge.conf")
if not purge.exists() or "--queue=purge" not in purge.read_text():
    purge.write_text("""[program:offerra-purge-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/offerra/artisan queue:work database --queue=purge --sleep=1 --tries=2 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/offerra/storage/logs/purge-worker.log
stopwaitsecs=3600
""")
    print("supervisor: wrote offerra-purge.conf")
else:
    print("supervisor: purge workers already configured")
PY
supervisorctl reread
supervisorctl update
if ! supervisorctl status offerra-dns-worker:* 2>/dev/null | grep -q offerra-dns-worker_05; then
  supervisorctl stop offerra-dns-worker:* || true
  supervisorctl remove offerra-dns-worker || true
  supervisorctl reread
  supervisorctl add offerra-dns-worker
  supervisorctl start offerra-dns-worker:* || true
fi`,
    { timeoutMs: 180000, allowFail: true },
  );
  await exec(
    conn,
    [
      "supervisorctl stop offerra-deploy-worker:* || true",
      "cd /var/www/offerra && php <<'PHP'",
      "<?php",
      "require 'vendor/autoload.php';",
      "$app = require 'bootstrap/app.php';",
      "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();",
      "$a = Illuminate\\Support\\Facades\\DB::table('jobs')->whereIn('queue', ['default', 'deploy'])->whereNull('reserved_at')->where(function ($q) { $q->where('payload', 'like', '%RecheckInfrastructureDnsJob%')->orWhere('payload', 'like', '%RebindOfferDnsJob%')->orWhere('payload', 'like', '%SwitchOfferEdgeJob%'); })->update(['queue' => 'dns']);",
      "$b = Illuminate\\Support\\Facades\\DB::table('jobs')->where('queue', 'deploy')->whereNull('reserved_at')->where('payload', 'like', '%PurgeOfferFromOriginJob%')->update(['queue' => 'purge']);",
      'echo "moved recheck={$a} purge={$b}\\n";',
      "PHP",
      "supervisorctl restart offerra-deploy-worker:*",
      "supervisorctl restart offerra-worker:*",
      "supervisorctl restart offerra-dns-worker:*",
      "supervisorctl restart offerra-purge-worker:*",
    ].join("\n"),
    { timeoutMs: 180000, allowFail: true },
  );
  await exec(conn, `supervisorctl status`, { timeoutMs: 60000, allowFail: true });

  step("smoke check");
  await exec(conn, `cd ${REMOTE} && php scripts/audit-origin-pool.php report | head -n 20`, {
    timeoutMs: 300000,
    allowFail: true,
  });

  if (EXEC) {
    step("exec");
    await exec(conn, `cd ${REMOTE} && ${EXEC}`, { timeoutMs: 300000 });
  }

  conn.end();
  console.log(`\nDEPLOYED ${from.slice(0, 8)} -> ${to.slice(0, 8)} (origin/${BRANCH})`);
}

main().catch((e) => {
  console.error("\nDEPLOY FAILED:", e.message);
  process.exit(1);
});
