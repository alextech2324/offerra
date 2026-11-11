<?php

namespace App\Console\Commands;

use App\Models\OriginServer;
use App\Support\OriginSsh;
use Illuminate\Console\Command;
use Throwable;

class FixOriginLangNginx extends Command
{
    protected $signature = 'origin:fix-lang-nginx
        {--dry-run : Only report whether the origin still has the broken numbered-capture rewrite}';

    protected $description = 'Fix origin nginx /{lang}/ rewrite so Vespera language URLs do not 404';

    public function handle(OriginSsh $ssh): int
    {
        $dry = (bool) $this->option('dry-run');
        $failed = 0;
        $patched = 0;
        $skipped = 0;

        foreach (OriginServer::query()->where('is_active', true)->orderBy('id')->get() as $server) {
            try {
                $out = $ssh->exec(
                    (string) $server->host,
                    (int) ($server->port ?: 22),
                    (string) $server->username,
                    (string) $server->password,
                    $dry ? $this->remoteProbe() : $this->remotePatch(),
                    45,
                );
            } catch (Throwable $e) {
                $this->error($server->host.': '.$e->getMessage());
                $failed++;

                continue;
            }

            $line = trim($out);
            $this->line($server->host.' '.$line);

            if (str_contains($line, 'patched')) {
                $patched++;
            } else {
                $skipped++;
            }
        }

        $this->info("patched={$patched} skipped={$skipped} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function remoteProbe(): string
    {
        return <<<'SH'
python3 - <<'PY'
from pathlib import Path
p = Path("/etc/nginx/conf.d/offers.conf")
text = p.read_text() if p.exists() else ""
if "offerra_lang_index" in text:
    print("already-patched")
elif "langs/$1/index.php" in text:
    print("needs-patch")
else:
    print("unknown-config")
PY
SH;
    }

    private function remotePatch(): string
    {
        return <<<'SH'
python3 - <<'PY'
from pathlib import Path
import subprocess
import sys

p = Path("/etc/nginx/conf.d/offers.conf")
if not p.exists():
    print("missing-offers-conf")
    sys.exit(1)

text = p.read_text()
if "offerra_lang_index" in text:
    print("already-patched")
    sys.exit(0)

new = """    location ~ ^/(?<offerra_lang>[a-z][a-z])/?$ {
        set $offerra_lang_index /langs/$offerra_lang/index.php;
        if (-f $offer_root$offerra_lang_index) {
            rewrite ^ $offerra_lang_index last;
        }
        rewrite ^ /index.php last;
    }

    location ~ ^/(?<offerra_lang>[a-z][a-z])/(?<offerra_php>.+\\.php)$ {
        set $offerra_lang_php /langs/$offerra_lang/$offerra_php;
        if (-f $offer_root$offerra_lang_php) {
            rewrite ^ $offerra_lang_php last;
        }
        rewrite ^ /index.php last;
    }
"""

start = text.find("    location ~ ^/([a-z][a-z])/?$ {")
if start < 0:
    print("no-match")
    sys.exit(1)
second = text.find("    location ~ ^/([a-z][a-z])/", start + 10)
if second < 0:
    print("no-second-location")
    sys.exit(1)
end = text.find("\n    }\n", second)
if end < 0:
    print("no-end")
    sys.exit(1)
end = end + len("\n    }\n")
updated = text[:start] + new + text[end:]

bak = Path("/etc/nginx/conf.d/offers.conf.bak-lang")
bak.write_text(text)
p.write_text(updated)
copy = Path("/etc/nginx/sites-available/offers")
if copy.exists():
    copy.write_text(updated)

chk = subprocess.run(["nginx", "-t"], capture_output=True, text=True)
if chk.returncode != 0:
    p.write_text(text)
    print("nginx-t-failed")
    sys.stderr.write(chk.stderr or chk.stdout or "")
    sys.exit(1)

subprocess.run(["systemctl", "reload", "nginx"], check=True)
print("patched")
PY
SH;
    }
}
