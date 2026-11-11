import { router } from '@inertiajs/react';
import { useState } from 'react';

function formatScanAt(value) {
    if (!value) {
        return null;
    }
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) {
        return String(value);
    }
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function StaleDeadBanner({ hint, canArchive = false }) {
    const [busy, setBusy] = useState(false);
    const count = Number(hint?.count ?? 0);
    if (!canArchive || !count || count < 1) {
        return null;
    }

    const scanLabel = formatScanAt(hint?.last_scan_at);

    const onArchiveAll = () => {
        const ok = window.confirm(
            `Заархівувати всі ${count} оферів брендів без ліда 21 день, де останній GSC старший за 21 день?\nЗнімемо з Search Console, origin і Cloudflare. Домен лишиться в Dynadot, NS скинемо на parking.`,
        );
        if (!ok) {
            return;
        }
        setBusy(true);
        router.post(route('offers.stale-dead.archive'), {}, {
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="stale-dead-banner" role="status">
            <div className="stale-dead-banner__text">
                <strong>{count}</strong>
                {' '}
                оферів брендів без ліда 21 день, останній GSC старше 21 дня.
                {scanLabel ? (
                    <span className="field-hint" style={{ display: 'inline', marginLeft: '0.5rem' }}>
                        Скан о 10:00: {scanLabel}
                    </span>
                ) : null}
            </div>
            {canArchive ? (
            <button
                type="button"
                className="btn btn-ghost btn-sm"
                disabled={busy}
                onClick={onArchiveAll}
            >
                {busy ? 'Архівація…' : 'Заархівувати всі'}
            </button>
            ) : null}
        </div>
    );
}
