import PanelLayout from '@/Layouts/PanelLayout';
import OfferEditModal from '@/Components/OfferEditModal';
import { clearWizardState } from '@/lib/offerWizardStorage';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const OFFER_COLUMNS_STORAGE_KEY = 'offerra.offers.visibleColumns.v1';

/** @type {{ id: string, label: string, locked?: boolean, adminOnly?: boolean }[]} */
const OFFER_TABLE_COLUMNS = [
    { id: 'user', label: 'User', adminOnly: true },
    { id: 'brand', label: 'Brand', locked: true },
    { id: 'domain', label: 'Domain', locked: true },
    { id: 'geo', label: 'GEO' },
    { id: 'lang', label: 'Lang' },
    { id: 'template', label: 'Template' },
    { id: 'panel', label: 'Server' },
    { id: 'cloudflare', label: 'Cloudflare' },
    { id: 'dynadot', label: 'Dynadot' },
    { id: 'cwv', label: 'CWV' },
    { id: 'created', label: 'Created' },
    { id: 'deployed', label: 'Deployed' },
    { id: 'status', label: 'Status' },
    { id: 'dns', label: 'DNS' },
    { id: 'indexing', label: 'Submitted' },
    { id: 'google_index', label: 'Indexed' },
    { id: 'actions', label: 'Actions', locked: true },
];

function defaultOfferColumnVisibility(showUserColumn) {
    return Object.fromEntries(
        OFFER_TABLE_COLUMNS
            .filter((col) => !col.adminOnly || showUserColumn)
            .map((col) => [col.id, true]),
    );
}

function loadOfferColumnVisibility(showUserColumn) {
    const defaults = defaultOfferColumnVisibility(showUserColumn);

    try {
        const raw = localStorage.getItem(OFFER_COLUMNS_STORAGE_KEY);
        if (!raw) {
            return defaults;
        }

        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') {
            return defaults;
        }

        const next = { ...defaults };
        Object.keys(defaults).forEach((key) => {
            if (typeof parsed[key] === 'boolean') {
                next[key] = parsed[key];
            }
        });

        OFFER_TABLE_COLUMNS.forEach((col) => {
            if (col.locked) {
                next[col.id] = true;
            }
        });

        return next;
    } catch {
        return defaults;
    }
}

function saveOfferColumnVisibility(visibility) {
    try {
        localStorage.setItem(OFFER_COLUMNS_STORAGE_KEY, JSON.stringify(visibility));
    } catch {
        // ignore quota / private mode
    }
}

function ArchiveIcon() {
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
            <path d="M3 7h18v4H3z" />
            <path d="M5 11v9h14v-9" />
            <path d="M10 15h4" />
        </svg>
    );
}

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

function statusBadge(status) {
    switch (status) {
        case 'deployed':
            return <span className="badge badge-ok">На сервері</span>;
        case 'deploying':
            return <span className="badge badge-warn">Деплоїться…</span>;
        case 'archiving':
            return <span className="badge badge-warn">Архівація…</span>;
        case 'purging':
            return <span className="badge badge-warn">Видалення…</span>;
        case 'teardown_failed':
            return <span className="badge badge-error">Архів помилка</span>;
        case 'failed':
            return <span className="badge badge-error">Помилка</span>;
        default:
            return <span className="badge">Згенеровано</span>;
    }
}

function infraBadge(offer) {
    if (!offer.provision_infrastructure && !offer.infra_status) {
        return null;
    }

    switch (offer.infra_status) {
        case 'provisioning':
        case 'pending':
            return <span className="badge badge-warn">Інфра…</span>;
        case 'failed':
            return <span className="badge badge-error">Інфра</span>;
        default:
            return null;
    }
}

function missTitle(offer) {
    const coverage = offer.google_index_coverage || '';
    if (coverage) {
        return coverage;
    }

    const host = offer.google_index_canonical || '';
    if (offer.google_index_status === 'duplicate' || host) {
        return host
            ? `Google індексує ${host} замість цього`
            : 'Google вважає цей URL дублем';
    }
    if (offer.google_index_status === 'not_indexed') {
        return 'Прокраулено, ще не в індексі';
    }

    return 'Не в індексі Google';
}

function indexedTick(offer) {
    if (offer.google_indexed) {
        return {
            tone: 'ok',
            title: offer.google_indexed_at
                ? `Indexed by Google: ${offer.google_indexed_at}${offer.google_index_coverage ? ` · ${offer.google_index_coverage}` : ''}`
                : (offer.google_index_coverage || 'Indexed by Google'),
        };
    }

    if (offer.google_index_miss || offer.google_index_status === 'duplicate' || offer.google_index_status === 'timeout') {
        return { tone: 'fail', title: missTitle(offer) };
    }

    const coverage = offer.google_index_coverage || '';
    if (offer.google_index_status === 'error') {
        return { tone: 'wait', title: coverage || 'Index check error — will retry' };
    }
    if (coverage && (offer.google_index_status === 'unknown' || offer.google_index_status === 'not_indexed')) {
        return { tone: 'wait', title: coverage };
    }
    if (offer.submitted_for_indexing || offer.google_index_status === 'waiting') {
        return { tone: 'wait', title: 'Waiting for Google (~24h after submit, then daily)' };
    }

    return { tone: 'wait', title: 'Not submitted yet' };
}

function IndexTick({ tone, title, onClick, busy = false, label, glyph = '✓', caption }) {
    const className = `index-tick index-tick--${tone}${onClick ? ' index-tick--button' : ''}${busy ? ' is-busy' : ''}${caption ? ' index-tick--captioned' : ''}`;

    const inner = (
        <>
            <span className="index-tick__mark" aria-hidden="true">{glyph}</span>
            {caption ? <span className="index-tick__caption">{caption}</span> : null}
        </>
    );

    if (typeof onClick === 'function') {
        return (
            <button
                type="button"
                className={className}
                title={title}
                aria-label={label || title}
                disabled={busy}
                onClick={onClick}
            >
                {inner}
            </button>
        );
    }

    return (
        <span className={className} title={title} aria-label={label || title}>
            {inner}
        </span>
    );
}

function formatOfferError(message) {
    const text = String(message ?? '').trim();

    if (!text) {
        return '';
    }

    const redacted = text.replace(/([?&]key=)[^&\s]+/gi, '$1REDACTED');

    if (/cURL error 35|unexpected eof|SSL routines|TLS connect|Recv failure/i.test(redacted)) {
        return 'Dynadot API обірвав зʼєднання (TLS). Інфра повторить set_ns автоматично.';
    }

    if (/cURL error 28|Failed to connect|Connection timed out/i.test(redacted)) {
        const match = redacted.match(/connect to ([^\s]+) port (\d+)/i);

        if (match) {
            return `Сервер не відповідає (${match[1]}:${match[2]}). Перевір IP і що порт відкритий для панелі.`;
        }

        if (/dynadot\.com/i.test(redacted)) {
            return 'Dynadot API не відповідає (таймаут). Інфра повторить set_ns автоматично.';
        }

        return 'Сервер не відповідає (таймаут). Перевір IP і SSH-порт у налаштуваннях Server.';
    }

    if (/Dynadot зайнятий/i.test(redacted)) {
        return 'Dynadot зайнятий паралельними запитами. Інфра повторить set_ns автоматично.';
    }

    if (/HTTP 429|Quota exceeded|Low rate user requests|rate-limited \(HTTP 429\)|GSC ліміт запитів/i.test(redacted)) {
        return 'Google Search Console тимчасово обмежив запити. Панель повторить подачу сама.';
    }

    if (isGscTokenError(redacted)) {
        return formatGscTokenError(redacted);
    }

    return redacted;
}

function isGscTokenError(message) {
    return /invalid_grant|expired or revoked|токен протух/i.test(String(message ?? ''));
}

