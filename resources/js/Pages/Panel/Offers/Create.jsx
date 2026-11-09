import PanelLayout from '@/Layouts/PanelLayout';
import PhoneGeoSelect, { normalizePhoneCountries, uniquePhonePresets, phoneOptionCode } from '@/Components/PhoneGeoSelect';
import TemplatePicker, { usedTemplateCountsForBrand, usedTemplatesForBrand } from '@/Components/TemplatePicker';
import { clearWizardState, draftHasProgress, loadWizardState, peekWizardDraft, saveWizardState, stripFreshQueryParam } from '@/lib/offerWizardStorage';
import { geoDepositMissingFromCatalog, lookupGeoDeposit } from '@/lib/geoDepositPrefs';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';

const steps = ['Основне', 'Ринок і шаблони', 'Інфра', 'Підсумок'];

const INFRA_TASKS = [
    { key: 'infra_cloudflare_zone', label: 'Cloudflare — створити DNS-зону' },
    { key: 'infra_cloudflare_dns', label: 'Cloudflare — A-записи @ і www', requiresZone: true },
    { key: 'infra_dynadot_ns', label: 'Dynadot — NS → Cloudflare', requiresZone: true },
    { key: 'infra_cloudflare_ssl', label: 'Cloudflare — SSL (flexible)', requiresZone: true },
    { key: 'infra_cloudflare_https', label: 'Cloudflare — HTTP → HTTPS', requiresZone: true },
    { key: 'infra_cloudflare_www_redirect', label: 'Cloudflare — www → домен (301)', requiresZone: true },
];

function defaultInfraOptions(enabled = false) {
    return {
        infra_cloudflare_zone: enabled,
        infra_cloudflare_dns: enabled,
        infra_dynadot_ns: enabled,
        infra_cloudflare_ssl: enabled,
        infra_cloudflare_https: enabled,
        infra_cloudflare_www_redirect: enabled,
    };
}

function anyInfraEnabled(data) {
    return INFRA_TASKS.some((task) => data[task.key]);
}

/** Псевдо-GEO для multilang: ім'я папки. У CRM країна = IP ліда. */
const MULTILANG_GEO = 'ML';

function isOverflowHubTemplate(templateId) {
    return templateId === 'multilang';
}

function isMlOfferTemplate(templateId) {
    return templateId === 'multilang' || templateId === 'vespera';
}

/** Vespera can sit in any GEO pack; generator pins ML / en. */
function templateAlwaysSelectable(templateId) {
    return isMlOfferTemplate(templateId);
}

function normalizeGeo(value) {
    return value.replace(/[^a-zA-Z]/g, '').toUpperCase().slice(0, 2);
}

/** Same slug Dynadot search uses for a brand without a zone. */
function domainSeedFromBrand(brand) {
    return String(brand || '').trim().toLowerCase().replace(/[^a-z0-9-]/g, '');
}

/** Postbacks often send "Frugacella | IT" — keep the brand, drop the GEO suffix. */
function sanitizeLinkBrand(brand, geo) {
    const trimmed = String(brand ?? '').trim();
    if (!trimmed) {
        return '';
    }

    const match = trimmed.match(/^(.*?)(?:\s*\|\s*([A-Za-z]{2}))$/);
    if (!match) {
        return trimmed;
    }

    const suffix = match[2].toUpperCase();
    const code = normalizeGeo(geo);
    if (!code || suffix === code) {
        return match[1].trim();
    }

    return trimmed;
}

function applyCreateLinkPrefill(prev, {
    brand,
    geo,
    lang,
    fromSearch,
    template,
    templates,
    geoPresets,
    geoMinDeposits,
}) {
    let next = { ...prev };

    if (template && templates.some((item) => item.id === template)) {
        next = {
            ...next,
            template,
            ...(isMlOfferTemplate(template)
                ? { geo: MULTILANG_GEO, lang: 'en', phone: 'ip' }
                : {}),
        };
    }

    if (brand) {
        next = { ...next, brand };
        if (!String(next.domain || '').trim()) {
            const seed = domainSeedFromBrand(brand);
            if (seed) {
                next = { ...next, domain: seed };
            }
        }
    }

    if (geo && !isMlOfferTemplate(next.template)) {
        const preset = geoPresets.find((item) => item.code === geo);
        const remembered = lookupGeoDeposit(geo, geoMinDeposits);
        next = {
            ...next,
            geo,
            lang: lang || preset?.lang || next.lang,
            phone: preset?.phone ?? geo.toLowerCase(),
            phone_countries: [preset?.phone ?? geo.toLowerCase()],
            min_deposit: remembered?.min_deposit || next.min_deposit,
            currency: remembered?.currency || preset?.currency || next.currency,
        };
    } else if (lang && !isMlOfferTemplate(next.template)) {
        next = { ...next, lang };
    }

    if (fromSearch) {
        next = { ...next, from_search_team: true };
    }

    return next;
}

