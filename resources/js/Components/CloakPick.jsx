import { Link } from '@inertiajs/react';

export default function CloakPick({
    enabled = false,
    whiteId = '',
    whites = [],
    onEnabledChange,
    onWhiteChange,
    namePrefix = 'cloak',
    hint = null,
}) {
    const on = Boolean(enabled);

    return (
        <div className="cloak-pick">
            <div className="cloak-pick__toggle" role="radiogroup" aria-label="Кло">
                <label className={`cloak-pick__choice${!on ? ' is-on' : ''}`}>
                    <input
                        type="radio"
                        name={`${namePrefix}_enabled`}
                        checked={!on}
                        onChange={() => onEnabledChange(false)}
                    />
                    <strong>Без кло</strong>
                    <span>Ленд відкривається всім як зараз</span>
                </label>
                <label className={`cloak-pick__choice${on ? ' is-on' : ''}`}>
                    <input
                        type="radio"
                        name={`${namePrefix}_enabled`}
                        checked={on}
                        onChange={() => onEnabledChange(true)}
                    />
                    <strong>Додати кло</strong>
                    <span>Googlebot і своє GEO бачать офер; решта — вайт. CF і проксі однаково</span>
                </label>
            </div>

            {on && (
                whites.length === 0 ? (
                    <p className="field-hint field-hint--warn">
                        Немає вайтів.{' '}
                        <Link href={route('landings.index')}>Завантаж ZIP у Лендінгах</Link>.
                    </p>
                ) : (
                    <div className="cloak-pick__whites" role="radiogroup" aria-label="Вайт">
                        <p className="cloak-pick__whites-label">Вайт</p>
                        {whites.map((landing) => {
                            const selected = String(whiteId) === String(landing.id);

                            return (
                                <label
                                    key={landing.id}
                                    className={`cloak-pick__white${selected ? ' is-on' : ''}`}
                                >
                                    <input
                                        type="radio"
                                        name={`${namePrefix}_white`}
                                        checked={selected}
                                        onChange={() => onWhiteChange(landing.id)}
                                    />
                                    <span className="cloak-pick__white-name">{landing.name}</span>
                                    {landing.is_demo ? (
                                        <span className="cloak-pick__badge">тест</span>
                                    ) : null}
                                </label>
                            );
                        })}
                    </div>
                )
            )}

            {on ? (
                <ul className="cloak-pick__rules">
                    <li><strong>Googlebot</strong> — IP з офіційного <code>googlebot.json</code>, не User-Agent</li>
                    <li><strong>GEO офера</strong> — <code>CF-IPCountry</code> з кроку Ринок. Cloudflare і проксі ставлять той самий заголовок</li>
                    <li><strong>Решта</strong> — вайт. Панель, sitemap і GSC-файл завжди проходять. Край офера кло не чіпає</li>
                </ul>
            ) : null}

            {hint ? <p className="field-hint cloak-pick__hint">{hint}</p> : null}
        </div>
    );
}
