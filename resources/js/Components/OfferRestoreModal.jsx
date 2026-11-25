import TemplatePicker, { usedTemplatesForBrand } from '@/Components/TemplatePicker';
import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';

const MULTILANG_GEO = 'ML';

function isMlOfferTemplate(templateId) {
    return templateId === 'multilang' || templateId === 'vespera' || templateId === 'fortis' || templateId === 'bluegrove';
}

export default function OfferRestoreModal({
    offer,
    geoPresets = [],
    templates = [],
    brandTemplateUsage = {},
    onClose,
}) {
    const { errors: pageErrors } = usePage().props;
    const initialTemplate = offer.template_id || 'default';
    const initialGeo = String(offer.geo || '').toUpperCase();

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        template: initialTemplate,
        geo: initialGeo,
    });

    const usedTemplateIds = useMemo(
        () => usedTemplatesForBrand(brandTemplateUsage, offer.brand),
        [brandTemplateUsage, offer.brand],
    );

    const pickerTemplates = useMemo(
        () => templates.filter((item) => item.active !== false || item.id === initialTemplate),
        [templates, initialTemplate],
    );

    const isMarketMultilang = isMlOfferTemplate(data.template);

    useEffect(() => {
        const onKey = (event) => {
            if (event.key === 'Escape' && !processing) {
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [onClose, processing]);

    const onTemplateChange = (templateId) => {
        const isMultilang = isMlOfferTemplate(templateId);
        const wasMultilang = isMlOfferTemplate(data.template);

        setData((prev) => ({
            ...prev,
            template: templateId,
            geo: isMultilang
                ? MULTILANG_GEO
                : (wasMultilang && prev.geo === MULTILANG_GEO
                    ? (geoPresets[0]?.code || prev.geo)
                    : prev.geo),
        }));
    };

    const submit = (event) => {
        event.preventDefault();
        clearErrors();
        post(route('offers.restore', offer.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const fieldError = pageErrors?.archive || errors.archive || errors.template || errors.geo;

    return (
        <div className="modal-backdrop" onClick={() => !processing && onClose()}>
            <div
                className="modal-card modal-card--wide"
                role="dialog"
                aria-labelledby="offer-restore-title"
                aria-modal="true"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="modal-card__header">
                    <div>
                        <h3 id="offer-restore-title">Поновити з архіву</h3>
                        <p className="card-desc">{offer.domain} · можна змінити шаблон і GEO</p>
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
                    {fieldError && (
                        <p className="field-hint" style={{ color: '#f87171' }}>{fieldError}</p>
                    )}

                    <div className="field">
                        <label id="restore-template-label">Шаблон</label>
                        <TemplatePicker
                            templates={pickerTemplates}
                            value={data.template}
                            onChange={onTemplateChange}
                            usedTemplateIds={usedTemplateIds}
                            idPrefix="restore-template"
                        />
                    </div>

                    <div className="field">
                        <label htmlFor="restore-geo">
                            {isMarketMultilang ? 'GEO (мітка)' : 'GEO'}
                        </label>
                        {isMarketMultilang ? (
                            <>
                                <select id="restore-geo" value={MULTILANG_GEO} disabled>
                                    <option value={MULTILANG_GEO}>
                                        {MULTILANG_GEO} — Multi (усі мови)
                                    </option>
                                </select>
                                <p className="field-hint">
                                    Для цього шаблону GEO завжди <strong>ML</strong>. Мова ленду фіксується в en.
                                </p>
                            </>
                        ) : (
                            <select
                                id="restore-geo"
                                value={data.geo}
                                onChange={(event) => setData('geo', event.target.value)}
                            >
                                {geoPresets.map((item) => (
                                    <option key={item.code} value={item.code}>
                                        {item.code}{item.name ? ` — ${item.name}` : ''}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    <p className="field-hint">
                        Зберемо лендінг заново, піднімемо Cloudflare/origin і запустимо деплой.
                        Домен має лишатися в Dynadot.
                    </p>

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
                            {processing ? 'Поновлюємо…' : 'Поновити'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