function domainNameWithoutZone(value) {
    const host = String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/^https?:\/\//i, '')
        .split(/[/?#]/)[0] ?? '';

    if (!host.includes('.')) {
        return host;
    }

    return host.split('.')[0] ?? host;
}

function resolveMarket(geo, geoPresets, availableLanguages) {
    const code = normalizeGeo(geo);
    const preset = geoPresets.find((item) => item.code === code);
    const langCodes = availableLanguages.map((item) => item.code);
    const suggested = preset?.lang && langCodes.includes(preset.lang) ? preset.lang : availableLanguages[0]?.code;
    const phone = preset?.phone ?? code.toLowerCase();
    const currency = preset?.currency ?? null;

    return {
        geo: code,
        lang: suggested ?? '',
        phone,
        phone_countries: [phone],
        ...(currency ? { currency } : {}),
    };
}

function templateLabel(templates, templateId) {
    return templates.find((item) => item.id === templateId)?.name ?? templateId;
}

function formatUsedTemplatesHint(templates, usedTemplateIds, counts = {}) {
    return usedTemplateIds.map((id) => {
        const n = Number(counts[id]) || 0;
        const name = templateLabel(templates, id);

        return n > 1 ? `${name} ×${n}` : name;
    }).join(', ');
}

function formatDomainPrice(price) {
    const match = String(price).match(/([\d.]+)\s*in\s*([A-Z]{3})/i);

    if (match) {
        return `${match[1]} ${match[2]}`;
    }

    return String(price).slice(0, 40);
}

function parseDomainPriceAmount(price) {
    if (!price) {
        return null;
    }

    const text = String(price);
    const match = text.match(/Registration\s*Price:\s*(\d+(?:[.,]\d+)?)/i)
        || text.match(/(\d+(?:[.,]\d+)?)\s*in\s*[A-Z]{3}/i)
        || text.match(/(\d+(?:[.,]\d+)?)/);

    if (!match) {
        return null;
    }

    return Number.parseFloat(match[1].replace(',', '.'));
}

function parseDomainPriceCurrency(price) {
    const match = String(price ?? '').match(/\b([A-Z]{3})\b/);

    return match?.[1] ?? '';
}

const DOMAIN_BULK_PURCHASE_LIMIT = 10;

function templateSupportsLang(template, langCode) {
    if (!langCode) {
        return true;
    }

    return (template?.languages ?? []).some((item) => item.code === langCode);
}

function unionLanguages(templates) {
    const seen = new Map();

    templates.forEach((template) => {
        (template.languages ?? []).forEach((lang) => {
            if (!seen.has(lang.code)) {
                seen.set(lang.code, lang);
            }
        });
    });

    return [...seen.values()];
}

function makePackItem(domain, searchItem = null, { owned = false } = {}) {
    const price = searchItem?.price ?? null;
    const amount = parseDomainPriceAmount(price);
    const currency = parseDomainPriceCurrency(price);

    return {
        domain,
        template: '',
        price,
        amount,
        currency,
        purchaseStatus: owned ? 'owned' : 'pending',
        purchaseError: null,
    };
}

/** Round-robin among templates that support lang; prefer unused-for-brand. */
function assignTemplatesRoundRobin(domains, templates, usedTemplateIds, fallbackTemplate, langCode = '') {
    const eligible = langCode
        ? templates.filter((item) => templateSupportsLang(item, langCode))
        : templates;
    const fallback = (
        (fallbackTemplate && eligible.some((item) => item.id === fallbackTemplate))
            ? fallbackTemplate
            : null
    )
        || eligible[0]?.id
        || templates[0]?.id
        || 'default';
    const unused = eligible.filter((item) => !usedTemplateIds.includes(item.id));
    const pool = unused.length > 0 ? unused : eligible;
    const ids = pool.length > 0
        ? pool.map((item) => item.id)
        : [fallback];

    return domains.map((domain, index) => ({
        domain,
        template: ids[index % ids.length] ?? fallback,
    }));
}

function buildDefaults(templates, fromSearch = false) {
    const defaultTemplate = templates[0]?.id ?? 'default';

    return {
        brand: '',
        domain: '',
        min_deposit: '250',
        currency: 'EUR',
        template: defaultTemplate,
        geo: '',
        lang: '',
        phone: '',
        phone_countries: [],
        vitals_enabled: true,
        from_search_team: Boolean(fromSearch),
        ...defaultInfraOptions(false),
    };
}

export default function OffersCreate({
    settingsReady,
    hasDynadotApiKey = false,
    hasDynadotContactId = false,
    canProvisionInfrastructure = false,
    domainSearchTlds = [],
    affiliateTag = 'BRO',
    geoPresets,
    currencies = [],
    templates,
    brandTemplateUsage = {},
    fresh = false,
    initialTemplate = null,
    initialBrand = null,
    initialGeo = null,
    initialLang = null,
    initialFromSearch = false,
}) {
    const { errors, panel, auth } = usePage().props;
    const canDownloadOfferZip = Boolean(auth?.user?.can_download_offer_zip);
    const geoMinDeposits = panel?.geo_min_deposits || {};
    const defaults = useMemo(
        () => buildDefaults(templates, initialFromSearch),
        [templates, initialFromSearch],
    );
    const skipPersist = useRef(false);
    const freshHandled = useRef(false);
    const domainSearchResultsRef = useRef(null);
    const offerBulkPackRef = useRef(null);
    const lastPackHeightRef = useRef(0);
    const preservePackScrollRef = useRef(null);
    const initial = useMemo(() => (
        fresh
            ? { step: 0, data: defaults, bulkItems: [], domainPurchasedViaPanel: false }
            : loadWizardState(defaults)
    ), [fresh, defaults]);
    const [step, setStep] = useState(initial.step);
    const [domainSearching, setDomainSearching] = useState(false);
    const [domainPurchasing, setDomainPurchasing] = useState(null);
    const [domainBulkPurchasing, setDomainBulkPurchasing] = useState(false);
    const [domainSearchError, setDomainSearchError] = useState('');
    const [domainSearchResults, setDomainSearchResults] = useState(null);
    const [domainSearchResultsVisible, setDomainSearchResultsVisible] = useState(false);
    const [dynadotBalance, setDynadotBalance] = useState(null);
    const [dynadotBalanceLoading, setDynadotBalanceLoading] = useState(false);
    const [domainPurchasedViaPanel, setDomainPurchasedViaPanel] = useState(initial.domainPurchasedViaPanel);
    const [bulkItems, setBulkItems] = useState(initial.bulkItems);
    const [bulkSubmitting, setBulkSubmitting] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [brandLookup, setBrandLookup] = useState(null);
    const [brandLookupLoading, setBrandLookupLoading] = useState(false);
    const brandLookupSeq = useRef(0);
    const autoDomainFromBrandRef = useRef((() => {
        const seed = domainSeedFromBrand(initial.data.brand);
        const domain = String(initial.data.domain || '').trim();

        return seed !== '' && domain === seed ? domain : '';
    })());

    const { data, setData, post, processing, reset } = useForm(initial.data);

    const isBulkMode = bulkItems.length > 1;

    const selectedTemplate = useMemo(
        () => templates.find((item) => item.id === data.template) ?? templates[0],
        [templates, data.template],
    );

    const brandUsageMap = useMemo(() => {
        const brandKey = String(data.brand || '').trim().toLowerCase();
        const lookupKey = String(brandLookup?.brand || '').trim().toLowerCase();
        const lookupTemplates = brandLookup?.templates;

        if (
            brandKey
            && lookupKey === brandKey
            && lookupTemplates
            && typeof lookupTemplates === 'object'
            && !Array.isArray(lookupTemplates)
        ) {
            return { [brandKey]: lookupTemplates };
        }

        return brandTemplateUsage;
    }, [brandLookup, brandTemplateUsage, data.brand]);

    const usedTemplateIds = useMemo(
        () => usedTemplatesForBrand(brandUsageMap, data.brand),
        [brandUsageMap, data.brand],
    );

    const usedTemplateCounts = useMemo(
        () => usedTemplateCountsForBrand(brandUsageMap, data.brand),
        [brandUsageMap, data.brand],
    );

    const usedTemplatesHint = useMemo(
        () => formatUsedTemplatesHint(templates, usedTemplateIds, usedTemplateCounts),
        [templates, usedTemplateIds, usedTemplateCounts],
    );

    const bulkAllMultilang = isBulkMode
        && bulkItems.length > 0
        && bulkItems.every((item) => isOverflowHubTemplate(item.template));

    const isMarketMultilang = isBulkMode
        ? bulkAllMultilang
        : isOverflowHubTemplate(data.template);
    const packHasVespera = isBulkMode
        ? bulkItems.some((item) => item.template === 'vespera')
        : data.template === 'vespera';

    const availableLanguages = useMemo(() => {
        if (isMarketMultilang) {
            return selectedTemplate?.languages
                ?? templates.find((item) => item.id === 'multilang')?.languages
                ?? [];
        }

        // Мову обираємо до шаблонів — показуємо всі мови з каталогу.
        return unionLanguages(templates);
    }, [isMarketMultilang, selectedTemplate, templates]);

    const templatesForLang = useMemo(() => {
        if (!data.lang || isMarketMultilang) {
            return templates;
        }

        return templates.filter((item) => (
            templateAlwaysSelectable(item.id) || templateSupportsLang(item, data.lang)
        ));
    }, [templates, data.lang, isMarketMultilang]);

    const disabledTemplateIds = useMemo(() => {
        if (isMarketMultilang) {
            return [];
        }

        return templates
            .filter((item) => {
                if (templateAlwaysSelectable(item.id)) {
                    return false;
                }

                return !data.lang || !templateSupportsLang(item, data.lang);
            })
            .map((item) => item.id);
    }, [templates, data.lang, isMarketMultilang]);

    const packNeedsPurchase = useMemo(
        () => bulkItems.some((item) => item.purchaseStatus === 'pending' || item.purchaseStatus === 'error'),
        [bulkItems],
    );

    const packAllReady = useMemo(
        () => bulkItems.length > 0
            && bulkItems.every((item) => item.purchaseStatus === 'purchased' || item.purchaseStatus === 'owned'),
        [bulkItems],
    );

    const packTotal = useMemo(() => {
        const billable = bulkItems.filter((item) => (
            item.purchaseStatus === 'pending'
            || item.purchaseStatus === 'error'
            || item.purchaseStatus === 'buying'
        ));

        if (billable.length === 0) {
            return null;
        }

        let sum = 0;
        let priced = 0;
        let currency = '';

        billable.forEach((item) => {
            if (item.amount === null || item.amount === undefined || Number.isNaN(item.amount)) {
                return;
            }

            sum += item.amount;
            priced += 1;

            if (!currency && item.currency) {
                currency = item.currency;
            }
        });

        if (priced === 0) {
            return null;
        }

        return {
            amount: sum,
            currency,
            incomplete: priced < billable.length,
            count: billable.length,
        };
    }, [bulkItems]);

    const packInSearch = useMemo(
        () => new Set(bulkItems.map((item) => item.domain)),
        [bulkItems],
    );

    useEffect(() => {
        if (freshHandled.current) {
            return;
        }

        freshHandled.current = true;

        const geo = normalizeGeo(initialGeo ?? '');
        const brand = sanitizeLinkBrand(initialBrand ?? '', geo || initialGeo);
        const lang = (initialLang ?? '').trim().toLowerCase();
        const hasLinkPrefill = Boolean(brand || geo || lang || initialFromSearch || initialTemplate);

        const applyPrefill = (base = null) => {
            if (!hasLinkPrefill) {
                return;
            }

            setData((prev) => applyCreateLinkPrefill(base ?? prev, {
                brand,
                geo,
                lang,
                fromSearch: initialFromSearch,
                template: initialTemplate,
                templates,
                geoPresets,
                geoMinDeposits,
            }));

            if (brand) {
                void lookupBrand(brand);
            }
        };

        if (!fresh) {
            applyPrefill();
            return;
        }

        // Intentional "new funnel" — but never silently wipe a draft with domains/progress.
        const existing = peekWizardDraft(defaults);
        if (draftHasProgress(existing)) {
            const packCount = existing.bulkItems?.length ?? 0;
            const keepDraft = !window.confirm(
                packCount > 0
                    ? `Є незавершена чернетка (${packCount} домен(ів) у пакеті, у т.ч. куплені). Почати з нуля і втратити її?`
                    : 'Є незавершена чернетка створення офера. Почати з нуля і втратити її?',
            );

            if (keepDraft) {
                const restored = loadWizardState(defaults);
                setStep(restored.step);
                setBulkItems(restored.bulkItems);
                setDomainPurchasedViaPanel(restored.domainPurchasedViaPanel);
                setBulkSubmitting(false);
                skipPersist.current = false;
                if (hasLinkPrefill) {
                    applyPrefill(restored.data);
                } else {
                    setData(restored.data);
                    if (restored.data?.brand) {
                        void lookupBrand(restored.data.brand);
                    }
                }
                stripFreshQueryParam();
                return;
            }
        }

        skipPersist.current = true;
        clearWizardState();
        setStep(0);
        reset();
        setBulkItems([]);
        setBulkSubmitting(false);
        setDomainPurchasedViaPanel(false);
        stripFreshQueryParam();
        skipPersist.current = false;
        applyPrefill();
    }, [fresh, reset, initialTemplate, initialBrand, initialGeo, initialLang, initialFromSearch, templates, geoPresets, geoMinDeposits, setData, defaults]);

    useEffect(() => {
        if (bulkItems.length === 0) {
            return;
        }

        const firstTemplate = bulkItems[0]?.template;

        setData((prev) => {
            if (!isBulkMode && firstTemplate && prev.template !== firstTemplate) {
                return { ...prev, template: firstTemplate };
            }

            return prev;
        });
    }, [bulkItems, isBulkMode, setData]);

    useEffect(() => {
        if (skipPersist.current || processing || generating || bulkSubmitting) {
            return;
        }

        saveWizardState(step, data, { bulkItems, domainPurchasedViaPanel });
    }, [step, data, bulkItems, domainPurchasedViaPanel, processing, generating, bulkSubmitting]);

    useEffect(() => {
        const flushDraft = () => {
            if (skipPersist.current || processing || generating || bulkSubmitting) {
                return;
            }

            saveWizardState(step, data, { bulkItems, domainPurchasedViaPanel });
        };

        const onVisibility = () => {
            if (document.visibilityState === 'hidden') {
                flushDraft();
            }
        };

        window.addEventListener('pagehide', flushDraft);
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            window.removeEventListener('pagehide', flushDraft);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [step, data, bulkItems, domainPurchasedViaPanel, processing, generating, bulkSubmitting]);

    const discardDraft = () => {
        if (!draftHasProgress({ step, data, bulkItems, domainPurchasedViaPanel })) {
            return;
        }

        if (!window.confirm('Очистити чернетку створення офера (пакет доменів теж)?')) {
            return;
        }

        skipPersist.current = true;
        clearWizardState();
        setStep(0);
        reset();
        setBulkItems([]);
        setBulkSubmitting(false);
        setDomainPurchasedViaPanel(false);
        setDomainSearchResults(null);
        setDomainSearchError('');
        skipPersist.current = false;
    };

    const goToStep = (nextStep) => {
        setStep(nextStep);
    };

    const updateTemplate = (templateId) => {
        const template = templates.find((item) => item.id === templateId);
        const langs = template?.languages ?? [];
        const wasHub = isOverflowHubTemplate(data.template);
        const isHub = isOverflowHubTemplate(templateId);
        const lang = isHub
            ? (langs.some((item) => item.code === 'en') ? 'en' : (langs[0]?.code ?? ''))
            : (data.lang && (templateAlwaysSelectable(templateId) || langs.some((item) => item.code === data.lang))
                ? data.lang
                : '');

        setData((prev) => ({
            ...prev,
            template: templateId,
            lang,
            ...(isHub
                ? {
                    geo: MULTILANG_GEO,
                    phone: 'ip',
                    phone_countries: uniquePhonePresets(geoPresets).map((item) => phoneOptionCode(item)),
                }
                : wasHub && !templateAlwaysSelectable(templateId)
                    ? { geo: '', phone: '', phone_countries: [] }
                    : {}),
        }));

        if (bulkItems.length === 1) {
            setBulkItems((prev) => prev.map((item, index) => (
                index === 0 ? { ...item, template: templateId } : item
            )));
        }
    };

    const phoneOptions = useMemo(() => uniquePhonePresets(geoPresets), [geoPresets]);

    useEffect(() => {
        // Multilang: SITE_LANG фіксуємо в en, але phone_countries дозволяємо всі (dropdown на клієнті).
        if (!isMarketMultilang) return;

        const allPhoneCodes = phoneOptions.map((item) => phoneOptionCode(item));
        if (allPhoneCodes.length === 0) return;

        setData((prev) => ({
            ...prev,
            geo: MULTILANG_GEO,
            lang: 'en',
            phone: 'ip',
            phone_countries: allPhoneCodes,
            // Дефолтний phone — IP; whitelist — усі пресети.
            ...(prev.phone && allPhoneCodes.includes(prev.phone) ? {} : {}),
        }));
    }, [isMarketMultilang, phoneOptions, setData]);

    const updateGeo = (raw) => {
        if (isMarketMultilang) {
            return;
        }

        const code = normalizeGeo(raw);

        if (code.length === 0) {
            setData((prev) => ({
                ...prev,
                geo: '',
                lang: '',
                phone: '',
                phone_countries: [],
            }));
            return;
        }

        if (code.length < 2) {
            setData((prev) => ({
                ...prev,
                geo: code,
                lang: '',
                phone: '',
                phone_countries: [],
            }));
            return;
        }

        const resolved = resolveMarket(code, geoPresets, availableLanguages);
        const remembered = lookupGeoDeposit(resolved.geo, geoMinDeposits);
        setData((prev) => {
            const countries = new Set([
                ...normalizePhoneCountries(prev.phone_countries, prev.phone),
                ...(resolved.phone ? [resolved.phone] : []),
            ]);
            const list = [...countries];

            return {
                ...prev,
                geo: resolved.geo,
                min_deposit: remembered?.min_deposit || prev.min_deposit,
                currency: remembered?.currency || resolved.currency || prev.currency,
                phone_countries: list.length > 0 ? list : (resolved.phone ? [resolved.phone] : []),
                phone: resolved.phone,
                lang: resolved.lang,
            };
        });
    };
    const selectedPhones = normalizePhoneCountries(data.phone_countries, data.phone);

    const togglePhoneCountry = (code) => {
        const normalized = code.toLowerCase();
        setData((prev) => {
            const current = normalizePhoneCountries(prev.phone_countries, prev.phone);
            const set = new Set(current);

            if (set.has(normalized)) {
                if (set.size <= 1) {
                    return prev;
                }
                set.delete(normalized);
            } else {
                set.add(normalized);
            }

            const list = [...set];
            const phone = prev.phone === 'ip'
                ? 'ip'
                : (list.includes(prev.phone) ? prev.phone : list[0]);

            return { ...prev, phone_countries: list, phone };
        });
    };

    const selectAllPhoneCountries = (codes) => {
        const list = normalizePhoneCountries(codes);
        if (list.length === 0) {
            return;
        }
        setData((prev) => ({
            ...prev,
            phone_countries: list,
            phone: prev.phone === 'ip' || list.includes(prev.phone) ? prev.phone : 'ip',
        }));
    };

    const clearPhoneCountries = () => {
        setData((prev) => {
            const keep = prev.phone === 'ip'
                ? (normalizePhoneCountries(prev.phone_countries)[0] || 'gb')
                : (prev.phone && /^[a-z]{2}$/.test(prev.phone) ? prev.phone : 'gb');

            return {
                ...prev,
                phone_countries: [keep],
                phone: prev.phone === 'ip' ? 'ip' : keep,
            };
        });
    };

    const update = (field, value) => {
        setData(field, value);
        if (field === 'brand') {
            setBrandLookup(null);
        }
    };

    const applyBrandToDomain = (brandValue) => {
        const seed = domainSeedFromBrand(brandValue);
        if (!seed) {
            return;
        }

        const current = String(data.domain || '').trim();
        if (current !== '' && current !== autoDomainFromBrandRef.current) {
            return;
        }

        autoDomainFromBrandRef.current = seed;
        if (current === seed) {
            return;
        }

        setData('domain', seed);
        setDomainSearchResults(null);
        setDomainSearchResultsVisible(false);
    };

    const lookupBrand = async (brandOverride) => {
        const brand = String(brandOverride ?? data.brand).trim();
        if (!brand) {
            setBrandLookup(null);
            setBrandLookupLoading(false);
            return;
        }

        const seq = ++brandLookupSeq.current;
        setBrandLookupLoading(true);
        try {
            const { data: result } = await axios.get(route('offers.brand-lookup'), {
                params: { brand },
            });
            if (seq !== brandLookupSeq.current) {
                return;
            }
            setBrandLookup({
                brand: result.brand ?? brand,
                active: Number(result.active) || 0,
                archived: Number(result.archived) || 0,
                templates: (
                    result.templates
                    && typeof result.templates === 'object'
                    && !Array.isArray(result.templates)
                ) ? result.templates : {},
            });
        } catch {
            if (seq !== brandLookupSeq.current) {
                return;
            }
            setBrandLookup(null);
        } finally {
            if (seq === brandLookupSeq.current) {
                setBrandLookupLoading(false);
            }
        }
    };

    const domainSearchQuery = useMemo(() => {
        const brand = data.brand.trim();
        const domain = data.domain.trim();
        return domain || brand;
    }, [data.brand, data.domain]);

    const domainSearchHasZone = useMemo(() => {
        const query = domainSearchQuery.replace(/^https?:\/\//i, '').split(/[/?#]/)[0] ?? '';
        return query.includes('.');
    }, [domainSearchQuery]);

    useEffect(() => {
        if (!domainSearchResults?.length || !domainSearchResultsVisible) {
            return;
        }

        const hideResults = (event) => {
            if (domainSearchResultsRef.current?.contains(event.target)) {
                return;
            }

            setDomainSearchResultsVisible(false);
        };

        document.addEventListener('mousedown', hideResults);
        document.addEventListener('touchstart', hideResults);

        return () => {
            document.removeEventListener('mousedown', hideResults);
            document.removeEventListener('touchstart', hideResults);
        };
    }, [domainSearchResults, domainSearchResultsVisible]);

    useLayoutEffect(() => {
        const el = offerBulkPackRef.current;
        const height = el && bulkItems.length > 0 ? el.offsetHeight : 0;
        const prev = lastPackHeightRef.current;
        const delta = height - prev;
        const preserved = preservePackScrollRef.current;

        if (preserved) {
            // Keep the domain results under the cursor after the pack card grows/shrinks
            // above them (and avoid the browser jumping to the focused #domain input).
            window.scrollTo(0, Math.max(0, preserved.windowY + delta));
            const list = domainSearchResultsRef.current?.querySelector?.('.domain-search-results');
            if (list && typeof preserved.listY === 'number') {
                list.scrollTop = preserved.listY;
            }
            preservePackScrollRef.current = null;
        } else if (delta !== 0) {
            window.scrollBy(0, delta);
        }

        lastPackHeightRef.current = height;
    }, [bulkItems]);

    const rememberPackScroll = () => {
        const active = document.activeElement;
        if (active instanceof HTMLElement && active.id === 'domain') {
            active.blur();
        }

        const list = domainSearchResultsRef.current?.querySelector?.('.domain-search-results');
        preservePackScrollRef.current = {
            windowY: window.scrollY,
            listY: list ? list.scrollTop : null,
        };
    };

    const searchDomains = async () => {
        const query = domainSearchQuery;

        if (query.length < 2) {
            setDomainSearchError('Введіть назву бренду або домен (мін. 2 символи).');
            return;
        }

        setDomainSearching(true);
        setDomainSearchError('');
        setDomainSearchResults(null);
        setDomainSearchResultsVisible(false);

        try {
            const { data: result } = await axios.post(route('domains.search'), { query });
            if (!result.ok) {
                setDomainSearchError(result.message ?? 'Помилка пошуку');
                return;
            }
            setDomainSearchResults(result.results ?? []);
            setDomainSearchResultsVisible(true);
        } catch (error) {
            setDomainSearchError(
                error.response?.data?.message ?? 'Не вдалося виконати пошук доменів',
            );
        } finally {
            setDomainSearching(false);
        }
    };

    const addDomainToPack = (searchItem, { owned = false } = {}) => {
        const domain = searchItem.domain;

        if (bulkItems.some((item) => item.domain === domain)) {
            setDomainSearchError(`${domain} уже в пакеті.`);
            return;
        }

        if (bulkItems.length >= DOMAIN_BULK_PURCHASE_LIMIT) {
            setDomainSearchError(`У пакеті вже максимум ${DOMAIN_BULK_PURCHASE_LIMIT} доменів.`);
            return;
        }

        rememberPackScroll();
        setDomainSearchError('');
        setBulkItems((prev) => [...prev, makePackItem(domain, searchItem, { owned })]);
    };

    const addAllAvailableToPack = () => {
        const available = (domainSearchResults ?? []).filter((item) => item.available);

        if (available.length === 0) {
            setDomainSearchError('Немає вільних доменів у результатах.');
            return;
        }

        const existing = new Set(bulkItems.map((item) => item.domain));
        const room = DOMAIN_BULK_PURCHASE_LIMIT - bulkItems.length;

        if (room <= 0) {
            setDomainSearchError(`У пакеті вже максимум ${DOMAIN_BULK_PURCHASE_LIMIT} доменів.`);
            return;
        }

        const toAdd = [];
        for (const item of available) {
            if (existing.has(item.domain)) {
                continue;
            }
            if (toAdd.length >= room) {
                break;
            }
            toAdd.push(makePackItem(item.domain, item));
        }

        if (toAdd.length === 0) {
            setDomainSearchError('Усі вільні домени вже в пакеті.');
            return;
        }

        const skipped = available.filter((item) => !existing.has(item.domain)).length - toAdd.length;
        if (skipped > 0) {
            setDomainSearchError(
                `Додано ${toAdd.length} (ліміт пакету ${DOMAIN_BULK_PURCHASE_LIMIT}).`,
            );
        } else {
            setDomainSearchError(`Додано до пакету: ${toAdd.map((item) => item.domain).join(', ')}`);
        }

        rememberPackScroll();
        setBulkItems((prev) => [...prev, ...toAdd]);
    };

    const availableNotInPackCount = useMemo(() => {
        if (!domainSearchResults) {
            return 0;
        }

        return domainSearchResults.filter((item) => (
            item.available && !packInSearch.has(item.domain)
        )).length;
    }, [domainSearchResults, packInSearch]);

    const addTypedDomainToPack = ({ owned = true } = {}) => {
        const domain = data.domain.trim().toLowerCase().replace(/^https?:\/\//i, '').split(/[/?#]/)[0];

        if (!domain.includes('.')) {
            setDomainSearchError('Вкажіть повний домен (з зоною), щоб додати до пакету.');
            return;
        }

        const fromSearch = (domainSearchResults ?? []).find((item) => item.domain === domain);
        addDomainToPack(
            fromSearch ?? { domain, price: null, available: !owned },
            { owned: owned || fromSearch?.available === false },
        );
    };

    const removeBulkItem = (domain) => {
        setBulkItems((prev) => prev.filter((item) => item.domain !== domain));
    };

    const updateBulkItemTemplate = (domain, templateId) => {
        setBulkItems((prev) => prev.map((item) => (
            item.domain === domain ? { ...item, template: templateId } : item
        )));
    };

    const applyFirstTemplateToAll = () => {
        const templateId = bulkItems[0]?.template;
        if (!templateId) {
            return;
        }
        setBulkItems((prev) => prev.map((item) => ({ ...item, template: templateId })));
    };

    const ensureBulkTemplatesForLang = (langCode) => {
        if (!langCode || bulkItems.length === 0) {
            return;
        }

        setBulkItems((prev) => {
            const needsAssign = prev.some((item) => {
                if (!item.template) {
                    return true;
                }

                const template = templates.find((row) => row.id === item.template);

                return !templateAlwaysSelectable(item.template) && !templateSupportsLang(template, langCode);
            });

            if (!needsAssign) {
                return prev;
            }

            const packUsed = [
                ...usedTemplatesForBrand(brandUsageMap, data.brand),
            ];
            const assigned = assignTemplatesRoundRobin(
                prev.map((item) => item.domain),
                templates,
                packUsed,
                data.template || templates[0]?.id,
                langCode,
            );

            return prev.map((item, index) => (
                templateAlwaysSelectable(item.template)
                    ? item
                    : {
                        ...item,
                        template: assigned[index]?.template || item.template,
                    }
            ));
        });
    };

    const loadDynadotBalance = async () => {
        if (!hasDynadotApiKey) {
            return;
        }

        setDynadotBalanceLoading(true);

        try {
            const { data: result } = await axios.get(route('domains.balance'));
            if (result.ok) {
                setDynadotBalance(result.balance ?? null);
            }
        } catch {
            // Balance is optional in the wizard.
        } finally {
            setDynadotBalanceLoading(false);
        }
    };

    const purchasePackDomains = async () => {
        if (!hasDynadotContactId) {
            setDomainSearchError('Вкажіть Dynadot Contact ID у налаштуваннях перед покупкою.');
            return;
        }

        const items = bulkItems.filter((item) => (
            item.purchaseStatus === 'pending' || item.purchaseStatus === 'error'
        ));

        if (items.length === 0) {
            setDomainSearchError('У пакеті немає доменів для купівлі.');
            return;
        }

        const totalHint = packTotal
            ? ` Приблизно ${packTotal.incomplete ? '≈ ' : ''}${packTotal.amount.toFixed(2)}${packTotal.currency ? ` ${packTotal.currency}` : ''}.`
            : '';

        if (!window.confirm(
            `Купити ${items.length} домен(и) на 1 рік без автопродовження?${totalHint}\n${items.map((item) => `• ${item.domain}`).join('\n')}\nСписання з балансу Dynadot.`,
        )) {
            return;
        }

        setDomainBulkPurchasing(true);
        setDomainSearchError('');

        let boughtCount = 0;
        let primaryDomain = null;
        const failed = [];

        try {
            for (let index = 0; index < items.length; index += 1) {
                const item = items[index];
                setDomainPurchasing(item.domain);
                setBulkItems((prev) => prev.map((row) => (
                    row.domain === item.domain
                        ? { ...row, purchaseStatus: 'buying', purchaseError: null }
                        : row
                )));

                try {
                    const { data: result } = await axios.post(route('domains.purchase'), { domain: item.domain });

                    if (!result.ok) {
                        const message = result.message ?? 'Не вдалося купити домен';
                        failed.push({ domain: item.domain, message });
                        setBulkItems((prev) => prev.map((row) => (
                            row.domain === item.domain
                                ? { ...row, purchaseStatus: 'error', purchaseError: message }
                                : row
                        )));
                        continue;
                    }

                    const purchasedDomain = result.result?.domain ?? item.domain;
                    boughtCount += 1;
                    if (!primaryDomain) {
                        primaryDomain = purchasedDomain;
                    }
                    setDomainPurchasedViaPanel(true);
                    setBulkItems((prev) => prev.map((row) => (
                        row.domain === item.domain
                            ? {
                                ...row,
                                domain: purchasedDomain,
                                purchaseStatus: 'purchased',
                                purchaseError: null,
                            }
                            : row
                    )));
                    setDomainSearchResults((prev) => (prev ?? []).map((row) => (
                        row.domain === item.domain || row.domain === purchasedDomain
                            ? { ...row, available: false, status: 'taken', message: null }
                            : row
                    )));
                } catch (error) {
                    const message = error.response?.data?.message ?? 'Не вдалося купити домен';
                    failed.push({ domain: item.domain, message });
                    setBulkItems((prev) => prev.map((row) => (
                        row.domain === item.domain
                            ? { ...row, purchaseStatus: 'error', purchaseError: message }
                            : row
                    )));
                }
            }

            if (boughtCount > 0) {
                setDomainSearchResults(null);
                setDomainSearchResultsVisible(false);
                const nameOnly = domainNameWithoutZone(primaryDomain);
                if (nameOnly) {
                    update('domain', nameOnly);
                }
            }

            const parts = [];
            if (boughtCount > 0) {
                parts.push(`Куплено: ${boughtCount}`);
            }
            if (failed.length > 0) {
                parts.push(`Не вдалося: ${failed.map((item) => `${item.domain} (${item.message})`).join('; ')}`);
            }
            setDomainSearchError(parts.join('. ') || '');
            await loadDynadotBalance();

            // Після купівлі лишаємось на кроці 1 — можна ще додати домени вручну.
        } finally {
            setDomainPurchasing(null);
            setDomainBulkPurchasing(false);
        }
    };

    const markPackItemOwned = (domain) => {
        setBulkItems((prev) => prev.map((item) => (
            item.domain === domain
                ? { ...item, purchaseStatus: 'owned', purchaseError: null, amount: null, price: null }
                : item
        )));
    };

    const toggleInfraOption = (key, checked) => {
        setData((prev) => {
            const task = INFRA_TASKS.find((row) => row.key === key);
            const next = { ...prev, [key]: checked };

            if (!checked && key === 'infra_cloudflare_zone') {
                INFRA_TASKS.forEach((row) => {
                    if (row.requiresZone) {
                        next[row.key] = false;
                    }
                });
            }

            if (checked && task?.requiresZone) {
                next.infra_cloudflare_zone = true;
            }

            return next;
        });
    };

    const enableAllInfraOptions = () => {
        setData((prev) => ({
            ...prev,
            ...defaultInfraOptions(true),
        }));
    };

    useEffect(() => {
        if (canProvisionInfrastructure && domainPurchasedViaPanel && !anyInfraEnabled(data)) {
            enableAllInfraOptions();
        }
    }, [canProvisionInfrastructure, domainPurchasedViaPanel]);

    useEffect(() => {
        if (hasDynadotApiKey && step === 0) {
            loadDynadotBalance();
        }
    }, [hasDynadotApiKey, step]);

    useEffect(() => {
        if (data.brand.trim()) {
            void lookupBrand();
        }
        // Restored draft: one lookup so «вже є» is ready before the template step.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (step !== 1 || !data.brand.trim()) {
            return;
        }

        const lookupKey = String(brandLookup?.brand || '').trim().toLowerCase();
        if (lookupKey === data.brand.trim().toLowerCase()) {
            return;
        }

        void lookupBrand();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [step]);

    const folderPreview = useMemo(() => {
        if (!data.brand || !data.domain || !data.geo) return '…';
        const brandSlug = data.brand.trim().replace(/\s+/g, '-');
        const date = new Date().toISOString().slice(0, 10);
        return `${data.geo}_${data.lang}_${affiliateTag}_${brandSlug}_${data.domain}_${date}`;
    }, [data, affiliateTag]);

    const canProceedStep0 = Boolean(data.brand.trim()) && (
        bulkItems.length > 0
            ? packAllReady
            : Boolean(data.domain.trim())
    );
    const canProceedTemplate = bulkItems.length > 0
        ? bulkItems.every((item) => {
            if (!item.template) {
                return false;
            }
            if (isMlOfferTemplate(item.template) || !data.lang || isMarketMultilang) {
                return true;
            }
            const template = templates.find((row) => row.id === item.template);

            return templateSupportsLang(template, data.lang);
        })
            && templates.length > 0
        : Boolean(data.template)
            && templates.length > 0
            && (
                isMarketMultilang
                || templateAlwaysSelectable(data.template)
                || !data.lang
                || templateSupportsLang(selectedTemplate, data.lang)
            );
    const langInIntersection = Boolean(
        data.lang && availableLanguages.some((item) => item.code === data.lang),
    );
    const canProceedMarket = data.geo.length === 2
        && data.lang
        && data.phone
        && selectedPhones.length > 0
        && availableLanguages.length > 0
        && langInIntersection;
    const canProceedStep1 = canProceedTemplate && canProceedMarket;

    const generateBlockReason = useMemo(() => {
        if (!settingsReady) {
            return 'Збережіть CRM API key і Telegram bot token у налаштуваннях.';
        }
        if (!canProceedStep0) {
            return isBulkMode || bulkItems.length > 0
                ? (packNeedsPurchase
                    ? 'Спочатку купіть домени в пакеті (крок 1).'
                    : 'Заповніть бренд і зберіть пакет доменів (крок 1).')
                : 'Заповніть бренд і домен (крок 1).';
        }
        if (!canProceedTemplate) {
            return isBulkMode
                ? 'Оберіть шаблон з підтримкою мови для кожного домену (крок 2).'
                : 'Оберіть шаблон з підтримкою мови (крок 2).';
        }
        if (templatesForLang.length === 0 && data.lang && !isMarketMultilang) {
            return 'Жоден шаблон не підтримує обрану мову (крок 2).';
        }
        if (!canProceedMarket) {
            return 'Перевірте GEO, мову та phone GEO (крок 2).';
        }

        return null;
    }, [
        settingsReady,
        canProceedStep0,
        canProceedTemplate,
        canProceedMarket,
        isBulkMode,
        packNeedsPurchase,
        bulkItems.length,
        templatesForLang.length,
        data.lang,
        data.template,
        isMarketMultilang,
    ]);

    const submitErrors = useMemo(
        () => Object.entries(errors).filter(([, message]) => Boolean(message)),
        [errors],
    );

    const firstSubmitError = submitErrors[0]?.[1] ?? null;

    useEffect(() => {
        if (step === steps.length - 1 && (firstSubmitError || generateBlockReason)) {
            document.getElementById('wizard-submit-feedback')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, [step, firstSubmitError, generateBlockReason]);

    const generate = (downloadZip = false) => {
        if (generateBlockReason) {
            return;
        }

        const onSuccess = () => {
            skipPersist.current = true;
            clearWizardState();
            setStep(0);
            reset();
            setBulkItems([]);
            setBulkSubmitting(false);
            setGenerating(false);
            setDomainPurchasedViaPanel(false);
        };

        if (isBulkMode) {
            setBulkSubmitting(true);
            router.post(route('offers.bulk'), {
                brand: data.brand,
                min_deposit: data.min_deposit,
                currency: data.currency,
                geo: data.geo,
                lang: data.lang,
                phone: data.phone,
                phone_countries: data.phone_countries,
                vitals_enabled: data.vitals_enabled,
                from_search_team: data.from_search_team,
                infra_cloudflare_zone: data.infra_cloudflare_zone,
                infra_cloudflare_dns: data.infra_cloudflare_dns,
                infra_dynadot_ns: data.infra_dynadot_ns,
                infra_cloudflare_ssl: data.infra_cloudflare_ssl,
                infra_cloudflare_https: data.infra_cloudflare_https,
                infra_cloudflare_www_redirect: data.infra_cloudflare_www_redirect,
                items: bulkItems.map(({ domain, template }) => ({ domain, template })),
            }, {
                preserveScroll: true,
                onSuccess,
                onError: () => {
                    setStep(steps.length - 1);
                },
                onFinish: () => {
                    setBulkSubmitting(false);
                },
            });
            return;
        }

        setGenerating(true);

        if (bulkItems.length === 1) {
            router.post(route('offers.store'), {
                ...data,
                domain: bulkItems[0].domain,
                template: bulkItems[0].template || data.template,
                download_zip: downloadZip,
            }, {
                preserveScroll: true,
                onSuccess,
                onError: () => {
                    setStep(steps.length - 1);
                },
                onFinish: () => setGenerating(false),
            });
            return;
        }

        router.post(route('offers.store'), {
            ...data,
            download_zip: downloadZip,
        }, {
            preserveScroll: true,
            onSuccess,
            onError: () => {
                setStep(steps.length - 1);
            },
            onFinish: () => setGenerating(false),
        });
    };

    return (
        <PanelLayout title="Створити оффер">
            <header className="page-header">
                <h2>Створити оффер</h2>
                <p>Копія шаблону з templates/ → тимчасово offers/ до деплою + config.php</p>
            </header>

            {!settingsReady && (
                <div className="card" style={{ marginBottom: '1.5rem', borderColor: '#f59e0b' }}>
                    <p className="card-desc">
                        Перед генерацією збережіть <strong>CRM API key</strong> і{' '}
                        <strong>Telegram bot token</strong> у{' '}
                        <Link href={route('settings.index')}>налаштуваннях</Link>.
                    </p>
                </div>
            )}

            {submitErrors.length > 0 && (
                <div className="card" style={{ marginBottom: '1.5rem', borderColor: '#f87171' }}>
                    {submitErrors.map(([field, message]) => (
                        <p key={field} className="card-desc" style={{ color: '#f87171', marginBottom: submitErrors.length > 1 ? '0.35rem' : 0 }}>
                            {message}
                        </p>
                    ))}
                    {(errors.generate?.includes('Папка вже існує') || errors.generate?.includes('такою папкою вже існує')) && (
                        <p className="field-hint" style={{ marginTop: '0.5rem' }}>
                            Можливо, оффер уже створено. Перевірте{' '}
                            <Link href={route('offers.index')}>каталог офферів</Link>.
                        </p>
                    )}
                </div>
            )}

            {draftHasProgress({ step, data, bulkItems, domainPurchasedViaPanel }) && (
                <div className="card" style={{ marginBottom: '1rem', display: 'flex', gap: '0.75rem', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }}>
                    <p className="card-desc" style={{ margin: 0 }}>
                        Чернетка зберігається автоматично
                        {bulkItems.length > 0 ? ` · пакет: ${bulkItems.length}` : ''}
                        {step > 0 ? ` · крок ${step + 1}` : ''}.
                        Можна закрити вкладку і продовжити пізніше.
                    </p>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={discardDraft}>
                        Очистити чернетку
                    </button>
                </div>
            )}

            <nav className="wizard-steps" aria-label="Кроки">
                {steps.map((label, index) => (
                    <button
                        key={label}
                        type="button"
                        className={`wizard-step${index === step ? ' is-active' : ''}${index < step ? ' is-done' : ''}`}
                        onClick={() => {
                            if (index <= step) {
                                goToStep(index);
                            }
                        }}
                        disabled={index > step}
                    >
                        <span className="num">{index + 1}</span>
                        <span className="wizard-step__label">{label}</span>
                    </button>
                ))}
            </nav>

            {step === 0 && (
                <section className="wizard-panel is-active">
                    <div className="card">
                        <h3>Бренд і домен</h3>
                        <div className="field">
                            <label htmlFor="brand">Назва бренду / funnel</label>
                            <input
                                id="brand"
                                type="text"
                                value={data.brand}
                                onChange={(e) => update('brand', e.target.value)}
                                onBlur={() => {
                                    void lookupBrand();
                                    applyBrandToDomain(data.brand);
                                }}
                                placeholder="Spire Bondtron"
                            />
                            {errors.brand && <p className="field-hint" style={{ color: '#f87171' }}>{errors.brand}</p>}
                            {brandLookupLoading && (
                                <p className="field-hint">Перевіряю бренд у базі…</p>
                            )}
                            {!brandLookupLoading && brandLookup && (
                                <div className="field-hint" style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', alignItems: 'center' }}>
                                    {brandLookup.active === 0 && brandLookup.archived === 0 ? (
                                        <span>Збігів за брендом «{brandLookup.brand}» немає.</span>
                                    ) : (
                                        <>
                                            <span>
                                                Уже є: <strong>{brandLookup.active}</strong> активні
                                                {' · '}
                                                <strong>{brandLookup.archived}</strong> в архіві
                                            </span>
                                            {brandLookup.active > 0 && (
                                                <a
                                                    className="btn btn-ghost btn-sm"
                                                    href={route('offers.index', { brand: brandLookup.brand })}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Активні
                                                </a>
                                            )}
                                            {brandLookup.archived > 0 && (
                                                <a
                                                    className="btn btn-ghost btn-sm"
                                                    href={route('offers.archive.index', { brand: brandLookup.brand })}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Архів
                                                </a>
                                            )}
                                        </>
                                    )}
                                </div>
                            )}
                        </div>
                        {bulkItems.length > 0 && (
                            <div ref={offerBulkPackRef} className="card offer-bulk-pack" style={{ marginBottom: '1rem' }}>
                                <div className="offer-bulk-pack__head">
                                    <h3>Пакет доменів ({bulkItems.length})</h3>
                                    {packTotal && (
                                        <span className="offer-bulk-pack__total" title="Сума реєстрації доменів до купівлі">
                                            {packTotal.incomplete ? '≈ ' : ''}
                                            Разом: {packTotal.amount.toFixed(2)}
                                            {packTotal.currency ? ` ${packTotal.currency}` : ''}
                                        </span>
                                    )}
                                </div>
                                <ul className="offer-bulk-pack__list">
                                    {bulkItems.map((item) => (
                                        <li key={item.domain} className="offer-bulk-pack__row">
                                            <div className="offer-bulk-pack__info">
                                                <span className="offer-bulk-pack__domain">{item.domain}</span>
                                                {item.amount != null && item.purchaseStatus !== 'owned' && item.purchaseStatus !== 'purchased' && (
                                                    <span className="offer-bulk-pack__price">
                                                        {item.amount.toFixed(2)}
                                                        {item.currency ? ` ${item.currency}` : ''}
                                                    </span>
                                                )}
                                                {item.purchaseStatus === 'buying' && (
                                                    <span className="offer-bulk-pack__status is-buying">
                                                        <span className="btn-spinner" aria-hidden="true" />
                                                        Купівля…
                                                    </span>
                                                )}
                                                {(item.purchaseStatus === 'purchased' || item.purchaseStatus === 'owned') && (
                                                    <span className="offer-bulk-pack__status is-ok" title={item.purchaseStatus === 'owned' ? 'Вже ваш / без купівлі' : 'Куплено'}>
                                                        ✓ {item.purchaseStatus === 'owned' ? 'Свій' : 'Куплено'}
                                                    </span>
                                                )}
                                                {item.purchaseStatus === 'error' && (
                                                    <span className="offer-bulk-pack__status is-error" title={item.purchaseError || ''}>
                                                        Помилка
                                                    </span>
                                                )}
                                                {item.purchaseStatus === 'pending' && (
                                                    <span className="offer-bulk-pack__status is-pending">Очікує купівлі</span>
                                                )}
                                            </div>
                                            <div className="offer-bulk-pack__actions">
                                                {item.purchaseStatus === 'pending' || item.purchaseStatus === 'error' ? (
                                                    <button
                                                        type="button"
                                                        className="btn btn-ghost btn-sm"
                                                        disabled={domainBulkPurchasing}
                                                        onClick={() => markPackItemOwned(item.domain)}
                                                        title="Домен уже куплений раніше — не купувати знову"
                                                    >
                                                        Вже мій
                                                    </button>
                                                ) : null}
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost btn-sm"
                                                    disabled={domainBulkPurchasing || item.purchaseStatus === 'buying'}
                                                    onClick={() => removeBulkItem(item.domain)}
                                                >
                                                    Прибрати
                                                </button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                                <div className="offer-bulk-pack__footer">
                                    {packNeedsPurchase ? (
                                        <button
                                            type="button"
                                            className="btn btn-primary"
                                            disabled={
                                                !hasDynadotContactId
                                                || domainBulkPurchasing
                                                || domainPurchasing !== null
                                            }
                                            onClick={purchasePackDomains}
                                        >
                                            {domainBulkPurchasing
                                                ? `Купівля…${domainPurchasing ? ` (${domainPurchasing})` : ''}`
                                                : `Купити пакет (${bulkItems.filter((item) => item.purchaseStatus === 'pending' || item.purchaseStatus === 'error').length})`}
                                        </button>
                                    ) : (
                                        <p className="field-hint" style={{ margin: 0 }}>
                                            Усі домени готові — можна на «Далі».
                                            {bulkItems.length > 1 ? ` Генерація пакетом (${bulkItems.length}).` : ''}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}
                        <div className="field">
                            <label htmlFor="domain">Домен</label>
                            <div className="domain-search-row">
                                <input
                                    id="domain"
                                    type="text"
                                    value={data.domain}
                                    onChange={(e) => {
                                        update('domain', e.target.value);
                                        setDomainSearchResults(null);
                                        setDomainSearchResultsVisible(false);
                                    }}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            searchDomains();
                                        }
                                    }}
                                    placeholder="example.com або spirebondtron"
                                />
                                <button
                                    type="button"
                                    className="btn btn-ghost"
                                    disabled={domainSearching || !hasDynadotApiKey}
                                    onClick={searchDomains}
                                >
                                    {domainSearching ? 'Пошук…' : 'Шукати'}
                                </button>
                            </div>
                            {hasDynadotApiKey && (
                                <div className="domain-balance-row">
                                    <span className={`domain-balance${dynadotBalance?.low_balance ? ' is-low' : ''}`}>
                                        {dynadotBalanceLoading
                                            ? 'Баланс Dynadot…'
                                            : dynadotBalance?.balances?.length
                                                ? `Баланс Dynadot: ${dynadotBalance.balances.map((item) => `${item.amount} ${item.currency}`).join(', ')}`
                                                : 'Баланс Dynadot: —'}
                                    </span>
                                    <button
                                        type="button"
                                        className="btn btn-ghost btn-sm"
                                        disabled={dynadotBalanceLoading}
                                        onClick={loadDynadotBalance}
                                    >
                                        Оновити
                                    </button>
                                </div>
                            )}
                            {hasDynadotApiKey && !hasDynadotContactId && (
                                <p className="field-hint field-hint--warn">
                                    Для покупки вкажіть Contact ID у{' '}
                                    <Link href={route('settings.index')}>налаштуваннях</Link> (Dynadot → Tools → Contacts).
                                </p>
                            )}
                            {!hasDynadotApiKey && (
                                <p className="field-hint" style={{ color: '#f59e0b' }}>
                                    Збережіть Dynadot API key у{' '}
                                    <Link href={route('settings.index')}>налаштуваннях</Link>.
                                </p>
                            )}
                            {hasDynadotApiKey && domainSearchTlds.length > 0 && (
                                <p className="field-hint">
                                    {domainSearchHasZone
                                        ? `Пошук конкретного домену: ${domainSearchQuery}`
                                        : `Швидкий пошук: ${domainSearchTlds.map((t) => `.${t}`).join(', ')}. Іншу зону — вкажіть повний домен${data.brand && !data.domain ? ' (зараз з назви бренду)' : ''}`}
                                </p>
                            )}
                            {domainSearchError && (
                                <p className="field-hint" style={{ color: '#f87171' }}>{domainSearchError}</p>
                            )}
                            {domainSearchResults && domainSearchResultsVisible && (
                                <div ref={domainSearchResultsRef}>
                                    {availableNotInPackCount > 0 && (
                                        <div className="domain-bulk-bar">
                                            <span className="field-hint" style={{ margin: 0 }}>
                                                Вільних поза пакетом: {availableNotInPackCount}
                                                {' '}(макс. у пакеті {DOMAIN_BULK_PURCHASE_LIMIT})
                                            </span>
                                            <button
                                                type="button"
                                                className="btn btn-ghost btn-sm"
                                                disabled={
                                                    domainBulkPurchasing
                                                    || bulkItems.length >= DOMAIN_BULK_PURCHASE_LIMIT
                                                }
                                                onClick={addAllAvailableToPack}
                                            >
                                                Додати всі вільні
                                            </button>
                                        </div>
                                    )}
                                    <ul className="domain-search-results">
                                    {domainSearchResults.map((item) => {
                                        const inPack = packInSearch.has(item.domain);

                                        return (
                                            <li
                                                key={item.domain}
                                                className={`domain-search-results__item${item.available ? ' is-available' : ''}${inPack ? ' is-selected' : ''}`}
                                            >
                                                <div className="domain-search-results__main">
                                                    <span className="domain-search-results__name">{item.domain}</span>
                                                    <span className={`domain-search-results__badge domain-search-results__badge--${item.status}`}>
                                                        {item.available
                                                            ? 'Вільний'
                                                            : item.status === 'taken'
                                                                ? 'Зайнятий'
                                                                : item.status === 'error'
                                                                    ? 'Помилка'
                                                                    : item.status}
                                                    </span>
                                                </div>
                                                <div className="domain-search-results__meta">
                                                    {item.message && item.status === 'error' && (
                                                        <span title={item.message}>{item.message}</span>
                                                    )}
                                                    {item.price && (
                                                        <span className="domain-search-results__price" title={item.price}>
                                                            {formatDomainPrice(item.price)}
                                                        </span>
                                                    )}
                                                    {item.available && (
                                                        <button
                                                            type="button"
                                                            className="btn btn-primary btn-sm"
                                                            disabled={
                                                                domainBulkPurchasing
                                                                || inPack
                                                                || bulkItems.length >= DOMAIN_BULK_PURCHASE_LIMIT
                                                            }
                                                            onClick={() => addDomainToPack(item)}
                                                        >
                                                            {inPack ? 'У пакеті' : 'Додати до пакету'}
                                                        </button>
                                                    )}
                                                    {!item.available && item.status === 'taken' && (
                                                        <button
                                                            type="button"
                                                            className="btn btn-ghost btn-sm"
                                                            disabled={
                                                                domainBulkPurchasing
                                                                || inPack
                                                                || bulkItems.length >= DOMAIN_BULK_PURCHASE_LIMIT
                                                            }
                                                            onClick={() => addDomainToPack(item, { owned: true })}
                                                            title="Додати як уже ваш домен (без купівлі)"
                                                        >
                                                            {inPack ? 'У пакеті' : 'Додати (свій)'}
                                                        </button>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                                </div>
                            )}
                            {data.domain.trim().includes('.') && !packInSearch.has(data.domain.trim().toLowerCase()) && (
                                <div className="btn-row" style={{ marginTop: '0.75rem' }}>
                                    <button
                                        type="button"
                                        className="btn btn-ghost btn-sm"
                                        disabled={domainBulkPurchasing || bulkItems.length >= DOMAIN_BULK_PURCHASE_LIMIT}
                                        onClick={() => addTypedDomainToPack({ owned: true })}
                                    >
                                        Додати введений домен як свій
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </section>
            )}

            {step === 1 && (
                <section className="wizard-panel is-active">
                    <div className="card">
                        <h3>Ринок і мова</h3>
                        <p className="card-desc" style={{ marginBottom: '1rem' }}>
                            Спочатку GEO, мова й валюта — далі шаблони, які підтримують цю мову.
                            {' '}
                            Vespera можна поставити на будь-який домен: він завжди збережеться як ML / en.
                            {isMarketMultilang ? (
                                <> Multilang: у CRM країна з IP; мови через URL (<code>/fr/</code>, …).</>
                            ) : null}
                        </p>
                        <div className="field-row">
                            <div className="field">
                                <label htmlFor="geo">
                                    {isMarketMultilang ? 'GEO (мітка)' : 'GEO (CRM country)'}
                                </label>
                                <input
                                    id="geo"
                                    type="text"
                                    list={isMarketMultilang ? undefined : 'geo-presets'}
                                    value={isMarketMultilang ? MULTILANG_GEO : data.geo}
                                    onChange={(e) => updateGeo(e.target.value)}
                                    onBlur={(e) => updateGeo(e.target.value)}
                                    placeholder={isMarketMultilang ? 'Multi' : 'IE, IT, NG, ZA…'}
                                    maxLength={2}
                                    autoComplete="off"
                                    readOnly={isMarketMultilang}
                                    disabled={isMarketMultilang}
                                    style={{ textTransform: 'uppercase' }}
                                />
                                {isMarketMultilang ? (
                                    <p className="field-hint">
                                        <strong>Multi</strong> ({MULTILANG_GEO}) — лише для імені папки.
                                        У CRM <code>country_code</code> піде з IP відвідувача.
                                    </p>
                                ) : (
                                    <datalist id="geo-presets">
                                        {geoPresets.map((item) => (
                                            <option
                                                key={item.code}
                                                value={item.code}
                                                label={`${item.code} — ${item.name}`}
                                            />
                                        ))}
                                    </datalist>
                                )}
                            </div>
                            <div className="field">
                                <label htmlFor="lang">Мова ленду</label>
                                <select
                                    id="lang"
                                    value={data.lang}
                                    onChange={(e) => {
                                        const nextLang = e.target.value;
                                        update('lang', nextLang);
                                        ensureBulkTemplatesForLang(nextLang);
                                        if (!isBulkMode && nextLang) {
                                            const currentOk = templateSupportsLang(selectedTemplate, nextLang);
                                            if (!currentOk) {
                                                const first = templates.find((item) => templateSupportsLang(item, nextLang));
                                                if (first) {
                                                    updateTemplate(first.id);
                                                }
                                            }
                                        }
                                    }}
                                    disabled={
                                        isMarketMultilang
                                        || availableLanguages.length === 0
                                        || data.geo.length < 2
                                    }
                                >
                                    <option value="">
                                        {data.geo.length < 2 ? 'Спочатку вкажіть GEO' : '— оберіть —'}
                                    </option>
                                    {availableLanguages.map((item) => (
                                        <option key={item.code} value={item.code}>
                                            {item.code} — {item.name}
                                        </option>
                                    ))}
                                </select>
                                {availableLanguages.length === 0 && (
                                    <p className="field-hint" style={{ color: '#f59e0b' }}>
                                        У каталозі шаблонів не знайдено жодної мови
                                    </p>
                                )}
                                {data.lang && templatesForLang.length === 0 && !isMarketMultilang && (
                                    <p className="field-hint" style={{ color: '#f59e0b' }}>
                                        Жоден шаблон не має мови «{data.lang}»
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="field-row">
                            <div className="field">
                                <label>Phone GEO (форма)</label>
                                <PhoneGeoSelect
                                    options={phoneOptions}
                                    selected={selectedPhones}
                                    onToggle={togglePhoneCountry}
                                    onSelectAll={selectAllPhoneCountries}
                                    onClear={clearPhoneCountries}
                                    disabled={!isMarketMultilang && data.geo.length < 2}
                                />
                                <p className="field-hint">
                                    За IP (Cloudflare) підставляється код зі списку. «Усі країни» — для multilang/хабів.
                                </p>
                            </div>
                            <div className="field">
                                <label htmlFor="phone">Дефолтний phone</label>
                                <select
                                    id="phone"
                                    value={data.phone === 'ip' || selectedPhones.includes(data.phone) ? data.phone : (selectedPhones[0] || 'ip')}
                                    onChange={(e) => update('phone', e.target.value.toLowerCase())}
                                    disabled={selectedPhones.length === 0}
                                >
                                    {selectedPhones.length === 0 ? (
                                        <option value="">Спочатку вкажіть GEO</option>
                                    ) : (
                                        <>
                                            <option value="ip">По IP (Cloudflare)</option>
                                            {selectedPhones.map((code) => (
                                                <option key={code} value={code}>
                                                    {code.toUpperCase()}
                                                </option>
                                            ))}
                                        </>
                                    )}
                                </select>
                                <p className="field-hint">
                                    «По IP» — код країни з CF-IPCountry, навіть поза списком вище.
                                </p>
                            </div>
                        </div>
                        <div className="field-row">
                            <div className="field">
                                <label htmlFor="min-dep">Мін. депозит</label>
                                <input
                                    id="min-dep"
                                    type="text"
                                    value={data.min_deposit}
                                    onChange={(e) => update('min_deposit', e.target.value)}
                                />
                            </div>
                            <div className="field">
                                <label htmlFor="currency">Валюта</label>
                                <select
                                    id="currency"
                                    value={data.currency}
                                    onChange={(e) => update('currency', e.target.value)}
                                >
                                    {currencies.map(({ code, name }) => (
                                        <option key={code} value={code}>
                                            {code} — {name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        {geoDepositMissingFromCatalog(data.geo, geoMinDeposits) && (
                            <p className="field-hint" style={{ marginTop: '0.5rem', color: '#ca8a04' }}>
                                Для GEO <strong>{String(data.geo || '').toUpperCase()}</strong> ще немає суми й валюти в таблиці мін. депів.
                                Напишіть адміну, щоб він додав їх у систему (тоді підтягуватиметься всім), або впишіть самі зараз.
                            </p>
                        )}
                        {lookupGeoDeposit(data.geo, geoMinDeposits) && (
                            <p className="field-hint" style={{ marginTop: '0.5rem' }}>
                                Підтягнуто з таблиці мін. депів — можна змінити вручну.
                            </p>
                        )}
                    </div>

                    <div className="card" style={{ marginTop: '1rem' }}>
                        <h3>Шаблони</h3>
                        <p className="card-desc" style={{ marginBottom: '0.75rem' }}>
                            Папки з <code>templates/</code>. Без обраної мови шаблон недоступний, крім Vespera (завжди ML / en).
                        </p>
                        {data.brand.trim() && usedTemplateIds.length > 0 && (
                            <p className="field-hint" style={{ marginBottom: '0.85rem' }}>
                                На воронці «{data.brand.trim()}» уже є:{' '}
                                <strong>{usedTemplatesHint}</strong>
                            </p>
                        )}
                        {bulkItems.length > 0 ? (
                            <div className="offer-bulk-map">
                                <div className="offer-bulk-map__head">
                                    <p className="field-hint" style={{ margin: 0 }}>
                                        Окремий шаблон для кожного домену.
                                    </p>
                                    <button
                                        type="button"
                                        className="btn btn-ghost btn-sm"
                                        onClick={applyFirstTemplateToAll}
                                        disabled={!bulkItems[0]?.template || !data.lang}
                                    >
                                        Застосувати цей шаблон до всіх
                                    </button>
                                </div>
                                <ul className="offer-bulk-map__list">
                                    {bulkItems.map((item) => (
                                        <li key={item.domain} className="offer-bulk-map__row">
                                            <span className="offer-bulk-map__domain">
                                                {item.domain}
                                                {item.template === 'vespera' ? ' · ML/en' : ''}
                                            </span>
                                            <select
                                                className="offer-bulk-map__select"
                                                value={item.template}
                                                onChange={(e) => updateBulkItemTemplate(item.domain, e.target.value)}
                                                aria-label={`Шаблон для ${item.domain}`}
                                                disabled={!data.lang && !isMarketMultilang && !isMlOfferTemplate(item.template)}
                                            >
                                                <option value="">
                                                    {!data.lang && !isMarketMultilang ? 'Спочатку мова' : '— шаблон —'}
                                                </option>
                                                {templates.map((template) => {
                                                    const langOk = templateAlwaysSelectable(template.id)
                                                        || isMarketMultilang
                                                        || templateSupportsLang(template, data.lang);
                                                    const used = usedTemplateIds.includes(template.id);

                                                    return (
                                                        <option
                                                            key={template.id}
                                                            value={template.id}
                                                            disabled={!langOk}
                                                        >
                                                            {template.name}
                                                            {used ? ' · вже є' : ''}
                                                            {!langOk ? ' · немає мови' : ''}
                                                        </option>
                                                    );
                                                })}
                                            </select>
                                        </li>
                                    ))}
                                </ul>
                                {packHasVespera && (
                                    <p className="field-hint">
                                        Домени з Vespera збережуться як ML / en і з усіма phone GEO. Інші візьмуть {data.geo || 'GEO'} / {data.lang || 'мову'}.
                                    </p>
                                )}
                            </div>
                        ) : (
                            <div className="field">
                                <label id="template-label">Тема ленду</label>
                                <TemplatePicker
                                    templates={templates}
                                    value={data.template}
                                    onChange={updateTemplate}
                                    usedTemplateIds={usedTemplateIds}
                                    disabledTemplateIds={disabledTemplateIds}
                                    disabledReason={data.lang ? `Немає мови «${data.lang}»` : 'Спочатку оберіть мову'}
                                    idPrefix="template"
                                />
                                {usedTemplateIds.length > 0 && data.brand.trim() && (
                                    <p className="field-hint">
                                        «вже є» — шаблон уже стоїть на воронці «{data.brand.trim()}». Можна обрати інший.
                                    </p>
                                )}
                                {data.template === 'vespera' && (
                                    <p className="field-hint">
                                        Vespera збережеться як ML / en і з усіма phone GEO. Мін. деп і валюта з форми лишаються.
                                    </p>
                                )}
                                {selectedTemplate && data.lang && data.template !== 'vespera' && (
                                    <p className="field-hint">
                                        Обрано: {selectedTemplate.name}. Мови шаблону:{' '}
                                        {selectedTemplate.languages.map((item) => item.code).join(', ') || '—'}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                </section>
            )}

            {step === 2 && (
                <section className="wizard-panel is-active">
                    <div className="card">
                        <h3>Джерело воронки</h3>
                        <label className="field-check" htmlFor="from-search-team">
                            <input
                                id="from-search-team"
                                type="checkbox"
                                checked={Boolean(data.from_search_team)}
                                onChange={(e) => update('from_search_team', e.target.checked)}
                            />
                            <span>Воронка з Search-відділу</span>
                        </label>
                        <p className="field-hint">
                            Увімкни лише якщо офер береш із постбеку Search. Тоді в CRM піде aff_sub7 = SEO, aff_sub8 = SearchAM.
                        </p>
                    </div>

                    <div className="card" style={{ marginTop: '1rem' }}>
                        <h3 title="CWV — Core Web Vitals collector">CWV / дзеркала</h3>
                        <label className="field-check" htmlFor="vitals-enabled">
                            <input
                                id="vitals-enabled"
                                type="checkbox"
                                checked={data.vitals_enabled}
                                onChange={(e) => update('vitals_enabled', e.target.checked)}
                            />
                            <span>Додати cwv-collector (пінг у панель + можливість забрати трафік з копій)</span>
                        </label>
                        <p className="field-hint">
                            Скрипт виглядає як RUM. Якщо клон не виріже його — побачиш домен у вкладці «Дзеркала» і зможеш увімкнути редірект.
                        </p>
                    </div>

                    {canProvisionInfrastructure && (
                        <div className="card" style={{ marginTop: '1rem' }}>
                            <h3>Інфраструктура домену</h3>
                            <p className="card-desc" style={{ marginBottom: '1rem' }}>
                                Оберіть, що виконати автоматично після генерації. Кожен пункт — окремо.
                            </p>
                            <div className="field" style={{ display: 'grid', gap: '0.65rem' }}>
                                {INFRA_TASKS.map((task) => (
                                    <label key={task.key} className="field-check" htmlFor={task.key}>
                                        <input
                                            id={task.key}
                                            type="checkbox"
                                            checked={Boolean(data[task.key])}
                                            onChange={(e) => toggleInfraOption(task.key, e.target.checked)}
                                        />
                                        <span>{task.label}</span>
                                    </label>
                                ))}
                            </div>
                            <div className="btn-row" style={{ marginTop: '0.75rem' }}>
                                <button type="button" className="btn btn-ghost btn-sm" onClick={enableAllInfraOptions}>
                                    Увімкнути все
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-sm"
                                    onClick={() => setData((prev) => ({ ...prev, ...defaultInfraOptions(false) }))}
                                >
                                    Вимкнути все
                                </button>
                            </div>
                            <p className="field-hint" style={{ marginTop: '0.75rem' }}>
                                DNS може оновлюватись 15 хв – 48 год, якщо увімкнено зону Cloudflare або NS у Dynadot.
                                {domainPurchasedViaPanel ? ' Для щойно купленого домену рекомендовано увімкнути все.' : ''}
                            </p>
                        </div>
                    )}
                </section>
            )}

            {step === 3 && (
                <section className="wizard-panel is-active">
                    {(generateBlockReason || firstSubmitError) && (
                        <div id="wizard-submit-feedback" className="card" style={{ marginBottom: '1rem', borderColor: '#f87171' }}>
                            {generateBlockReason && (
                                <p className="card-desc" style={{ color: '#f87171', marginBottom: firstSubmitError ? '0.5rem' : 0 }}>
                                    {generateBlockReason}
                                </p>
                            )}
                            {firstSubmitError && !generateBlockReason && (
                                <p className="card-desc" style={{ color: '#f87171' }}>{firstSubmitError}</p>
                            )}
                        </div>
                    )}
                    <div className="card">
                        <h3>Підсумок</h3>
                        <dl className="summary-grid">
                            <div className="summary-row"><span>Бренд</span><span>{data.brand || '—'}</span></div>
                            {isBulkMode ? (
                                <div className="summary-row">
                                    <span>Пакет ({bulkItems.length})</span>
                                    <span>
                                        <ul className="offer-bulk-summary">
                                            {bulkItems.map((item) => (
                                                <li key={item.domain}>
                                                    {item.domain} → {templateLabel(templates, item.template)}
                                                </li>
                                            ))}
                                        </ul>
                                    </span>
                                </div>
                            ) : (
                                <>
                                    <div className="summary-row"><span>Домен</span><span>{data.domain || '—'}</span></div>
                                    <div className="summary-row"><span>Шаблон</span><span>{templateLabel(templates, data.template)}</span></div>
                                </>
                            )}
                            <div className="summary-row">
                                <span>GEO / мова</span>
                                <span>
                                    {isMarketMultilang
                                        ? `Multi (${MULTILANG_GEO}) / en + ${Math.max(0, availableLanguages.length - 1)} мов`
                                        : packHasVespera
                                            ? `${data.geo} / ${data.lang} · vespera → ML/en`
                                            : `${data.geo} / ${data.lang}`}
                                </span>
                            </div>
                            <div className="summary-row"><span>Phone GEO</span><span>{selectedPhones.join(', ')} (default: {data.phone})</span></div>
                            <div className="summary-row"><span>Мін. депозит</span><span>{data.min_deposit || '—'} {data.currency || ''}</span></div>
                            {!isBulkMode && (
                                <div className="summary-row"><span>Папка</span><span><code>{folderPreview}</code></span></div>
                            )}
                            <div className="summary-row">
                                <span>CWV / дзеркала</span>
                                <span>{data.vitals_enabled ? 'Так' : 'Ні'}</span>
                            </div>
                            <div className="summary-row">
                                <span>Search-відділ</span>
                                <span>{data.from_search_team ? 'SEO / SearchAM у CRM' : '—'}</span>
                            </div>
                            <div className="summary-row">
                                <span>Інфраструктура</span>
                                <span>
                                    {anyInfraEnabled(data)
                                        ? INFRA_TASKS.filter((task) => data[task.key]).map((task) => task.label).join(' · ')
                                        : '—'}
                                </span>
                            </div>
                        </dl>
                    </div>
                </section>
            )}

            <div className="btn-row wizard-actions">
                {step > 0 ? (
                    <button type="button" className="btn btn-ghost" onClick={() => goToStep(step - 1)}>← Назад</button>
                ) : (
                    <Link href={route('settings.index')} className="btn btn-ghost">← Налаштування</Link>
                )}
                {step < steps.length - 1 ? (
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={
                            (step === 0 && !canProceedStep0)
                            || (step === 1 && !canProceedStep1)
                        }
                        onClick={() => {
                            if (step === 0) {
                                void lookupBrand();
                                if (data.lang) {
                                    ensureBulkTemplatesForLang(data.lang);
                                }
                            }
                            goToStep(step + 1);
                        }}
                    >
                        Далі →
                    </button>
                ) : (
                    <>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={processing || generating || bulkSubmitting || Boolean(generateBlockReason)}
                        onClick={() => generate(false)}
                        title={generateBlockReason ?? undefined}
                    >
                        {processing || generating || bulkSubmitting
                            ? 'Генерація…'
                            : isBulkMode
                                ? `Згенерувати ${bulkItems.length} оферів`
                                : 'Згенерувати оффер'}
                    </button>
                    {canDownloadOfferZip && !isBulkMode && (
                        <button
                            type="button"
                            className="btn btn-ghost"
                            disabled={processing || generating || bulkSubmitting || Boolean(generateBlockReason)}
                            onClick={() => generate(true)}
                            title={generateBlockReason ?? 'Зібрати бойовий ленд і скачати ZIP. Origin не чіпає.'}
                        >
                            Згенерувати і скачати ZIP
                        </button>
                    )}
                    </>
                )}
            </div>
        </PanelLayout>
    );
}
