import PanelLayout from '@/Layouts/PanelLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) {
        return `${n} Б`;
    }
    if (n < 1024 * 1024) {
        return `${(n / 1024).toFixed(1)} КБ`;
    }

    return `${(n / (1024 * 1024)).toFixed(1)} МБ`;
}

function LandingCard({ landing, busy, onDelete }) {
    const previewUrl = landing.preview_url || '';

    return (
        <article className="template-library-card">
            {previewUrl ? (
                <a
                    className="template-thumb-link"
                    href={previewUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={`Превʼю ${landing.name}`}
                >
                    <div className="template-live-frame-wrap">
                        <iframe
                            className="template-live-frame"
                            src={previewUrl}
                            title={`Превʼю ${landing.name}`}
                            tabIndex={-1}
                            loading="lazy"
                            sandbox="allow-scripts allow-same-origin"
                        />
                        <div className="template-live-overlay">
                            <span>↗ Відкрити превʼю</span>
                        </div>
                    </div>
                </a>
            ) : (
                <div className="template-thumb-link" style={{ cursor: 'default' }}>
                    <div className="template-browser">
                        <div className="template-browser-screen template-browser-screen--empty">
                            <p>Превʼю недоступне</p>
                        </div>
                    </div>
                </div>
            )}
            <div className="template-library-body">
                <div className="template-library-top">
                    <h3>{landing.name}</h3>
                    <span className="badge">{landing.is_demo ? 'Тест' : 'Вайт'}</span>
                </div>
                <p className="template-library-meta">
                    <span>{landing.file_count} файл.{landing.file_count === 1 ? '' : 'ів'}</span>
                    <span>{formatBytes(landing.size_bytes)}</span>
                </p>
                <p className="template-library-desc">
                    {landing.original_filename || landing.entry_file}
                    {landing.created_at ? ` · ${landing.created_at}` : ''}
                </p>
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
                    <Link
                        href={route('offers.create', { fresh: 1 })}
                        className="btn btn-ghost"
                    >
                        До офера
                    </Link>
                    <button
                        type="button"
                        className="btn btn-ghost"
                        disabled={busy}
                        onClick={() => onDelete(landing)}
                    >
                        Видалити
                    </button>
                </div>
            </div>
        </article>
    );
}

export default function LandingsIndex({ landings = [] }) {
    const { errors } = usePage().props;
    const fileRef = useRef(null);
    const [busyId, setBusyId] = useState(null);
    const form = useForm({
        name: '',
        zip: null,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('landings.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData({ name: '', zip: null });
                if (fileRef.current) {
                    fileRef.current.value = '';
                }
            },
        });
    };

    const removeLanding = (landing) => {
        if (!window.confirm(`Видалити вайт «${landing.name}»?`)) {
            return;
        }

        setBusyId(landing.id);
        router.delete(route('landings.destroy', landing.id), {
            preserveScroll: true,
            onFinish: () => setBusyId(null),
        });
    };

    return (
        <PanelLayout title="Лендінги" wide>
            <header className="page-header">
                <h2>Лендінги</h2>
                <p>
                    Бібліотека вайтів для кло. ZIP з <code>index.html</code> або
                    <code>index.php</code> у корені (чи в одній папці). PHP зберігаємо
                    як файли — на панелі не виконуємо, щоб вайт не зміг запуститись
                    тут. Кло можна обрати при створенні офера і в редагуванні існуючого.
                </p>
            </header>

            <form className="card landing-upload" onSubmit={submit}>
                <h3>Новий вайт</h3>
                <div className="landing-upload__row">
                    <div className="field">
                        <label htmlFor="landing-name">Назва</label>
                        <input
                            id="landing-name"
                            type="text"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="Наприклад, News IE"
                            maxLength={80}
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="landing-zip">ZIP</label>
                        <input
                            id="landing-zip"
                            ref={fileRef}
                            type="file"
                            accept=".zip,application/zip"
                            onChange={(event) => form.setData('zip', event.target.files?.[0] ?? null)}
                        />
                        {errors.zip && <p className="field-hint" style={{ color: '#f87171' }}>{errors.zip}</p>}
                        {form.errors.zip && !errors.zip && (
                            <p className="field-hint" style={{ color: '#f87171' }}>{form.errors.zip}</p>
                        )}
                        {form.progress && (
                            <p className="field-hint">Завантаження {form.progress.percentage}%</p>
                        )}
                    </div>
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={form.processing || !form.data.zip}
                    >
                        {form.processing ? 'Завантаження…' : 'Додати вайт'}
                    </button>
                </div>
            </form>

            {landings.length === 0 ? (
                <div className="card" style={{ marginTop: '1rem' }}>
                    <p className="card-desc" style={{ margin: 0 }}>
                        Ще немає вайтів. Завантаж ZIP зверху — і він зʼявиться тут для вибору в офері.
                    </p>
                </div>
            ) : (
                <div className="template-library-grid" style={{ marginTop: '1.25rem' }}>
                    {landings.map((landing) => (
                        <LandingCard
                            key={landing.id}
                            landing={landing}
                            busy={busyId === landing.id}
                            onDelete={removeLanding}
                        />
                    ))}
                </div>
            )}
        </PanelLayout>
    );
}