function formatGscTokenError(message) {
    const text = String(message ?? '');
    const email = text.match(/для\s+(\S+@\S+)/)?.[1]?.replace(/[.,;]+$/, '');

    if (email) {
        return `Google токен протух для ${email}. Перепідключіть цей акаунт у Settings і натисніть «Подати в GSC».`;
    }

    return 'Google токен протух. Перепідключіть цей акаунт у Settings і натисніть «Подати в GSC».';
}

function gscSettingsHref(offer) {
    return offer.user_id
        ? route('settings.index', { user_id: offer.user_id })
        : route('settings.index');
}

function dnsBadge(offer) {
    switch (offer.dns_status) {
        case 'ready':
            return (
                <span className="dns-status dns-status--ready" title="DNS поширено" aria-label="DNS поширено">
                    ✓
                </span>
            );
        case 'pending':
            return (
                <span className="dns-status dns-status--pending" title="Очікується поширення DNS">
                    <span className="dns-status__dot" aria-hidden="true" />
                    Очікується DNS
                </span>
            );
        case 'waiting':
            return <span className="dns-status dns-status--waiting">—</span>;
        default:
            return <span className="dns-status dns-status--na">—</span>;
    }
}

function availabilityDot(offer, { onClick, busy = false } = {}) {
    const status = offer.availability_status || 'unchecked';
    const checked = offer.availability_checked_at
        ? `Перевірено: ${offer.availability_checked_at}`
        : 'Ще не перевіряли';
    const error = offer.availability_error ? `\n${offer.availability_error}` : '';
    const clickHint = '\nКлік — перевірити зараз';

    let tone = 'unchecked';
    let title = checked;
    let label = 'Доступність ще не перевірена';

    if (status === 'ok') {
        tone = 'ok';
        title = `Сайт відкривається\n${checked}`;
        label = 'Сайт доступний';
    } else if (status === 'down') {
        tone = 'down';
        title = `Сайт недоступний\n${checked}${error}`;
        label = 'Сайт недоступний';
    }

    if (busy) {
        title = 'Перевіряю…';
        label = 'Перевірка доступності';
    } else if (typeof onClick === 'function') {
        title += clickHint;
        label = `${label}. Натисніть, щоб перевірити зараз`;
    }

    const className = [
        'availability-dot',
        `availability-dot--${tone}`,
        busy ? 'is-checking' : '',
        typeof onClick === 'function' ? 'availability-dot--button' : '',
    ].filter(Boolean).join(' ');

    if (typeof onClick === 'function') {
        return (
            <button
                type="button"
                className={className}
                title={title}
                aria-label={label}
                disabled={busy}
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    onClick(offer);
                }}
            />
        );
    }

    return (
        <span
            className={className}
            title={title}
            aria-label={label}
        />
    );
}

function formatCreatedDate(isoDate) {
    if (!isoDate) {
        return '—';
    }

    const [year, month, day] = isoDate.split('-');

    return `${day}.${month}.${year}`;
}

function formatDeployedAt(value) {
    if (!value) {
        return '—';
    }

    // backend returns "YYYY-MM-DD HH:mm"
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
        chips.push({
            id: 'brand',
            label: `Brand: ${filters.brand}`,
            clear: { brand: '' },
        });
    }

    if (filters.domain) {
        chips.push({
            id: 'domain',
            label: `Domain: ${filters.domain}`,
            clear: { domain: '' },
        });
    }

    if (filters.geo) {
        chips.push({
            id: 'geo',
            label: `GEO: ${filters.geo}`,
            clear: { geo: '' },
        });
    }

    if (filters.lang) {
        chips.push({
            id: 'lang',
            label: `Lang: ${filters.lang}`,
            clear: { lang: '' },
        });
    }

    if (filters.template) {
        chips.push({
            id: 'template',
            label: `Template: ${filters.template}`,
            clear: { template: '' },
        });
    }

    if (filters.panel) {
        chips.push({
            id: 'panel',
            label: `Server: ${filters.panel}`,
            clear: { panel: '' },
        });
    }

    if (filters.user) {
        const user = users.find((item) => String(item.id) === String(filters.user));
        chips.push({
            id: 'user',
            label: `User: ${user?.name ?? user?.email ?? filters.user}`,
            clear: { user: '' },
        });
    }

    if (filters.indexing === 'yes') {
        chips.push({
            id: 'indexing',
            label: 'Submitted: yes',
            clear: { indexing: '' },
        });
    } else if (filters.indexing === 'no') {
        chips.push({
            id: 'indexing',
            label: 'Submitted: no',
            clear: { indexing: '' },
        });
    }

    if (filters.google_index === 'indexed') {
        chips.push({
            id: 'google_index',
            label: 'Indexed',
            clear: { google_index: '' },
        });
    } else if (filters.google_index === 'pending') {
        chips.push({
            id: 'google_index',
            label: 'Pending',
            clear: { google_index: '' },
        });
    } else if (['not_indexed', 'duplicate', 'unseen', 'timeout'].includes(filters.google_index)) {
        chips.push({
            id: 'google_index',
            label: 'Not indexed',
            clear: { google_index: '' },
        });
    }

    if (filters.availability === 'ok') {
        chips.push({
            id: 'availability',
            label: '● Green (up)',
            clear: { availability: '' },
        });
    } else if (filters.availability === 'down') {
        chips.push({
            id: 'availability',
            label: '● Red (down)',
            clear: { availability: '' },
        });
    } else if (filters.availability === 'unchecked') {
        chips.push({
            id: 'availability',
            label: '● Gray (unchecked)',
            clear: { availability: '' },
        });
    }

    if (filters.created) {
        let dateLabel = 'Date: ';

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
                ].join(' – ');
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

function resolveOffersPagination(offers, filters = {}) {
    const pagination = offers?.meta ?? offers ?? {};

    return {
        rows: offers?.data ?? [],
        total: Number(pagination.total ?? 0),
        from: Number(pagination.from ?? 0),
        to: Number(pagination.to ?? 0),
        currentPage: Number(pagination.current_page ?? 1),
        lastPage: Number(pagination.last_page ?? 1),
        perPage: Number(pagination.per_page ?? filters.per_page ?? 30),
    };
}

