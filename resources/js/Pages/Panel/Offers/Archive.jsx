import PanelLayout from '@/Layouts/PanelLayout';
import OfferRestoreModal from '@/Components/OfferRestoreModal';
import StaleDeadBanner from '@/Components/StaleDeadBanner';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

function TrashIcon() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            width="16"
            height="16"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M3 6h18" />
            <path d="M8 6V4h8v2" />
            <path d="M19 6l-1 14H6L5 6" />
            <path d="M10 11v6" />
            <path d="M14 11v6" />
        </svg>
    );
}

function formatArchivedAt(value) {
    if (!value) {
        return '—';
    }

    const [date, time] = String(value).split(' ');
    if (!date) {
        return String(value);
    }

    const [year, month, day] = date.split('-');
    if (!year || !month || !day) {
        return String(value);
    }

    return `${day}.${month}.${year}${time ? ` ${time}` : ''}`;
}

function formatCreatedDate(value) {
    if (!value) {
        return '';
    }
    const [year, month, day] = String(value).split('-');
    if (!year || !month || !day) {
        return String(value);
    }
    return `${day}.${month}.${year}`;
}

function buildQueryParams(filters, overrides = {}) {
    const merged = { ...filters, ...overrides };
    const params = {};

    Object.entries(merged).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) {
            return;
        }
        if (key === 'page' && Number(value) <= 1) {
            return;
        }
        params[key] = value;
    });

    return params;
}

function buildActiveFilterChips(filters, users) {
    const chips = [];

    if (filters.brand) {
        chips.push({ id: 'brand', label: `Brand: ${filters.brand}`, clear: { brand: '' } });
    }
    if (filters.domain) {
        chips.push({ id: 'domain', label: `Domain: ${filters.domain}`, clear: { domain: '' } });
    }
    if (filters.geo) {
        chips.push({ id: 'geo', label: `GEO: ${filters.geo}`, clear: { geo: '' } });
    }
    if (filters.lang) {
        chips.push({ id: 'lang', label: `Lang: ${filters.lang}`, clear: { lang: '' } });
    }
    if (filters.template) {
        chips.push({ id: 'template', label: `Template: ${filters.template}`, clear: { template: '' } });
    }
    if (filters.panel) {
        chips.push({ id: 'panel', label: `Server: ${filters.panel}`, clear: { panel: '' } });
    }
    if (filters.user) {
        const user = users.find((item) => String(item.id) === String(filters.user));
        chips.push({
            id: 'user',
            label: `User: ${user?.name ?? user?.email ?? filters.user}`,
            clear: { user: '' },
        });
    }
    if (filters.archive_status === 'archived') {
        chips.push({ id: 'archive_status', label: 'Status: archived', clear: { archive_status: '' } });
    } else if (filters.archive_status === 'teardown_failed') {
        chips.push({ id: 'archive_status', label: 'Status: error', clear: { archive_status: '' } });
    }
    if (filters.archive_reason === 'stale_dead') {
        chips.push({ id: 'archive_reason', label: 'Чому: мертвий', clear: { archive_reason: '' } });
    } else if (filters.archive_reason === 'cf_abuse') {
        chips.push({ id: 'archive_reason', label: 'Чому: CF abuse', clear: { archive_reason: '' } });
    } else if (filters.archive_reason === 'manual') {
        chips.push({ id: 'archive_reason', label: 'Чому: вручну', clear: { archive_reason: '' } });
    }
    if (filters.created) {
        let dateLabel = 'Archived: ';
        switch (filters.created) {
            case 'today':
                dateLabel += 'today';
                break;
            case 'yesterday':
                dateLabel += 'yesterday';
                break;
            case 'week':
                dateLabel += 'this week';
                break;
            case 'month':
                dateLabel += 'this month';
                break;
            case 'custom':
                dateLabel += [
                    filters.created_from ? formatCreatedDate(filters.created_from) : '…',
                    filters.created_to ? formatCreatedDate(filters.created_to) : '…',
                ].join(' — ');
                break;
            default:
                dateLabel += filters.created;
        }
        chips.push({
            id: 'created',
            label: dateLabel,
            clear: { created: '', created_from: '', created_to: '' },
        });
    }

    return chips;
}

