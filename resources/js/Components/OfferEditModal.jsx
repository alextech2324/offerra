import PhoneGeoSelect, { normalizePhoneCountries, phoneOptionCode, uniquePhonePresets } from '@/Components/PhoneGeoSelect';
import TemplatePicker, { usedTemplatesForBrand } from '@/Components/TemplatePicker';
import { geoDepositMissingFromCatalog, lookupGeoDeposit } from '@/lib/geoDepositPrefs';
import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';

/** Псевдо-GEO для multilang: ім'я папки. У CRM країна = IP ліда. */
const MULTILANG_GEO = 'ML';

function isMlOfferTemplate(templateId) {
    return templateId === 'multilang' || templateId === 'vespera';
}

function resolveMarket(geo, geoPresets, availableLanguages) {
    const code = String(geo || '').toUpperCase();
    const preset = geoPresets.find((item) => item.code === code);
    const preferredLang = (preset?.lang || '').toLowerCase();
    const langCodes = availableLanguages.map((item) => item.code);
    const lang = langCodes.includes(preferredLang)
        ? preferredLang
        : (langCodes[0] || preferredLang || '');

    return {
        geo: code,
        lang,
        currency: preset?.currency || '',
        phone: (preset?.phone || lang || '').toLowerCase(),
    };
}