export default function OffersIndex({
    offers,
    filters = {},
    filterOptions = {},
    createdCounts = {},
    perPageOptions = [10, 30, 50, 100],
    canDeploy,
    geoPresets = [],
    currencies = [],
    templates = [],
    brandTemplateUsage = {},
    showUserColumn = false,
    canManageAllOffers = false,
    users = [],
    dateFilters = {},
    hasCloudflarePrimary = false,
    cloudflarePrimaryName = 'Основний CF',
    spareCloudflareAccounts = [],
    originServers = [],
    downCount = 0,
}) {
    const { flash, errors, auth } = usePage().props;
    const canDownloadOfferZip = Boolean(auth?.user?.can_download_offer_zip);
    const [deployingId, setDeployingId] = useState(null);
    const [zippingId, setZippingId] = useState(null);
    const [provisioningId, setProvisioningId] = useState(null);
    const [indexingId, setIndexingId] = useState(null);
    const [gscSubmitId, setGscSubmitId] = useState(null);
    const [checkingAvailabilityId, setCheckingAvailabilityId] = useState(null);
    const [checkingIndexId, setCheckingIndexId] = useState(null);
    const [archivingId, setArchivingId] = useState(null);
    const [teardownKind, setTeardownKind] = useState(null);
    const [copiedDomainId, setCopiedDomainId] = useState(null);
    const [editingOffer, setEditingOffer] = useState(null);
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [bulkIp, setBulkIp] = useState('');
    const [rebindOpen, setRebindOpen] = useState(false);
    const [cfSwitchOpen, setCfSwitchOpen] = useState(false);
    const [serverSwitchOpen, setServerSwitchOpen] = useState(false);
    const [scanningDown, setScanningDown] = useState(false);
    const selectAllRef = useRef(null);
    const [brandQuery, setBrandQuery] = useState(filters.brand ?? '');
    const [domainQuery, setDomainQuery] = useState(filters.domain ?? '');
    const [columnVisibility, setColumnVisibility] = useState(() => loadOfferColumnVisibility(showUserColumn));
    const [columnsMenuOpen, setColumnsMenuOpen] = useState(false);
    const columnsMenuRef = useRef(null);

    const pagination = resolveOffersPagination(offers, filters);
    const rows = pagination.rows;
    const { total, from: rangeFrom, to: rangeTo, currentPage, lastPage, perPage } = pagination;
    const geos = filterOptions.geos ?? [];
    const langs = filterOptions.langs ?? [];
    const templateOptions = filterOptions.templates ?? [];
    const panelOptions = filterOptions.panels ?? [];

    const availableColumns = useMemo(
        () => OFFER_TABLE_COLUMNS.filter((col) => !col.adminOnly || showUserColumn),
        [showUserColumn],
    );

    const colVisible = (id) => Boolean(columnVisibility[id]);

    const visibleDataColCount = useMemo(
        () => 2 + availableColumns.filter((col) => columnVisibility[col.id]).length,
        [availableColumns, columnVisibility],
    );

    useEffect(() => {
        setColumnVisibility((prev) => {
            const defaults = defaultOfferColumnVisibility(showUserColumn);
            const next = { ...defaults };
            Object.keys(defaults).forEach((key) => {
                if (typeof prev[key] === 'boolean') {
                    next[key] = prev[key];
                }
            });
            OFFER_TABLE_COLUMNS.forEach((col) => {
                if (col.locked) {
                    next[col.id] = true;
                }
            });
            return next;
        });
    }, [showUserColumn]);

    useEffect(() => {
        saveOfferColumnVisibility(columnVisibility);
    }, [columnVisibility]);

    useEffect(() => {
        if (!columnsMenuOpen) {
            return undefined;
        }

        const onPointerDown = (event) => {
            if (!columnsMenuRef.current?.contains(event.target)) {
                setColumnsMenuOpen(false);
            }
        };

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setColumnsMenuOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [columnsMenuOpen]);

    const toggleColumn = (id) => {
        const meta = OFFER_TABLE_COLUMNS.find((col) => col.id === id);
        if (!meta || meta.locked) {
            return;
        }

        setColumnVisibility((prev) => ({
            ...prev,
            [id]: !prev[id],
        }));
    };

    const showAllColumns = () => {
        setColumnVisibility(defaultOfferColumnVisibility(showUserColumn));
    };

    useEffect(() => {
        if (flash?.success) {
            clearWizardState();
        }
    }, [flash?.success]);

    useEffect(() => {
        const offerId = flash?.zip_offer_id;
        if (!offerId || !canDownloadOfferZip) {
            return;
        }

        window.location.assign(route('offers.download-zip', offerId));
    }, [flash?.zip_offer_id, canDownloadOfferZip]);

    useEffect(() => {
        setBrandQuery(filters.brand ?? '');
    }, [filters.brand]);

    useEffect(() => {
        setDomainQuery(filters.domain ?? '');
    }, [filters.domain]);

    const reloadOffers = (overrides = {}, { resetPage = true } = {}) => {
        const next = { ...filters, ...overrides };

        if (resetPage) {
            next.page = 1;
        }

        router.get(route('offers.index'), buildQueryParams(next), {
            preserveState: false,
            replace: true,
            preserveScroll: true,
        });
    };

    const clearAllFilters = () => {
        setBrandQuery('');
        setDomainQuery('');
        reloadOffers({
            brand: '',
            domain: '',
            geo: '',
            lang: '',
            template: '',
            panel: '',
            indexing: '',
            google_index: '',
            availability: '',
            created: '',
            created_from: '',
            created_to: '',
            user: '',
        });
    };

    const clearFilter = (overrides) => {
        reloadOffers(overrides);
    };

    useEffect(() => {
        if (brandQuery === (filters.brand ?? '')) {
            return undefined;
        }

        const timeout = window.setTimeout(() => {
            reloadOffers({ brand: brandQuery });
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [brandQuery, filters.brand]);

    useEffect(() => {
        if (domainQuery === (filters.domain ?? '')) {
            return undefined;
        }

        const timeout = window.setTimeout(() => {
            reloadOffers({ domain: domainQuery });
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [domainQuery, filters.domain]);

    const hasDeploying = useMemo(
        () => rows.some((offer) => ['deploying', 'archiving', 'purging'].includes(offer.status)),
        [rows],
    );

    const hasInfraProvisioning = useMemo(
        () => rows.some((offer) => ['pending', 'provisioning'].includes(offer.infra_status)),
        [rows],
    );

    const hasDnsPending = useMemo(
        () => rows.some((offer) => offer.dns_status === 'pending'),
        [rows],
    );

    useEffect(() => {
        if (!hasDeploying && !hasInfraProvisioning) {
            return undefined;
        }

        const interval = window.setInterval(() => {
            router.reload({
                only: ['offers'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 5000);

        return () => window.clearInterval(interval);
    }, [hasDeploying, hasInfraProvisioning]);

    useEffect(() => {
        if (!hasDnsPending) {
            return undefined;
        }

        const interval = window.setInterval(() => {
            router.reload({
                only: ['offers'],
                preserveScroll: true,
                preserveState: true,
            });
        }, 60000);

        return () => window.clearInterval(interval);
    }, [hasDnsPending]);

    const downloadOfferZip = (offer) => {
        if (!canDownloadOfferZip) {
            return;
        }

        setZippingId(offer.id);
        window.location.assign(route('offers.download-zip', offer.id));
        window.setTimeout(() => setZippingId(null), 2500);
    };

    const deployOffer = (offer) => {
        setDeployingId(offer.id);
        router.post(route('offers.deploy', offer.id), {}, {
            preserveScroll: true,
            onFinish: () => setDeployingId(null),
        });
    };

    const provisionOffer = (offer) => {
        setProvisioningId(offer.id);
        router.post(route('offers.provision', offer.id), {}, {
            preserveScroll: true,
            onFinish: () => setProvisioningId(null),
        });
    };

    const archiveOffer = (offer) => {
        const ok = window.confirm(
            `Архівувати ${offer.domain}?\n\n`
            + 'Знімемо origin і Cloudflare. Домен лишиться в Dynadot, офер буде в архіві — можна поновити.',
        );
        if (!ok) {
            return;
        }

        setArchivingId(offer.id);
        setTeardownKind('archive');
        router.post(route('offers.archive', offer.id), {}, {
            preserveScroll: true,
            onFinish: () => {
                setArchivingId(null);
                setTeardownKind(null);
            },
        });
    };

    const deleteOffer = (offer) => {
        const ok = window.confirm(
            `Видалити ${offer.domain} назавжди?\n\n`
            + 'Знімемо origin і Cloudflare. З архіву поновити не вийде — лише для мертвих/забанених доменів.',
        );
        if (!ok) {
            return;
        }

        setArchivingId(offer.id);
        setTeardownKind('purge');
        router.delete(route('offers.destroy', offer.id), {
            preserveScroll: true,
            onFinish: () => {
                setArchivingId(null);
                setTeardownKind(null);
            },
        });
    };

    const canManageOffer = (offer) => {
        if (canManageAllOffers) {
            return true;
        }

        return offer.user_id === auth?.user?.id;
    };

    const canDeployOffer = (offer) => {
        if (canManageAllOffers) {
            return offer.deploy_ready;
        }

        return canDeploy && offer.user_id === auth?.user?.id;
    };

    const selectableIds = useMemo(
        () => rows
            .filter((offer) => {
                const canManage = canManageAllOffers || offer.user_id === auth?.user?.id;
                return canManage && !['archiving', 'purging', 'archived'].includes(offer.status);
            })
            .map((offer) => offer.id),
        [rows, canManageAllOffers, auth?.user?.id],
    );

    const selectedCount = selectedIds.length;
    const selectedOnPageCount = selectableIds.filter((id) => selectedIds.includes(id)).length;
    const allPageSelected = selectableIds.length > 0 && selectedOnPageCount === selectableIds.length;
    const originTargets = Array.isArray(originServers) ? originServers : [];
    const spareAccounts = Array.isArray(spareCloudflareAccounts) ? spareCloudflareAccounts : [];
    const canSwitchCloudflarePool = auth?.user?.role === 'admin';

    const selectedRowsOnPage = useMemo(
        () => rows.filter((offer) => selectedIds.includes(offer.id)),
        [rows, selectedIds],
    );

    const allKnownSelectedOnHost = (host) => {
        if (selectedRowsOnPage.length === 0 || selectedRowsOnPage.length !== selectedIds.length) {
            return false;
        }

        return selectedRowsOnPage.every((offer) => String(offer.deploy_panel || '') === String(host));
    };

    const originButtonLabel = (server) => {
        const hoster = String(server.hoster || '').trim();

        return hoster !== '' ? `${hoster} · ${server.host}` : (server.label || server.host);
    };

    const selectedCfContext = useMemo(() => {
        const selected = rows.filter((offer) => selectedIds.includes(offer.id));

        // Selection may span pages; if none of the selected rows are on this page, stay neutral.
        if (selected.length === 0) {
            if (selectedIds.length > 0) {
                return {
                    primaryName: 'Основний CF власника',
                    hasPrimary: true,
                    mixedOwners: true,
                    ownerLabel: null,
                };
            }

            return {
                primaryName: cloudflarePrimaryName,
                hasPrimary: hasCloudflarePrimary,
                mixedOwners: false,
                ownerLabel: null,
            };
        }

        const ownerIds = [...new Set(selected.map((offer) => String(offer.user_id ?? '')))];
        const mixedOwners = ownerIds.length > 1;

        if (mixedOwners) {
            return {
                primaryName: 'Основний CF власника',
                hasPrimary: selected.some((offer) => offer.owner_has_cf_primary),
                mixedOwners: true,
                ownerLabel: null,
            };
        }

        const sample = selected[0];

        return {
            primaryName: sample.owner_cf_primary_name || cloudflarePrimaryName,
            hasPrimary: Boolean(sample.owner_has_cf_primary),
            mixedOwners: false,
            ownerLabel: sample.user_name || sample.user_email || null,
        };
    }, [
        rows,
        selectedIds,
        cloudflarePrimaryName,
        hasCloudflarePrimary,
    ]);

    useEffect(() => {
        if (!selectAllRef.current) {
            return;
        }
        selectAllRef.current.indeterminate = selectedOnPageCount > 0 && !allPageSelected;
    }, [selectedOnPageCount, allPageSelected]);

    const toggleSelected = (id) => {
        setSelectedIds((prev) => (
            prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
        ));
    };

    const toggleSelectPage = (checked) => {
        setSelectedIds((prev) => {
            if (checked) {
                return [...new Set([...prev, ...selectableIds])];
            }
            return prev.filter((id) => !selectableIds.includes(id));
        });
    };

    const runBulkAction = (action, extra = {}) => {
        if (selectedCount === 0 || bulkBusy) {
            return;
        }

        if (action === 'check_availability') {
            if (!window.confirm(
                `Перевірити доступність ${selectedCount} обраних оферів зараз?\nКружечки оновляться, коли скан добіжить черги.`,
            )) {
                return;
            }
        }

        if (action === 'submit_gsc') {
            if (!window.confirm(
                `Відправити sitemap у Search Console ще раз для ${selectedCount} оффер(ів)?\n` +
                'Вже подані теж підуть повторно. Google сам вирішить, коли переіндексувати.',
            )) {
                return;
            }
        }

        if (action === 'redeploy') {
            if (!window.confirm(`Запустити редеплой для ${selectedCount} оффер(ів)?`)) {
                return;
            }
        }

        if (action === 'rebind_dns') {
            const ip = bulkIp.trim();
            if (!/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip)) {
                window.alert('Вкажи IPv4 нового сервера, наприклад 91.224.92.135');
                return;
            }
            if (!window.confirm(
                `Змінити Cloudflare A-записи @ і www на ${ip} для ${selectedCount} оффер(ів)?\nФайли на диску не копіюються — лише DNS.`,
            )) {
                return;
            }
        }

        if (action === 'switch_cloudflare') {
            const rawTarget = String(extra.cloudflare_target || 'primary');
            const spareMatch = rawTarget.match(/^spare:(\d+)$/);
            const spare = spareMatch
                ? spareAccounts.find((item) => String(item.id) === spareMatch[1])
                : null;
            const target = spare ? rawTarget : 'primary';
            const label = spare ? spare.name : selectedCfContext.primaryName;
            const hasSlot = spare ? true : selectedCfContext.hasPrimary;

            if (!hasSlot) {
                window.alert(
                    spare
                        ? 'Цей запасний Cloudflare вимкнений або без token/Account ID.'
                        : 'У власника вибраних оферів немає основного Cloudflare (token + Account ID) у Settings.',
                );
                return;
            }

            const ownerHint = spare
                ? 'акаунт з пулу адміна'
                : (selectedCfContext.mixedOwners
                    ? 'у кожного офера — свій CF власника'
                    : (selectedCfContext.ownerLabel
                        ? `власник: ${selectedCfContext.ownerLabel}`
                        : 'CF власника офера'));

            if (!window.confirm(
                `Перекинути ${selectedCount} оффер(ів) на «${label}» (${ownerHint})?\n` +
                'Створиться нова CF-зона, A/SSL/редіректи, NS у Dynadot; стара зона спробує видалитись.',
            )) {
                return;
            }
        }

        if (action === 'move_server') {
            const host = String(extra.target_host || '').trim();
            if (!host) {
                window.alert('Оберіть origin-сервер.');
                return;
            }
            if (allKnownSelectedOnHost(host)) {
                window.alert(`Обрані офери вже на ${host}.`);
                return;
            }
            if (!window.confirm(
                `Перекинути ${selectedCount} оффер(ів) на ${host}?\n` +
                'Файли заллються на новий origin, A-запис оновиться, копія зі старого сервера зніметься пізніше.',
            )) {
                return;
            }
        }

        setBulkBusy(true);
        router.post(route('offers.bulk-action'), {
            ids: selectedIds,
            action,
            ...(action === 'rebind_dns' ? { ip: bulkIp.trim() } : {}),
            ...(action === 'switch_cloudflare' ? { cloudflare_target: extra.cloudflare_target } : {}),
            ...(action === 'move_server' ? { target_host: extra.target_host } : {}),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedIds([]);
                setRebindOpen(false);
                setCfSwitchOpen(false);
                setServerSwitchOpen(false);
            },
            onFinish: () => setBulkBusy(false),
        });
    };

    const toggleIndexing = (offer, checked) => {
        setIndexingId(offer.id);
        router.patch(
            route('offers.indexing', offer.id),
            { submitted_for_indexing: checked },
            {
                preserveScroll: true,
                onFinish: () => setIndexingId(null),
            },
        );
    };

    const scanAllDown = () => {
        if (scanningDown || downCount < 1) {
            return;
        }
        if (!window.confirm(
            `Перевірити всі ${downCount} червоні офери зараз?\nСкан іде в черзі, кружечки оновляться самі.`,
        )) {
            return;
        }
        setScanningDown(true);
        router.post(route('offers.scan-availability'), {}, {
            preserveScroll: true,
            onFinish: () => setScanningDown(false),
        });
    };

    const checkAvailability = (offer) => {
        if (checkingAvailabilityId) {
            return;
        }

        setCheckingAvailabilityId(offer.id);
        router.post(
            route('offers.check-availability', offer.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setCheckingAvailabilityId(null),
            },
        );
    };

    const submitGsc = (offer) => {
        if (gscSubmitId) {
            return;
        }

        setGscSubmitId(offer.id);
        router.post(
            route('offers.gsc', offer.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setGscSubmitId(null),
            },
        );
    };

    const renderGscError = (offer) => {
        if (!offer.gsc_error) {
            return null;
        }

        return (
            <div className="gsc-error">
                <div className="gsc-error__text" title={offer.gsc_error}>
                    {formatOfferError(offer.gsc_error)}
                </div>
                {canManageOffer(offer) && (
                    <div className="gsc-error__actions">
                        {isGscTokenError(offer.gsc_error) && (
                            <Link href={gscSettingsHref(offer)} className="btn btn-ghost btn-sm">
                                Перепідключити
                            </Link>
                        )}
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm"
                            disabled={gscSubmitId === offer.id}
                            onClick={() => submitGsc(offer)}
                        >
                            {gscSubmitId === offer.id ? '…' : 'Подати в GSC'}
                        </button>
                    </div>
                )}
            </div>
        );
    };

    const inspectIndex = (offer) => {
        if (checkingIndexId) {
            return;
        }

        setCheckingIndexId(offer.id);
        router.post(
            route('offers.inspect-index', offer.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setCheckingIndexId(null),
            },
        );
    };

    const copyDomainUrl = async (offer) => {
        const url = `https://${offer.domain}`;

        try {
            await navigator.clipboard.writeText(url);
        } catch {
            const textarea = document.createElement('textarea');
            textarea.value = url;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }

        setCopiedDomainId(offer.id);
        window.setTimeout(() => {
            setCopiedDomainId((current) => (current === offer.id ? null : current));
        }, 2000);
    };

    const activeFilterChips = useMemo(
        () => buildActiveFilterChips(filters, users),
        [filters, users],
    );

    const hasActiveFilters = activeFilterChips.length > 0;

    const renderOfferActions = (offer) => {
        const isDeploying = offer.status === 'deploying' || deployingId === offer.id;

        return (
            <div className="offer-actions">
                {canManageOffer(offer) && (
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        onClick={() => setEditingOffer(offer)}
                        title="Редагувати оффер (GEO / мова / шаблон / phone / CWV)"
                        aria-label="Редагувати оффер"
                    >
                        ✎
                    </button>
                )}
                {canManageOffer(offer) && offer.provision_infrastructure && offer.dns_status === 'pending' && (
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={provisioningId === offer.id}
                        onClick={() => provisionOffer(offer)}
                        title="Перевірити DNS зараз"
                    >
                        {provisioningId === offer.id ? '…' : 'DNS'}
                    </button>
                )}
                {canManageOffer(offer) && offer.provision_infrastructure && ['failed', 'pending', 'provisioning'].includes(offer.infra_status) && (
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={provisioningId === offer.id}
                        onClick={() => provisionOffer(offer)}
                        title="Повторити налаштування інфраструктури"
                    >
                        {provisioningId === offer.id ? '…' : 'Інфра'}
                    </button>
                )}
                {canDownloadOfferZip && (
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={zippingId === offer.id}
                        onClick={() => downloadOfferZip(offer)}
                        title="Скачати зібраний бойовий ленд ZIP"
                        aria-label={`Скачати ZIP ${offer.domain}`}
                    >
                        {zippingId === offer.id ? (
                            <span className="btn-spinner" aria-hidden="true" />
                        ) : (
                            'ZIP'
                        )}
                    </button>
                )}
                {canDeployOffer(offer) ? (
                    <button
                        type="button"
                        className={`btn btn-ghost btn-sm btn-deploy${isDeploying ? ' is-loading' : ''}`}
                        disabled={isDeploying}
                        aria-busy={isDeploying}
                        onClick={() => deployOffer(offer)}
                    >
                        {isDeploying ? (
                            <>
                                <span className="btn-spinner" aria-hidden="true" />
                                <span>Деплой…</span>
                            </>
                        ) : offer.status === 'deployed' ? (
                            '↻'
                        ) : offer.status === 'failed' ? (
                            'Повтор'
                        ) : (
                            'Деплой'
                        )}
                    </button>
                ) : (
                    <span className="field-hint">—</span>
                )}
                {canManageOffer(offer) && !['archiving', 'purging', 'archived'].includes(offer.status) && (
                    <>
                        {auth?.user?.can_archive_offers && (
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm btn-shelve"
                            disabled={archivingId === offer.id || offer.status === 'deploying' || offer.status === 'purging'}
                            onClick={() => archiveOffer(offer)}
                            title="Архівувати: зняти з origin і Cloudflare, домен лишиться, можна поновити"
                            aria-label={`Архівувати ${offer.domain}`}
                        >
                            {archivingId === offer.id && teardownKind === 'archive' ? (
                                <span className="btn-spinner" aria-hidden="true" />
                            ) : (
                                <ArchiveIcon />
                            )}
                        </button>
                        )}
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm btn-purge"
                            disabled={archivingId === offer.id || offer.status === 'deploying' || offer.status === 'purging'}
                            onClick={() => deleteOffer(offer)}
                            title="Видалити назавжди: мертвий/забанений домен, без відновлення"
                            aria-label={`Видалити ${offer.domain}`}
                        >
                            {archivingId === offer.id && teardownKind === 'purge' ? (
                                <span className="btn-spinner" aria-hidden="true" />
                            ) : (
                                <TrashIcon />
                            )}
                        </button>
                    </>
                )}
            </div>
        );
    };

    return (
        <PanelLayout title="Оффери" fullWidth>
            <div className="offers-page">
            <header className="page-header offers-page-header">
                <div>
                    <h2>Оффери</h2>
                    <p>Каталог згенерованих лендів — деплой на сервер</p>
                </div>
                <Link
                    href={route('offers.create')}
                    className="btn btn-primary offers-page-header__create"
                >
                    + Новий оффер
                </Link>
            </header>

            {flash?.error && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                    <p className="card-desc" style={{ color: '#f87171' }}>{flash.error}</p>
                </div>
            )}
            {flash?.warning && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#fbbf24' }}>
                    <p className="card-desc" style={{ color: '#fbbf24' }}>{flash.warning}</p>
                </div>
            )}
            {flash?.success && (
                <div className="card" style={{ marginBottom: '1rem' }}>
                    <p className="card-desc">{flash.success}</p>
                </div>
            )}

            {downCount > 0 && (
                <div className="down-scan-banner" role="status">
                    <div className="down-scan-banner__text">
                        <span className="availability-dot availability-dot--down" aria-hidden="true" />
                        <strong>{downCount}</strong>
                        {' '}
                        червоних оферів — сайт не відкривається з панелі.
                    </div>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        disabled={scanningDown}
                        onClick={scanAllDown}
                    >
                        {scanningDown ? 'Запуск…' : 'Перевірити всі червоні'}
                    </button>
                </div>
            )}

            {errors?.edit && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                    <p className="card-desc" style={{ color: '#f87171' }}>{errors.edit}</p>
                </div>
            )}

            {errors?.deploy && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                    <p className="card-desc" style={{ color: '#f87171' }}>{errors.deploy}</p>
                </div>
            )}
            {(errors?.bulk || errors?.ids || errors?.ip || errors?.action) && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                    <p className="card-desc" style={{ color: '#f87171' }}>
                        {errors.bulk || errors.ids || errors.ip || errors.action}
                    </p>
                </div>
            )}
            {errors?.provision && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                    <p className="card-desc" style={{ color: '#f87171' }}>{errors.provision}</p>
                </div>
            )}
            {errors?.zip && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                    <p className="card-desc" style={{ color: '#f87171' }}>{errors.zip}</p>
                </div>
            )}
            {(errors?.archive || errors?.delete) && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                    <p className="card-desc" style={{ color: '#f87171' }}>{errors.delete || errors.archive}</p>
                </div>
            )}

            {!canDeploy && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#f59e0b' }}>
                    <p className="card-desc">
                        Для деплою збережіть SFTP-налаштування в{' '}
                        <Link href={route('settings.index')}>налаштуваннях</Link>.
                    </p>
                </div>
            )}

            {hasActiveFilters && (
                <div className="active-filters" aria-label="Активні фільтри">
                    <span className="active-filters__label">Обрані фільтри:</span>
                    <div className="active-filters__chips">
                        {activeFilterChips.map((chip) => (
                            <button
                                key={chip.id}
                                type="button"
                                className="filter-chip"
                                onClick={() => clearFilter(chip.clear)}
                                title="Remove filter"
                            >
                                <span>{chip.label}</span>
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
                <div className="columns-picker" ref={columnsMenuRef}>
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm columns-picker__btn"
                        aria-expanded={columnsMenuOpen}
                        aria-haspopup="true"
                        aria-label="Table columns"
                        title="Columns"
                        onClick={() => setColumnsMenuOpen((open) => !open)}
                    >
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
                            <rect x="3" y="4" width="18" height="16" rx="2" />
                            <path d="M9 4v16" />
                            <path d="M15 4v16" />
                        </svg>
                    </button>
                    {columnsMenuOpen && (
                        <div className="columns-picker__menu" role="menu" aria-label="Visible columns">
                            <div className="columns-picker__head">
                                <span>Columns</span>
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-sm"
                                    onClick={showAllColumns}
                                >
                                    All
                                </button>
                            </div>
                            <ul className="columns-picker__list">
                                {availableColumns.map((col) => (
                                    <li key={col.id}>
                                        <label className={`columns-picker__option${col.locked ? ' is-locked' : ''}`}>
                                            <input
                                                type="checkbox"
                                                checked={colVisible(col.id)}
                                                disabled={Boolean(col.locked)}
                                                onChange={() => toggleColumn(col.id)}
                                            />
                                            <span>{col.label}</span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
                <div className="filter-bar__scroll">
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
                    onChange={(e) => reloadOffers({ geo: e.target.value })}
                >
                    <option value="">All GEO</option>
                    {geos.map((g) => (
                        <option key={g} value={g}>
                            {g}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Lang"
                    value={filters.lang ?? ''}
                    onChange={(e) => reloadOffers({ lang: e.target.value })}
                >
                    <option value="">All langs</option>
                    {langs.map((l) => (
                        <option key={l} value={l}>
                            {l}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Template"
                    value={filters.template ?? ''}
                    onChange={(e) => reloadOffers({ template: e.target.value })}
                >
                    <option value="">Templates</option>
                    {templateOptions.map((tpl) => (
                        <option key={tpl} value={tpl}>
                            {tpl}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Server"
                    value={filters.panel ?? ''}
                    onChange={(e) => reloadOffers({ panel: e.target.value })}
                >
                    <option value="">Servers</option>
                    {panelOptions.map((panel) => (
                        <option key={panel} value={panel}>
                            {panel}
                        </option>
                    ))}
                </select>
                {showUserColumn && users.length > 0 && (
                    <select
                        aria-label="User"
                        value={filters.user ?? ''}
                        onChange={(e) => reloadOffers({ user: e.target.value })}
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
                    aria-label="Submitted to GSC"
                    value={filters.indexing ?? ''}
                    onChange={(e) => reloadOffers({ indexing: e.target.value })}
                >
                    <option value="">Submitted</option>
                    <option value="no">Not submitted</option>
                    <option value="yes">Submitted</option>
                </select>
                <select
                    aria-label="Google index status"
                    value={['duplicate', 'unseen', 'timeout'].includes(filters.google_index)
                        ? 'not_indexed'
                        : (filters.google_index ?? '')}
                    onChange={(e) => reloadOffers({ google_index: e.target.value })}
                >
                    <option value="">All</option>
                    <option value="indexed">Indexed</option>
                    <option value="pending">Pending</option>
                    <option value="not_indexed">Not indexed</option>
                </select>
                <select
                    aria-label="Availability dots"
                    value={filters.availability ?? ''}
                    onChange={(e) => reloadOffers({ availability: e.target.value })}
                >
                    <option value="">● Status</option>
                    <option value="ok">● Green</option>
                    <option value="down">● Red</option>
                    <option value="unchecked">● Gray</option>
                </select>
                <select
                    aria-label="Created date"
                    value={filters.created ?? ''}
                    onChange={(e) => reloadOffers({ created: e.target.value })}
                >
                    <option value="">Dates</option>
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
                                onChange={(e) => reloadOffers({ created_from: e.target.value })}
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
                                onChange={(e) => reloadOffers({ created_to: e.target.value })}
                            />
                        </label>
                    </div>
                )}
                </div>
                <div className="filter-bar__meta">
                    <label className="filter-bar__per-page">
                        <span className="filter-bar__per-page-label">/ pg</span>
                        <select
                            aria-label="Rows per page"
                            value={String(filters.per_page ?? 30)}
                            onChange={(e) => reloadOffers({ per_page: Number(e.target.value) })}
                        >
                            {perPageOptions.map((size) => (
                                <option key={size} value={size}>
                                    {size}
                                </option>
                            ))}
                        </select>
                    </label>
                    {(hasActiveFilters || total > 0) && (
                        <span className="filter-bar__count">
                            {total === 0
                                ? 'No offers'
                                : `${total}${lastPage > 1 ? ` · ${rangeFrom}–${rangeTo}` : ''}`}
                        </span>
                    )}
                </div>
            </div>

            {selectedCount > 0 && (
                <div className="offer-bulk-bar" role="region" aria-label="Масові дії">
                    <span className="offer-bulk-bar__count">Обрано {selectedCount}</span>
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={bulkBusy}
                        onClick={() => runBulkAction('check_availability')}
                    >
                        {bulkBusy ? 'Запуск…' : 'Перевірити доступність'}
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        disabled={bulkBusy}
                        onClick={() => runBulkAction('submit_gsc')}
                    >
                        {bulkBusy ? 'Запуск…' : 'Переіндексація'}
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        disabled={bulkBusy}
                        onClick={() => runBulkAction('redeploy')}
                    >
                        {bulkBusy ? 'Запуск…' : 'Редеплой'}
                    </button>
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={bulkBusy}
                        onClick={() => {
                            setCfSwitchOpen(false);
                            setServerSwitchOpen(false);
                            setRebindOpen((open) => !open);
                        }}
                    >
                        A-запис (IP)
                    </button>
                    {rebindOpen && (
                        <div className="offer-bulk-bar__rebind">
                            <input
                                type="text"
                                inputMode="decimal"
                                autoComplete="off"
                                placeholder="Новий IP, напр. 91.224.92.135"
                                value={bulkIp}
                                list="offer-bulk-ip-presets"
                                onChange={(e) => setBulkIp(e.target.value)}
                                aria-label="IPv4 нового сервера"
                            />
                            {panelOptions.length > 0 && (
                                <datalist id="offer-bulk-ip-presets">
                                    {panelOptions.map((panel) => (
                                        <option key={panel} value={panel} />
                                    ))}
                                </datalist>
                            )}
                            <button
                                type="button"
                                className="btn btn-primary btn-sm"
                                disabled={bulkBusy}
                                onClick={() => runBulkAction('rebind_dns')}
                            >
                                Змінити A
                            </button>
                        </div>
                    )}
                    {canSwitchCloudflarePool && (
                        <>
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={bulkBusy}
                        onClick={() => {
                            setRebindOpen(false);
                            setServerSwitchOpen(false);
                            setCfSwitchOpen((open) => !open);
                        }}
                    >
                        CF акаунт
                    </button>
                    {cfSwitchOpen && (
                        <div className="offer-bulk-bar__rebind">
                            <button
                                type="button"
                                className="btn btn-primary btn-sm"
                                disabled={bulkBusy || !selectedCfContext.hasPrimary}
                                title={
                                    !selectedCfContext.hasPrimary
                                        ? 'Немає основного CF у Settings власника'
                                        : (selectedCfContext.ownerLabel
                                            ? `CF власника: ${selectedCfContext.ownerLabel}`
                                            : undefined)
                                }
                                onClick={() => runBulkAction('switch_cloudflare', { cloudflare_target: 'primary' })}
                            >
                                → {selectedCfContext.primaryName}
                            </button>
                            {spareAccounts.length === 0 ? (
                                <span className="field-hint">Додай запасні в «Запасні CF»</span>
                            ) : spareAccounts.map((account) => (
                                <button
                                    key={account.id}
                                    type="button"
                                    className="btn btn-primary btn-sm"
                                    disabled={bulkBusy}
                                    title={`${account.account_id} · ${account.offers_count} оферів`}
                                    onClick={() => runBulkAction('switch_cloudflare', { cloudflare_target: `spare:${account.id}` })}
                                >
                                    → {account.name}
                                </button>
                            ))}
                        </div>
                    )}
                        </>
                    )}
                    {originTargets.length > 0 && (
                        <>
                            <button
                                type="button"
                                className="btn btn-ghost btn-sm"
                                disabled={bulkBusy}
                                onClick={() => {
                                    setRebindOpen(false);
                                    setCfSwitchOpen(false);
                                    setServerSwitchOpen((open) => !open);
                                }}
                            >
                                Сервер
                            </button>
                            {serverSwitchOpen && (
                                <div className="offer-bulk-bar__rebind">
                                    {originTargets.map((server) => {
                                        const already = allKnownSelectedOnHost(server.host);

                                        return (
                                            <button
                                                key={server.host}
                                                type="button"
                                                className="btn btn-primary btn-sm"
                                                disabled={bulkBusy || already}
                                                title={
                                                    already
                                                        ? `Вже на ${server.host}`
                                                        : `${server.host} · ${server.offers_count} оферів${server.role === 'spare' ? ' · запасний' : ''}`
                                                }
                                                onClick={() => runBulkAction('move_server', { target_host: server.host })}
                                            >
                                                → {originButtonLabel(server)}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </>
                    )}
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={bulkBusy}
                        onClick={() => {
                            setSelectedIds([]);
                            setRebindOpen(false);
                            setCfSwitchOpen(false);
                            setServerSwitchOpen(false);
                        }}
                    >
                        Скинути
                    </button>
                </div>
            )}

            <div className="table-wrap offers-table-desktop">
                <table>
                    <thead>
                        <tr>
                            <th className="col-select">
                                <input
                                    ref={selectAllRef}
                                    type="checkbox"
                                    checked={allPageSelected}
                                    disabled={selectableIds.length === 0}
                                    onChange={(e) => toggleSelectPage(e.target.checked)}
                                    aria-label="Select all on this page"
                                />
                            </th>
                            <th className="col-num" title={`Total: ${total}`}>#</th>
                            {colVisible('user') && showUserColumn && <th>User</th>}
                            {colVisible('brand') && <th>Brand</th>}
                            {colVisible('domain') && <th>Domain</th>}
                            {colVisible('geo') && <th>GEO</th>}
                            {colVisible('lang') && <th>Lang</th>}
                            {colVisible('template') && <th>Template</th>}
                            {colVisible('panel') && <th>Server</th>}
                            {colVisible('cloudflare') && <th>Cloudflare</th>}
                            {colVisible('dynadot') && <th>Dynadot</th>}
                            {colVisible('cwv') && (
                                <th
                                    className="col-cwv"
                                    title="CWV (Core Web Vitals) — CDN-скрипт на ленді: детекція дзеркал і повернення трафіку з копій"
                                >
                                    CWV
                                </th>
                            )}
                            {colVisible('created') && <th>Created</th>}
                            {colVisible('deployed') && <th className="col-deployed">Deployed</th>}
                            {colVisible('status') && <th>Status</th>}
                            {colVisible('dns') && <th>DNS</th>}
                            {colVisible('indexing') && (
                                <th className="col-index" title="Sitemap submitted to Search Console">Submitted</th>
                            )}
                            {colVisible('google_index') && (
                                <th className="col-index" title="Google index: indexed, pending, or not indexed">Indexed</th>
                            )}
                            {colVisible('actions') && <th />}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((offer, index) => {
                            const rowNumber = (currentPage - 1) * perPage + index + 1;

                            return (
                                <tr key={offer.folder} className={selectedIds.includes(offer.id) ? 'is-selected' : undefined}>
                                    <td className="col-select">
                                        {selectableIds.includes(offer.id) ? (
                                            <input
                                                type="checkbox"
                                                checked={selectedIds.includes(offer.id)}
                                                onChange={() => toggleSelected(offer.id)}
                                                aria-label={`Обрати ${offer.domain}`}
                                            />
                                        ) : null}
                                    </td>
                                    <td className="col-num">{rowNumber}</td>
                                    {colVisible('user') && showUserColumn && (
                                        <td>
                                            <span className="offer-user" title={offer.user_email}>
                                                {offer.user_name ?? offer.user_email ?? '—'}
                                            </span>
                                        </td>
                                    )}
                                    {colVisible('brand') && <td>{offer.brand}</td>}
                                    {colVisible('domain') && (
                                        <td>
                                            <div className="domain-cell">
                                                {availabilityDot(offer, {
                                                    busy: checkingAvailabilityId === offer.id,
                                                    onClick: canManageOffer(offer) ? checkAvailability : undefined,
                                                })}
                                                <a
                                                    href={`https://${offer.domain}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    {offer.domain}
                                                </a>
                                                <button
                                                    type="button"
                                                    className={`domain-copy${copiedDomainId === offer.id ? ' is-copied' : ''}`}
                                                    onClick={() => copyDomainUrl(offer)}
                                                    title={`Копіювати https://${offer.domain}`}
                                                    aria-label={`Копіювати https://${offer.domain}`}
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
                                    )}
                                    {colVisible('geo') && <td>{offer.geo}</td>}
                                    {colVisible('lang') && <td>{offer.lang}</td>}
                                    {colVisible('template') && <td>{offer.template}</td>}
                                    {colVisible('panel') && <td>{offer.deploy_panel ?? '—'}</td>}
                                    {colVisible('cloudflare') && (
                                        <td>
                                            {offer.cloudflare_account ?? '—'}
                                            {offer.cloudflare_slot === 'backup' || offer.cloudflare_slot === 'spare' ? (
                                                <span className="muted" style={{ marginLeft: '0.35rem' }}>· запасний</span>
                                            ) : null}
                                        </td>
                                    )}
                                    {colVisible('dynadot') && <td>{offer.dynadot_account ?? '—'}</td>}
                                    {colVisible('cwv') && (
                                        <td
                                            className="col-cwv"
                                            title={
                                                offer.vitals_enabled
                                                    ? 'CWV увімкнено: CDN-скрипт на ленді (дзеркала / редірект з копій)'
                                                    : 'CWV вимкнено'
                                            }
                                        >
                                            {offer.vitals_enabled ? (
                                                <span className="cwv-mark cwv-mark--on" aria-label="CWV увімкнено">
                                                    ✓
                                                </span>
                                            ) : (
                                                <span className="cwv-mark cwv-mark--off" aria-label="CWV вимкнено">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    )}
                                    {colVisible('created') && (
                                        <td title={offer.date ?? undefined}>
                                            {formatCreatedDate(offer.date)}
                                        </td>
                                    )}
                                    {colVisible('deployed') && (
                                        <td className="col-deployed" title={offer.deployed_at ?? undefined}>
                                            {formatDeployedAt(offer.deployed_at)}
                                        </td>
                                    )}
                                    {colVisible('status') && (
                                        <td>
                                            {statusBadge(offer.status)}
                                            {infraBadge(offer) && (
                                                <div style={{ marginTop: '0.35rem' }}>{infraBadge(offer)}</div>
                                            )}
                                            {offer.infra_error && (
                                                <p className="field-hint" style={{ color: '#f87171', marginTop: '0.25rem' }}>
                                                    {formatOfferError(offer.infra_error)}
                                                </p>
                                            )}
                                            {offer.deploy_error && (
                                                <p className="field-hint" style={{ color: '#f87171', marginTop: '0.25rem' }}>
                                                    {formatOfferError(offer.deploy_error)}
                                                </p>
                                            )}
                                        </td>
                                    )}
                                    {colVisible('dns') && (
                                        <td className="col-dns">
                                            {dnsBadge(offer)}
                                            {offer.dns_error && (
                                                <p className="field-hint" style={{ color: '#f87171', marginTop: '0.25rem' }}>
                                                    {formatOfferError(offer.dns_error)}
                                                </p>
                                            )}
                                        </td>
                                    )}
                                    {colVisible('indexing') && (
                                        <td className="col-index">
                                            <div className="indexing-cell">
                                                {canManageOffer(offer) ? (
                                                    <label
                                                        className="indexing-check"
                                                        title={
                                                            offer.gsc_error
                                                                ? offer.gsc_error
                                                                : offer.submitted_for_indexing && offer.indexed_at
                                                                  ? `Submitted to GSC: ${offer.indexed_at}`
                                                                  : 'Tick if you submitted indexing manually (auto after DNS + green availability)'
                                                        }
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={Boolean(offer.submitted_for_indexing)}
                                                            disabled={indexingId === offer.id}
                                                            onChange={(e) => toggleIndexing(offer, e.target.checked)}
                                                        />
                                                    </label>
                                                ) : (
                                                    <span className="field-hint">
                                                        {offer.submitted_for_indexing ? 'yes' : '—'}
                                                    </span>
                                                )}
                                                {renderGscError(offer)}
                                            </div>
                                        </td>
                                    )}
                                    {colVisible('google_index') && (
                                        <td className="col-index">
                                            {(() => {
                                                const mark = indexedTick(offer);
                                                const canInspect = canManageOffer(offer);
                                                return (
                                                    <IndexTick
                                                        tone={mark.tone}
                                                        title={
                                                            canInspect
                                                                ? `${mark.title} · Натисни, щоб перевірити зараз`
                                                                : mark.title
                                                        }
                                                        label={mark.title}
                                                        busy={checkingIndexId === offer.id}
                                                        onClick={canInspect ? () => inspectIndex(offer) : undefined}
                                                    />
                                                );
                                            })()}
                                        </td>
                                    )}
                                    {colVisible('actions') && (
                                        <td>
                                            {renderOfferActions(offer)}
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={visibleDataColCount} className="field-hint">
                                    No offers found
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <div className="offers-mobile-list" aria-label="Offers">
                {rows.map((offer) => (
                    <article key={`m-${offer.folder}`} className={`offer-mobile-card${selectedIds.includes(offer.id) ? ' is-selected' : ''}`}>
                        <div className="offer-mobile-card__top">
                            {selectableIds.includes(offer.id) && (
                                <label className="offer-mobile-card__select">
                                    <input
                                        type="checkbox"
                                        checked={selectedIds.includes(offer.id)}
                                        onChange={() => toggleSelected(offer.id)}
                                        aria-label={`Обрати ${offer.domain}`}
                                    />
                                </label>
                            )}
                            <div className="offer-mobile-card__title">
                                <strong>{offer.brand}</strong>
                                <div className="domain-cell">
                                    {availabilityDot(offer, {
                                        busy: checkingAvailabilityId === offer.id,
                                        onClick: canManageOffer(offer) ? checkAvailability : undefined,
                                    })}
                                    <a href={`https://${offer.domain}`} target="_blank" rel="noreferrer">
                                        {offer.domain}
                                    </a>
                                    <button
                                        type="button"
                                        className={`domain-copy${copiedDomainId === offer.id ? ' is-copied' : ''}`}
                                        onClick={() => copyDomainUrl(offer)}
                                        aria-label={`Копіювати https://${offer.domain}`}
                                    >
                                        {copiedDomainId === offer.id ? '✓' : '⧉'}
                                    </button>
                                </div>
                            </div>
                            <div className="offer-mobile-card__badges">
                                {statusBadge(offer.status)}
                                {infraBadge(offer)}
                                {dnsBadge(offer)}
                            </div>
                        </div>

                        <dl className="offer-mobile-card__meta">
                            {showUserColumn && (
                                <div>
                                    <dt>User</dt>
                                    <dd>{offer.user_name ?? offer.user_email ?? '—'}</dd>
                                </div>
                            )}
                            <div>
                                <dt>GEO</dt>
                                <dd>{offer.geo} / {offer.lang}</dd>
                            </div>
                            <div>
                                <dt>Template</dt>
                                <dd>{offer.template}</dd>
                            </div>
                            <div>
                                <dt>Server</dt>
                                <dd>{offer.deploy_panel || '—'}</dd>
                            </div>
                            <div>
                                <dt>Created</dt>
                                <dd>{formatCreatedDate(offer.date)}</dd>
                            </div>
                        </dl>

                        {(offer.infra_error || offer.deploy_error || offer.dns_error) && (
                            <p className="field-hint" style={{ color: '#f87171', marginBottom: '0.65rem' }}>
                                {formatOfferError(offer.infra_error || offer.deploy_error || offer.dns_error)}
                            </p>
                        )}

                        <div className="offer-mobile-card__footer">
                            {canManageOffer(offer) ? (
                                <div className="indexing-cell">
                                    <label
                                        className="indexing-check"
                                        title={
                                            offer.gsc_error
                                                ? offer.gsc_error
                                                : offer.submitted_for_indexing && offer.indexed_at
                                                  ? `Submitted to GSC: ${offer.indexed_at}`
                                                  : 'Tick if you submitted indexing manually'
                                        }
                                    >
                                        <input
                                            type="checkbox"
                                            checked={Boolean(offer.submitted_for_indexing)}
                                            disabled={indexingId === offer.id}
                                            onChange={(e) => toggleIndexing(offer, e.target.checked)}
                                        />
                                        <span className="indexing-check__label">Submitted</span>
                                    </label>
                                </div>
                            ) : (
                                <span className="field-hint">
                                    Submitted: {offer.submitted_for_indexing ? 'yes' : '—'}
                                </span>
                            )}
                            {(() => {
                                const indexed = indexedTick(offer);
                                const canInspect = canManageOffer(offer);
                                return (
                                    <div className="offer-mobile-ticks">
                                        <span className="field-hint">Indexed</span>
                                        <IndexTick
                                            tone={indexed.tone}
                                            title={
                                                canInspect
                                                    ? `${indexed.title} · Натисни, щоб перевірити зараз`
                                                    : indexed.title
                                            }
                                            busy={checkingIndexId === offer.id}
                                            onClick={canInspect ? () => inspectIndex(offer) : undefined}
                                        />
                                    </div>
                                );
                            })()}
                            {renderGscError(offer)}
                            {renderOfferActions(offer)}
                        </div>
                    </article>
                ))}
                {rows.length === 0 && (
                    <p className="field-hint" style={{ padding: '1rem 0' }}>No offers found</p>
                )}
            </div>

            {lastPage > 1 && (
                <nav className="pagination-bar" aria-label="Offer pages">
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={currentPage <= 1}
                        onClick={() => reloadOffers({ page: currentPage - 1 }, { resetPage: false })}
                    >
                        ← Prev
                    </button>
                    <span className="pagination-bar__info">
                        Page {currentPage} of {lastPage}
                    </span>
                    <button
                        type="button"
                        className="btn btn-ghost btn-sm"
                        disabled={currentPage >= lastPage}
                        onClick={() => reloadOffers({ page: currentPage + 1 }, { resetPage: false })}
                    >
                        Next →
                    </button>
                </nav>
            )}
            </div>

            {editingOffer && (
                <OfferEditModal
                    offer={editingOffer}
                    geoPresets={geoPresets}
                    currencies={currencies}
                    templates={templates}
                    brandTemplateUsage={brandTemplateUsage}
                    onClose={() => setEditingOffer(null)}
                />
            )}
        </PanelLayout>
    );
}
