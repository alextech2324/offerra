import PanelLayout from '@/Layouts/PanelLayout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

function languageSummary(languages) {
    if (!languages?.length) {
        return '—';
    }

    return languages.map((item) => item.code).join(', ');
}

function TemplateCard({ template }) {
    const [busy, setBusy] = useState(false);
    const previewUrl = template.preview_url;
    const active = Boolean(template.active);

    const toggleAvailability = () => {
        if (busy) {
            return;
        }

        setBusy(true);
        router.patch(route('templates.availability', template.id), {
            enabled: !active,
        }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const thumb = previewUrl ? (
        <div className="template-live-frame-wrap">
            <iframe
                className="template-live-frame"
                src={previewUrl}
                title={`Превʼю ${template.name}`}
                tabIndex={-1}
                loading="lazy"
            />
            <div className="template-live-overlay">
                <span>↗ Відкрити превʼю</span>
            </div>
        </div>
    ) : (
        <div className="template-browser">
            <div className="template-browser-chrome">
                <span /><span /><span />
                <div className="template-browser-url">{template.id}.live</div>
            </div>
            <div className="template-browser-screen template-browser-screen--empty">
                <p>Превʼю недоступне</p>
            </div>
        </div>
    );

    return (
        <article className={`template-library-card${active ? '' : ' is-disabled'}`}>
            {previewUrl ? (
                <a
                    className="template-thumb-link"
                    href={previewUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={`Відкрити превʼю ${template.name}`}
                >
                    {thumb}
                </a>
            ) : (
                <div className="template-thumb-link" style={{ cursor: 'default' }}>
                    {thumb}
                </div>
            )}
            <div className="template-library-body">
                <div className="template-library-top">
                    <h3>{template.name}</h3>
                    {active ? (
                        <span className="badge badge-ok">Активний</span>
                    ) : (
                        <span className="badge badge-warn">Вимкнений</span>
                    )}
                </div>
                <p className="template-library-desc">{template.description}</p>
                <p className="template-library-meta">
                    <span>Мови: {languageSummary(template.languages)}</span>
                </p>
                <p className="template-library-pages">{template.pages}</p>
                <div className="template-library-actions">
                    {previewUrl && (
                        <a
                            href={previewUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="btn btn-primary"
                        >
                            ↗ Превʼю
                        </a>
                    )}
                    {active && (
                        <Link
                            href={route('offers.create', { fresh: 1, template: template.id })}
                            className="btn btn-ghost"
                        >
                            Використати
                        </Link>
                    )}
                    <button
                        type="button"
                        className="btn btn-ghost"
                        disabled={busy}
                        onClick={toggleAvailability}
                    >
                        {active ? 'Вимкнути' : 'Увімкнути'}
                    </button>
                </div>
            </div>
        </article>
    );
}

export default function TemplatesIndex({ templates }) {
    return (
        <PanelLayout title="Шаблони" wide>
            <header className="page-header">
                <h2>Шаблони</h2>
                <p>
                    Живий PHP-ленд з templates/ — клік по превʼю відкриє повноцінний зразок у новій вкладці.
                    Вимкнений шаблон не зʼявляється при створенні офера; уже зроблені воронки лишаються.
                </p>
            </header>

            <div className="template-library-grid">
                {templates.map((template) => (
                    <TemplateCard key={template.id} template={template} />
                ))}
            </div>
        </PanelLayout>
    );
}