export default function OfferEditModal({
    offer,
    geoPresets = [],
    currencies = [],
    templates = [],
    brandTemplateUsage = {},
    onClose,
}) {
    const phoneCountries = normalizePhoneCountries(offer.phone_countries, offer.phone);
    const initialTemplate = offer.template_id || 'default';

    const { errors: pageErrors, panel } = usePage().props;
    const geoMinDeposits = panel?.geo_min_deposits || {};
    const { data, setData, patch, processing, errors, reset, clearErrors } = useForm({
        brand: offer.brand || '',
        geo: String(offer.geo || '').toUpperCase(),
        lang: String(offer.lang || '').toLowerCase(),
        template: initialTemplate,
        min_deposit: offer.min_deposit || '250',
        currency: String(offer.currency || 'EUR').toUpperCase(),
        phone: offer.phone || phoneCountries[0] || '',
        phone_countries: phoneCountries,
        vitals_enabled: Boolean(offer.vitals_enabled),
        auto_redeploy: true,
    });

    const usedTemplateIds = useMemo(
        () => usedTemplatesForBrand(brandTemplateUsage, data.brand, {
            excludeTemplate: initialTemplate,
            excludeIfCountIsOne: true,
        }),
        [brandTemplateUsage, data.brand, initialTemplate],
    );

    const pickerTemplates = useMemo(
        () => templates.filter((item) => item.active !== false || item.id === initialTemplate),
        [templates, initialTemplate],
    );

    useEffect(() => {
        const onKey = (event) => {
            if (event.key === 'Escape' && !processing) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [onClose, processing]);

    const selectedTemplate = useMemo(
        () => pickerTemplates.find((item) => item.id === data.template) ?? pickerTemplates[0] ?? null,
        [pickerTemplates, data.template],
    );
    const availableLanguages = selectedTemplate?.languages ?? [];
    const isMarketMultilang = isMlOfferTemplate(data.template);
    const phoneOptions = useMemo(() => uniquePhonePresets(geoPresets), [geoPresets]);
    const selectedPhones = normalizePhoneCountries(data.phone_countries, data.phone);

    useEffect(() => {
        if (!isMarketMultilang) {
            return;
        }

        const langs = availableLanguages;
        const nextLang = langs.some((item) => item.code === 'en') ? 'en' : (langs[0]?.code || 'en');

        setData((prev) => {
            if (prev.geo === MULTILANG_GEO && prev.lang === nextLang) {
                return prev;
            }

            return {
                ...prev,
                geo: MULTILANG_GEO,
                lang: nextLang,
            };
        });
    }, [isMarketMultilang, availableLanguages, setData]);

    const onTemplateChange = (templateId) => {
        const template = pickerTemplates.find((item) => item.id === templateId);
        const langs = template?.languages ?? [];
        const wasMultilang = isMlOfferTemplate(data.template);
        const isMultilang = isMlOfferTemplate(templateId);
        const nextLang = isMultilang
            ? (langs.some((item) => item.code === 'en') ? 'en' : (langs[0]?.code ?? ''))
            : (langs.some((item) => item.code === data.lang) ? data.lang : (langs[0]?.code || data.lang));
        const allPhoneCodes = phoneOptions.map((item) => phoneOptionCode(item));

        setData((prev) => ({
            ...prev,
            template: templateId,
            lang: nextLang,
            ...(isMultilang
                ? {
                    geo: MULTILANG_GEO,
                    phone: 'ip',
                    phone_countries: allPhoneCodes.length > 0 ? allPhoneCodes : prev.phone_countries,
                }
                : wasMultilang
                    ? { geo: prev.geo === MULTILANG_GEO ? (geoPresets[0]?.code || '') : prev.geo }
                    : {}),
        }));
    };

    const onGeoChange = (code) => {
        const resolved = resolveMarket(code, geoPresets, availableLanguages);
        const remembered = lookupGeoDeposit(resolved.geo, geoMinDeposits);
        setData((prev) => {
            const phones = normalizePhoneCountries(prev.phone_countries, prev.phone);
            const phoneSet = new Set(phones);
            if (resolved.phone) {
                phoneSet.add(resolved.phone);
            }
            const list = [...phoneSet];
            const phone = list.includes(resolved.phone) ? resolved.phone : (list.includes(prev.phone) ? prev.phone : list[0]);

            return {
                ...prev,
                geo: resolved.geo,
                lang: resolved.lang || prev.lang,
                min_deposit: remembered?.min_deposit || prev.min_deposit,
                currency: remembered?.currency || resolved.currency || prev.currency,
                phone_countries: list,
                phone,
            };
        });
    };

    const updateDepositField = (field, value) => {
        setData(field, value);
    };

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
                ? (normalizePhoneCountries(prev.phone_countries)[0] || phoneOptions[0] && phoneOptionCode(phoneOptions[0]) || 'gb')
                : (prev.phone && /^[a-z]{2}$/.test(prev.phone) ? prev.phone : 'gb');

            return {
                ...prev,
                phone_countries: [keep],
                phone: prev.phone === 'ip' ? 'ip' : keep,
            };
        });
    };

    const submit = (event) => {
        event.preventDefault();
        clearErrors();

        patch(route('offers.update', offer.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    const fieldError =
        pageErrors?.edit
        || errors.edit
        || errors.brand
        || errors.geo
        || errors.lang
        || errors.template
        || errors.min_deposit
        || errors.currency
        || errors.phone
        || errors.phone_countries
        || errors.vitals_enabled;

    return (
        <div className="modal-backdrop" onClick={() => !processing && onClose()}>
            <div
                className="modal-card modal-card--wide"
                role="dialog"
                aria-labelledby="offer-edit-title"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="modal-card__header">
                    <div>
                        <h3 id="offer-edit-title">Редагувати оффер</h3>
                        <p className="card-desc">{offer.domain} · домен не змінюється</p>
                    </div>
                    <button
                        type="button"
                        className="modal-card__close"
                        onClick={onClose}
                        disabled={processing}
                        aria-label="Закрити"
                    >
                        ×
                    </button>
                </div>

                <form onSubmit={submit}>
                    <div className="field">
                        <label htmlFor="edit-brand">Бренд</label>
                        <input
                            id="edit-brand"
                            type="text"
                            value={data.brand}
                            onChange={(event) => setData('brand', event.target.value)}
                            required
                        />
                    </div>

                    <div className="field">
                        <label id="edit-template-label">Шаблон</label>
                        <TemplatePicker
                            templates={pickerTemplates}
                            value={data.template}
                            onChange={onTemplateChange}
                            usedTemplateIds={usedTemplateIds}
                            idPrefix="edit-template"
                        />
                    </div>

                    <div className="field-row">
                        <div className="field">
                            <label htmlFor="edit-geo">
                                {isMarketMultilang ? 'GEO (мітка)' : 'GEO (CRM)'}
                            </label>
                            {isMarketMultilang ? (
                                <>
                                    <select id="edit-geo" value={MULTILANG_GEO} disabled>
                                        <option value={MULTILANG_GEO}>
                                            {MULTILANG_GEO} — Multi (усі мови)
                                        </option>
                                    </select>
                                    <p className="field-hint">
                                        <strong>ML</strong> — лише мітка для папки.
                                        У CRM країна йде з IP відвідувача. Мова ленду фіксується в <code>en</code>,
                                        інші мови вже всередині шаблону.
                                    </p>
                                </>
                            ) : (
                                <select
                                    id="edit-geo"
                                    value={data.geo}
                                    onChange={(event) => onGeoChange(event.target.value)}
                                >
                                    {geoPresets.map((item) => (
                                        <option key={item.code} value={item.code}>
                                            {item.code}{item.name ? ` — ${item.name}` : ''}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </div>
                        <div className="field">
                            <label htmlFor="edit-lang">Мова</label>
                            <select
                                id="edit-lang"
                                value={data.lang}
                                onChange={(event) => setData('lang', event.target.value.toLowerCase())}
                                disabled={isMarketMultilang}
                            >
                                {availableLanguages.map((item) => (
                                    <option key={item.code} value={item.code}>
                                        {item.code}{item.name ? ` — ${item.name}` : ''}
                                    </option>
                                ))}
                            </select>
                            {isMarketMultilang && (
                                <p className="field-hint">
                                    Для multilang база — <code>en</code>. Не шукай <code>ML</code> тут — це GEO.
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="field-row">
                        <div className="field">
                            <label htmlFor="edit-min-deposit">Мін. депозит</label>
                            <input
                                id="edit-min-deposit"
                                type="text"
                                value={data.min_deposit}
                                onChange={(event) => updateDepositField('min_deposit', event.target.value)}
                                required
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="edit-currency">Валюта</label>
                            <select
                                id="edit-currency"
                                value={data.currency}
                                onChange={(event) => updateDepositField('currency', event.target.value.toUpperCase())}
                            >
                                {currencies.map(({ code, name }) => (
                                    <option key={code} value={code}>
                                        {code}{name ? ` — ${name}` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {geoDepositMissingFromCatalog(data.geo, geoMinDeposits) && (
                        <p className="field-hint" style={{ marginTop: '0.35rem', color: '#ca8a04' }}>
                            Для GEO <strong>{String(data.geo || '').toUpperCase()}</strong> ще немає суми й валюти в таблиці мін. депів.
                            Напишіть адміну, щоб він додав їх у систему (тоді підтягуватиметься всім), або впишіть самі зараз.
                        </p>
                    )}
                    {lookupGeoDeposit(data.geo, geoMinDeposits) && (
                        <p className="field-hint" style={{ marginTop: '0.35rem' }}>
                            Підтягнуто з таблиці мін. депів — можна змінити вручну.
                        </p>
                    )}

                    <div className="field">
                        <label>Phone GEO (форма)</label>
                        <PhoneGeoSelect
                            options={phoneOptions}
                            selected={selectedPhones}
                            onToggle={togglePhoneCountry}
                            onSelectAll={selectAllPhoneCountries}
                            onClear={clearPhoneCountries}
                        />
                        <p className="field-hint">
                            За IP (Cloudflare) підставляється код зі списку. «Усі країни» — для multilang/хабів.
                        </p>
                    </div>

                    <div className="field">
                        <label htmlFor="edit-phone">Дефолтний phone</label>
                        <select
                            id="edit-phone"
                            value={data.phone === 'ip' || selectedPhones.includes(data.phone) ? data.phone : (selectedPhones[0] || 'ip')}
                            onChange={(event) => setData('phone', event.target.value.toLowerCase())}
                        >
                            <option value="ip">По IP (Cloudflare)</option>
                            {selectedPhones.map((code) => (
                                <option key={code} value={code}>
                                    {code.toUpperCase()}
                                </option>
                            ))}
                        </select>
                        <p className="field-hint">
                            «По IP» — код країни з CF-IPCountry, навіть якщо його немає у списку вище.
                        </p>
                    </div>

                    <div className="field">
                        <label className="field-check" htmlFor="edit-vitals-enabled">
                            <input
                                id="edit-vitals-enabled"
                                type="checkbox"
                                checked={data.vitals_enabled}
                                onChange={(event) => setData('vitals_enabled', event.target.checked)}
                            />
                            <span title="CWV — Core Web Vitals collector (дзеркала / редірект з копій)">
                                CWV-collector (дзеркала / редірект з копій)
                            </span>
                        </label>
                    </div>

                    <div className="field">
                        <label className="field-check" htmlFor="edit-auto-redeploy">
                            <input
                                id="edit-auto-redeploy"
                                type="checkbox"
                                checked={data.auto_redeploy}
                                onChange={(event) => setData('auto_redeploy', event.target.checked)}
                            />
                            <span>Одразу передеплоїти після збереження</span>
                        </label>
                        <p className="field-hint">
                            Зміна мови/шаблону перезбирає ленд з нуля. Домен і Cloudflare/Dynadot не чіпаються.
                        </p>
                    </div>

                    {fieldError && (
                        <p className="field-hint" style={{ color: '#f87171' }}>
                            {fieldError}
                        </p>
                    )}

                    <div className="btn-row">
                        <button
                            type="button"
                            className="btn btn-ghost"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Скасувати
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {processing ? 'Збереження…' : 'Зберегти'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