export default function Archive({
    offers,
    filters = {},
    filterOptions = {},
    createdCounts = {},
    perPageOptions = [10, 30, 50, 100],
    showUserColumn = false,
    users = [],
    dateFilters = {},
    staleDeadHint = null,
    canManageAllOffers = false,
    geoPresets = [],
    templates = [],
    brandTemplateUsage = {},
}) {
    const { auth, errors, flash } = usePage().props;
    const rows = offers?.data ?? [];
    const [retryingId, setRetryingId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const [restoringOffer, setRestoringOffer] = useState(null);
    const [copiedDomainId, setCopiedDomainId] = useState(null);
    const [brandQuery, setBrandQuery] = useState(filters.brand ?? '');
    const [domainQuery, setDomainQuery] = useState(filters.domain ?? '');

    const currentPage = offers?.current_page ?? 1;
    const lastPage = offers?.last_page ?? 1;
    const total = offers?.total ?? rows.length;
    const geos = filterOptions.geos ?? [];
    const langs = filterOptions.langs ?? [];
    const templateOptions = filterOptions.templates ?? [];
    const panelOptions = filterOptions.panels ?? [];

    const canManageOffer = (offer) => canManageAllOffers || offer.user_id === auth?.user?.id;

    const activeChips = useMemo(
        () => buildActiveFilterChips(filters, users),
        [filters, users],
    );

    const reloadArchive = (overrides = {}) => {
        const params = buildQueryParams(filters, { ...overrides, page: overrides.page ?? 1 });
        router.get(route('offers.archive.index'), params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearAllFilters = () => {
        reloadArchive({
            brand: '',
            domain: '',
            geo: '',
            lang: '',
            template: '',
            panel: '',
            user: '',
            archive_status: '',
            archive_reason: '',
            created: '',
            created_from: '',
            created_to: '',
            page: 1,
        });
    };

    useEffect(() => {
        setBrandQuery(filters.brand ?? '');
    }, [filters.brand]);

    useEffect(() => {
        setDomainQuery(filters.domain ?? '');
    }, [filters.domain]);

    useEffect(() => {
        if (brandQuery === (filters.brand ?? '')) {
            return undefined;
        }
        const timer = window.setTimeout(() => {
            reloadArchive({ brand: brandQuery });
        }, 350);
        return () => window.clearTimeout(timer);
    }, [brandQuery]);

    useEffect(() => {
        if (domainQuery === (filters.domain ?? '')) {
            return undefined;
        }
        const timer = window.setTimeout(() => {
            reloadArchive({ domain: domainQuery });
        }, 350);
        return () => window.clearTimeout(timer);
    }, [domainQuery]);

    const copyDomain = async (offer) => {
        const text = offer.domain;
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
        setCopiedDomainId(offer.id);
        window.setTimeout(() => setCopiedDomainId((id) => (id === offer.id ? null : id)), 2000);
    };

    const retryArchive = (offer) => {
        setRetryingId(offer.id);
        router.post(route('offers.archive.retry', offer.id), {}, {
            preserveScroll: true,
            onFinish: () => setRetryingId(null),
        });
    };

    const restoreOffer = (offer) => {
        setRestoringOffer(offer);
    };

    const deleteOffer = (offer) => {
        const ok = window.confirm(
            `Видалити ${offer.domain} назавжди з бази?\n\n`
            + 'Офер зникне з архіву. Поновити не вийде.',
        );
        if (!ok) {
            return;
        }

        setDeletingId(offer.id);
        router.delete(route('offers.destroy', offer.id), {
            preserveScroll: true,
            onFinish: () => setDeletingId(null),
        });
    };

    return (
        <PanelLayout title="Архів доменів" fullWidth>
            <div className="offers-page">
                <header className="page-header">
                    <h2>Архів доменів</h2>
                    <p>
                        Оффери зняті з origin-сервера і Cloudflare. Дані в БД збережені — можна поновити оффер.
                        Домен лишається в Dynadot, NS скидаються на parking (ns1/ns2.dynadot.com).
                    </p>
                    <p className="field-hint" style={{ marginTop: '0.5rem' }}>
                        <Link href={route('offers.index')}>← До активних офферів</Link>
                    </p>
                </header>

                <StaleDeadBanner hint={staleDeadHint} canArchive />

                {flash?.success && (
                    <div className="card" style={{ marginBottom: '1rem' }}>
                        <p className="card-desc">{flash.success}</p>
                    </div>
                )}

                {(errors?.archive || errors?.delete) && (
                    <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                        <p className="card-desc" style={{ color: '#f87171' }}>{errors.archive || errors.delete}</p>
                    </div>
                )}

                {activeChips.length > 0 && (
                    <div className="active-filters" aria-label="Активні фільтри">
                        <span className="active-filters__label">Обрані фільтри:</span>
                        <div className="active-filters__chips">
                            {activeChips.map((chip) => (
                                <button
                                    key={chip.id}
                                    type="button"
                                    className="filter-chip"
                                    onClick={() => reloadArchive(chip.clear)}
                                    title="Remove filter"
                                >
                                    {chip.label}
                                    <span className="filter-chip__remove" aria-hidden="true">×</span>
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm active-filters__reset"
                            onClick={clearAllFilters}
                        >
                            Reset all
                        </button>
                    </div>
                )}

                <div className="filter-bar">
                    <div className="filter-bar__search-group">
                        <input
                            type="search"
                            className="filter-bar__search"
                            placeholder="Brand…"
                            value={brandQuery}
                            onChange={(event) => setBrandQuery(event.target.value)}
                            aria-label="Search by brand"
                        />
                        <input
                            type="search"
                            className="filter-bar__search"
                            placeholder="Domain…"
                            value={domainQuery}
                            onChange={(event) => setDomainQuery(event.target.value)}
                            aria-label="Search by domain"
                        />
                    </div>
                    <select
                        aria-label="GEO"
                        value={filters.geo ?? ''}
                        onChange={(e) => reloadArchive({ geo: e.target.value })}
                    >
                        <option value="">All GEO</option>
                        {geos.map((g) => (
                            <option key={g} value={g}>{g}</option>
                        ))}
                    </select>
                    <select
                        aria-label="Lang"
                        value={filters.lang ?? ''}
                        onChange={(e) => reloadArchive({ lang: e.target.value })}
                    >
                        <option value="">All langs</option>
                        {langs.map((l) => (
                            <option key={l} value={l}>{l}</option>
                        ))}
                    </select>
                    <select
                        aria-label="Template"
                        value={filters.template ?? ''}
                        onChange={(e) => reloadArchive({ template: e.target.value })}
                    >
                        <option value="">Templates</option>
                        {templateOptions.map((tpl) => (
                            <option key={tpl} value={tpl}>{tpl}</option>
                        ))}
                    </select>
                    <select
                        aria-label="Server"
                        value={filters.panel ?? ''}
                        onChange={(e) => reloadArchive({ panel: e.target.value })}
                    >
                        <option value="">Servers</option>
                        {panelOptions.map((panel) => (
                            <option key={panel} value={panel}>{panel}</option>
                        ))}
                    </select>
                    {showUserColumn && users.length > 0 && (
                        <select
                            aria-label="User"
                            value={filters.user ?? ''}
                            onChange={(e) => reloadArchive({ user: e.target.value })}
                        >
                            <option value="">All users</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name ?? user.email}
                                </option>
                            ))}
                        </select>
                    )}
                    <select
                        aria-label="Archive status"
                        value={filters.archive_status ?? ''}
                        onChange={(e) => reloadArchive({ archive_status: e.target.value })}
                    >
                        <option value="">All statuses</option>
                        <option value="archived">В архіві</option>
                        <option value="teardown_failed">Помилка</option>
                    </select>
                    <select
                        aria-label="Archive reason"
                        value={filters.archive_reason ?? ''}
                        onChange={(e) => reloadArchive({ archive_reason: e.target.value })}
                    >
                        <option value="">Чому</option>
                        <option value="manual">Вручну</option>
                        <option value="stale_dead">Мертвий</option>
                        <option value="cf_abuse">CF abuse</option>
                    </select>
                    <select
                        aria-label="Archived date"
                        value={filters.created ?? ''}
                        onChange={(e) => reloadArchive({ created: e.target.value })}
                    >
                        <option value="">Archived date</option>
                        <option value="today">Today ({createdCounts.today ?? 0})</option>
                        <option value="yesterday">Yesterday ({createdCounts.yesterday ?? 0})</option>
                        <option value="week">This week ({createdCounts.week ?? 0})</option>
                        <option value="month">This month ({createdCounts.month ?? 0})</option>
                        <option value="custom">Custom…</option>
                    </select>
                    {filters.created === 'custom' && (
                        <div className="filter-bar__date-range">
                            <label className="filter-bar__date-field">
                                <span className="sr-only">From</span>
                                <input
                                    type="date"
                                    aria-label="Date from"
                                    value={filters.created_from ?? ''}
                                    max={filters.created_to || dateFilters.today || undefined}
                                    onChange={(e) => reloadArchive({ created_from: e.target.value })}
                                />
                            </label>
                            <span className="filter-bar__date-sep" aria-hidden="true">—</span>
                            <label className="filter-bar__date-field">
                                <span className="sr-only">To</span>
                                <input
                                    type="date"
                                    aria-label="Date to"
                                    value={filters.created_to ?? ''}
                                    min={filters.created_from || undefined}
                                    max={dateFilters.today || undefined}
                                    onChange={(e) => reloadArchive({ created_to: e.target.value })}
                                />
                            </label>
                        </div>
                    )}
                    <div className="filter-bar__meta">
                        <label className="filter-bar__per-page">
                            <span className="filter-bar__per-page-label">/ pg</span>
                            <select
                                aria-label="Per page"
                                value={String(filters.per_page ?? 30)}
                                onChange={(e) => reloadArchive({ per_page: Number(e.target.value), page: 1 })}
                            >
                                {perPageOptions.map((n) => (
                                    <option key={n} value={n}>{n}</option>
                                ))}
                            </select>
                        </label>
                        <span className="filter-bar__count">{total}</span>
                    </div>
                </div>

                <div className="table-wrap">
                    <table className="data-table offers-table">
                        <thead>
                            <tr>
                                {showUserColumn && <th>Користувач</th>}
                                <th>Домен</th>
                                <th>Бренд</th>
                                <th>Шаблон</th>
                                <th>GEO</th>
                                <th>Cloudflare</th>
                                <th>Реєстратор</th>
                                <th>Чому</th>
                                <th>Архівовано</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((offer) => {
                                const teardownErrors = offer.status === 'teardown_failed'
                                    ? (offer.teardown_meta?.errors ?? [])
                                    : [];
                                return (
                                    <tr key={offer.id}>
                                        {showUserColumn && (
                                            <td>
                                                <span className="field-hint">{offer.user_name ?? '—'}</span>
                                            </td>
                                        )}
                                        <td>
                                            <div className="domain-cell">
                                                <span className="domain-cell__name">{offer.domain}</span>
                                                <button
                                                    type="button"
                                                    className={`domain-copy${copiedDomainId === offer.id ? ' is-copied' : ''}`}
                                                    onClick={() => copyDomain(offer)}
                                                    title="Скопіювати домен"
                                                    aria-label={`Скопіювати ${offer.domain}`}
                                                >
                                                    {copiedDomainId === offer.id ? (
                                                        <span aria-hidden="true">✓</span>
                                                    ) : (
                                                        <svg
                                                            aria-hidden="true"
                                                            viewBox="0 0 24 24"
                                                            width="14"
                                                            height="14"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="2"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        >
                                                            <rect x="9" y="9" width="13" height="13" rx="2" />
                                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                                                        </svg>
                                                    )}
                                                </button>
                                            </div>
                                        </td>
                                        <td>{offer.brand}</td>
                                        <td>{offer.template}</td>
                                        <td>{offer.geo}</td>
                                        <td>
                                            {offer.cloudflare_account ?? '—'}
                                            {offer.cloudflare_slot === 'backup' || offer.cloudflare_slot === 'spare' ? (
                                                <span className="muted" style={{ marginLeft: '0.35rem' }}>· запасний</span>
                                            ) : null}
                                        </td>
                                        <td>{offer.dynadot_account ?? '—'}</td>
                                        <td>
                                            {offer.archive_reason_label ?? 'Вручну'}
                                            {teardownErrors.length > 0 && (
                                                <p className="field-hint" style={{ color: '#f87171', marginTop: '0.25rem' }}>
                                                    {teardownErrors.join(' · ')}
                                                </p>
                                            )}
                                        </td>
                                        <td>{formatArchivedAt(offer.archived_at)}</td>
                                        <td>
                                            <div className="offer-actions">
                                                {canManageOffer(offer) && offer.status === 'archived' && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-ghost btn-sm"
                                                        disabled={restoringOffer?.id === offer.id || deletingId === offer.id}
                                                        onClick={() => restoreOffer(offer)}
                                                        title="Поновити оффер з даних у БД"
                                                    >
                                                        Поновити
                                                    </button>
                                                )}
                                                {canManageOffer(offer) && offer.status === 'teardown_failed' && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-ghost btn-sm"
                                                        disabled={retryingId === offer.id || deletingId === offer.id}
                                                        onClick={() => retryArchive(offer)}
                                                    >
                                                        {retryingId === offer.id ? '…' : 'Повтор'}
                                                    </button>
                                                )}
                                                {canManageOffer(offer) && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-ghost btn-sm btn-purge"
                                                        disabled={deletingId === offer.id || restoringOffer?.id === offer.id}
                                                        onClick={() => deleteOffer(offer)}
                                                        title="Видалити назавжди з бази, без відновлення"
                                                        aria-label={`Видалити ${offer.domain}`}
                                                    >
                                                        {deletingId === offer.id ? (
                                                            <span className="btn-spinner" aria-hidden="true" />
                                                        ) : (
                                                            <TrashIcon />
                                                        )}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={showUserColumn ? 10 : 9} className="field-hint">
                                        {activeChips.length > 0 ? 'Нічого не знайдено за фільтрами' : 'Архів порожній'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {lastPage > 1 && (
                    <nav className="pagination-bar" aria-label="Сторінки архіву">
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm"
                            disabled={currentPage <= 1}
                            onClick={() => reloadArchive({ page: currentPage - 1 })}
                        >
                            ← Назад
                        </button>
                        <span className="pagination-bar__info">
                            Сторінка {currentPage} з {lastPage}
                        </span>
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm"
                            disabled={currentPage >= lastPage}
                            onClick={() => reloadArchive({ page: currentPage + 1 })}
                        >
                            Далі →
                        </button>
                    </nav>
                )}
            </div>

            {restoringOffer && (
                <OfferRestoreModal
                    offer={restoringOffer}
                    geoPresets={geoPresets}
                    templates={templates}
                    brandTemplateUsage={brandTemplateUsage}
                    onClose={() => setRestoringOffer(null)}
                />
            )}
        </PanelLayout>
    );
}
