import SecretInput from '@/Components/SecretInput';
import PanelLayout from '@/Layouts/PanelLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

function formFromSettings(settings, userId = null) {
    return {
        user_id: userId ?? '',
        affiliate_tag: settings.affiliate_tag ?? 'BRO',
        crm_api_key: settings.crm_api_key ?? '',
        tg_bot_token: settings.tg_bot_token ?? '',
        tg_chat_id: settings.tg_chat_id ?? '',
        tg_group_chat_id: settings.tg_group_chat_id ?? '',
        dynadot_api_key: settings.dynadot_api_key ?? '',
        dynadot_api_secret: settings.dynadot_api_secret ?? '',
        dynadot_contact_id: settings.dynadot_contact_id ?? '',
        dynadot_account_name: settings.dynadot_account_name ?? '',
        cloudflare_api_token: settings.cloudflare_api_token ?? '',
        cloudflare_account_id: settings.cloudflare_account_id ?? '',
        cloudflare_default_proxied: settings.cloudflare_default_proxied ?? true,
        cloudflare_account_name: settings.cloudflare_account_name ?? '',
        test_domain: 'reserve-safegrove-ie.com',
    };
}

export default function SettingsIndex({ settings, settingsUser, users = [] }) {
    const { errors: pageErrors, auth } = usePage().props;
    const [cloudflareTest, setCloudflareTest] = useState(null);
    const [testingCloudflare, setTestingCloudflare] = useState(false);
    const [dynadotBalance, setDynadotBalance] = useState(null);
    const [dynadotBalanceLoading, setDynadotBalanceLoading] = useState(false);
    const [dynadotBalanceError, setDynadotBalanceError] = useState('');
    const [uploadingGscVerification, setUploadingGscVerification] = useState(false);
    const gscVerificationInputRef = useRef(null);

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm(
        formFromSettings(settings, settingsUser.id),
    );

    const syncFromSettings = (nextSettings) => {
        const next = formFromSettings(nextSettings, settingsUser.id);
        Object.entries(next).forEach(([key, value]) => setData(key, value));
    };

    const changeSettingsUser = (userId) => {
        router.get(
            route('settings.index', userId ? { user: userId } : {}),
            {},
            { preserveState: false },
        );
    };

    const submit = (e) => {
        e.preventDefault();
        patch(route('settings.update'), {
            preserveScroll: true,
            onSuccess: (page) => {
                syncFromSettings(page.props.settings);
            },
        });
    };

    const testCloudflare = async () => {
        setTestingCloudflare(true);
        setCloudflareTest(null);

        try {
            const { data: result } = await axios.post(route('settings.test-cloudflare'), {
                ...data,
                cloudflare_slot: 'primary',
            });
            setCloudflareTest(result);
        } catch (error) {
            setCloudflareTest({
                ok: false,
                message: error.response?.data?.message ?? 'Не вдалося перевірити Cloudflare API',
            });
        } finally {
            setTestingCloudflare(false);
        }
    };

    const loadDynadotBalance = async () => {
        if (!settings.has_dynadot_api_key) {
            return;
        }

        setDynadotBalanceLoading(true);
        setDynadotBalanceError('');

        try {
            const { data: result } = await axios.get(route('domains.balance'), {
                params: { user_id: settingsUser.id },
            });
            if (!result.ok) {
                setDynadotBalanceError(result.message ?? 'Не вдалося отримати баланс');
                return;
            }
            setDynadotBalance(result.balance ?? null);
        } catch (error) {
            setDynadotBalanceError(
                error.response?.data?.message ?? 'Не вдалося отримати баланс Dynadot',
            );
        } finally {
            setDynadotBalanceLoading(false);
        }
    };

    useEffect(() => {
        if (settings.has_dynadot_api_key) {
            loadDynadotBalance();
        }
    }, [settingsUser.id, settings.has_dynadot_api_key]);

    const pickGscVerificationFile = () => {
        gscVerificationInputRef.current?.click();
    };

    const uploadGscVerificationFile = (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        setUploadingGscVerification(true);

        const formData = new FormData();
        formData.append('verification_file', file);
        formData.append('user_id', String(settingsUser.id));

        router.post(route('settings.gsc-verification.store'), formData, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setUploadingGscVerification(false),
        });
    };

    const removeGscVerificationFile = () => {
        if (!settings.has_gsc_verification_file) {
            return;
        }

        setUploadingGscVerification(true);
        router.delete(route('settings.gsc-verification.destroy'), {
            data: { user_id: settingsUser.id },
            preserveScroll: true,
            onFinish: () => setUploadingGscVerification(false),
        });
    };

    return (
        <PanelLayout title="Налаштування">
            <header className="page-header">
                <h2>Налаштування профілю</h2>
                <p>
                    {settingsUser.is_self
                        ? 'Загальні параметри для всіх ваших офферів — один раз, далі підставляються автоматично'
                        : `Налаштування користувача ${settingsUser.name ?? settingsUser.email}`}
                </p>
            </header>

            {users.length > 0 && (
                <div className="filter-bar" style={{ marginBottom: '1rem' }}>
                    <select
                        aria-label="Користувач"
                        value={String(settingsUser.id)}
                        onChange={(e) => changeSettingsUser(e.target.value)}
                    >
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name ?? user.email}
                            </option>
                        ))}
                    </select>
                    {!settingsUser.is_self && (
                        <Link href={route('offers.index', { user: settingsUser.id })} className="btn btn-ghost">
                            Оффери користувача
                        </Link>
                    )}
                </div>
            )}

            <form onSubmit={submit}>
                <section className="card">
                    <h3>Affiliate / CRM</h3>
                    <div className="field-row">
                        <div className="field">
                            <label htmlFor="aff-tag">Мітка affiliate</label>
                            <input
                                type="text"
                                id="aff-tag"
                                value={data.affiliate_tag}
                                onChange={(e) => setData('affiliate_tag', e.target.value)}
                            />
                        </div>
                        <div className="field">
                            <label htmlFor="crm-key">CRM API key</label>
                            <SecretInput
                                id="crm-key"
                                value={data.crm_api_key}
                                onChange={(e) => setData('crm_api_key', e.target.value)}
                                placeholder="YourLeads API key"
                            />
                        </div>
                    </div>
                </section>

                <section className="card">
                    <h3>Telegram</h3>
                    <div className="field">
                        <label htmlFor="tg-token">Bot token</label>
                        <SecretInput
                            id="tg-token"
                            value={data.tg_bot_token}
                            onChange={(e) => setData('tg_bot_token', e.target.value)}
                            placeholder="123456:ABC..."
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="tg-chat">Chat ID (особистий)</label>
                        <input
                            type="text"
                            id="tg-chat"
                            value={data.tg_chat_id}
                            onChange={(e) => setData('tg_chat_id', e.target.value)}
                            placeholder="123456789"
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="tg-group-chat">Chat ID групи (опційно)</label>
                        <input
                            type="text"
                            id="tg-group-chat"
                            value={data.tg_group_chat_id}
                            onChange={(e) => setData('tg_group_chat_id', e.target.value)}
                            placeholder="-1001234567890"
                        />
                        <p className="field-hint">
                            Додайте бота в групу — ліди дублюватимуться туди. Якщо порожньо, лише в особистий чат.
                        </p>
                    </div>
                    {auth?.user?.role === 'admin' && (settings.lead_postback_url || settings.sales_postback_url) && (
                        <>
                            {settings.lead_postback_url && (
                                <div className="field">
                                    <label htmlFor="sales-s2s-lead">Postback — лід</label>
                                    <input
                                        type="text"
                                        id="sales-s2s-lead"
                                        readOnly
                                        value={settings.lead_postback_url}
                                        onFocus={(e) => e.target.select()}
                                    />
                                </div>
                            )}
                            {settings.sales_postback_url && (
                                <div className="field">
                                    <label htmlFor="sales-s2s">Postback — деп</label>
                                    <input
                                        type="text"
                                        id="sales-s2s"
                                        readOnly
                                        value={settings.sales_postback_url}
                                        onFocus={(e) => e.target.select()}
                                    />
                                </div>
                            )}
                            <p className="field-hint">
                                Один URL на всю панель — встав у YourLeads як GET S2S.
                                <code>aff_sub3</code> — click id ленда: з нього панель знаходить офер і баєра.
                                Невідомі кліки ігноруються. Деп також прийде в Telegram власника офера (subid + сума).
                            </p>
                        </>
                    )}
                </section>

                <section className="card">
                    <h3>Dynadot (домени)</h3>
                    <p className="card-desc">API для пошуку та реєстрації доменів при створенні оффера</p>
                    <div className="field">
                        <label htmlFor="dynadot-account-name">Назва акаунта</label>
                        <input
                            type="text"
                            id="dynadot-account-name"
                            value={data.dynadot_account_name}
                            onChange={(e) => setData('dynadot_account_name', e.target.value)}
                            placeholder="DynadotEGO"
                        />
                        <p className="field-hint">
                            Коротка мітка для таблиці оферів (фіксується при створенні офера).
                        </p>
                    </div>
                    <div className="field">
                        <label htmlFor="dynadot-api-key">API key</label>
                        <SecretInput
                            id="dynadot-api-key"
                            value={data.dynadot_api_key}
                            onChange={(e) => setData('dynadot_api_key', e.target.value)}
                            placeholder="Dynadot API key"
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="dynadot-contact">Contact ID</label>
                        <input
                            type="text"
                            id="dynadot-contact"
                            value={data.dynadot_contact_id}
                            onChange={(e) => setData('dynadot_contact_id', e.target.value)}
                            placeholder="1885528 (лише цифри, без C-)"
                        />
                        <p className="field-hint">
                            У Dynadot показується як C-1885528 — вставляйте лише <strong>1885528</strong>.
                        </p>
                    </div>
                    {settings.has_dynadot_api_key && (
                        <div className="domain-balance-row" style={{ marginTop: '0.75rem' }}>
                            <span className={`domain-balance${dynadotBalance?.low_balance ? ' is-low' : ''}`}>
                                {dynadotBalanceLoading
                                    ? 'Баланс Dynadot…'
                                    : dynadotBalance?.balances?.length
                                        ? `Баланс: ${dynadotBalance.balances.map((item) => `${item.amount} ${item.currency}`).join(', ')}`
                                        : 'Баланс: —'}
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
                    {dynadotBalance?.low_balance && (
                        <p className="field-hint field-hint--warn">
                            Баланс низький — поповніть Dynadot, щоб купувати домени.
                        </p>
                    )}
                    {dynadotBalanceError && (
                        <p className="field-hint" style={{ color: '#f87171' }}>{dynadotBalanceError}</p>
                    )}
                </section>

                <section className="card">
                    <h3>Cloudflare (DNS)</h3>
                    <p className="card-desc">
                        API для DNS: зона, A-запис, NS у Dynadot при створенні оффера.
                        {auth?.user?.role === 'admin' ? (
                            <>
                                {' '}
                                Запасні акаунти — у вкладці{' '}
                                <Link href={route('cloudflare-accounts.index')}>Запасні CF</Link>.
                            </>
                        ) : null}
                    </p>
                    <div className="field">
                        <label htmlFor="cf-account-name">Назва акаунта</label>
                        <input
                            type="text"
                            id="cf-account-name"
                            value={data.cloudflare_account_name}
                            onChange={(e) => setData('cloudflare_account_name', e.target.value)}
                            placeholder="CloudflareEGO"
                        />
                        <p className="field-hint">
                            Коротка мітка для таблиці оферів (фіксується при створенні офера).
                        </p>
                    </div>
                    <div className="field">
                        <label htmlFor="cf-token">API token</label>
                        <SecretInput
                            id="cf-token"
                            value={data.cloudflare_api_token}
                            onChange={(e) => setData('cloudflare_api_token', e.target.value)}
                            placeholder="Cloudflare API token"
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="cf-account">Account ID</label>
                        <input
                            type="text"
                            id="cf-account"
                            value={data.cloudflare_account_id}
                            onChange={(e) => setData('cloudflare_account_id', e.target.value)}
                        />
                    </div>
                    <label className="field-check" htmlFor="cf-proxied">
                        <input
                            id="cf-proxied"
                            type="checkbox"
                            checked={data.cloudflare_default_proxied}
                            onChange={(e) => setData('cloudflare_default_proxied', e.target.checked)}
                        />
                        <span>Proxied (помаранчева хмарка) за замовчуванням</span>
                    </label>
                    <div style={{ marginTop: '1rem' }}>
                        <button
                            type="button"
                            className="btn btn-ghost"
                            disabled={testingCloudflare}
                            onClick={testCloudflare}
                        >
                            {testingCloudflare ? 'Перевірка…' : 'Перевірити Cloudflare API'}
                        </button>
                    </div>
                    {cloudflareTest && (
                        <p
                            className="field-hint"
                            style={{
                                marginTop: '0.75rem',
                                color: cloudflareTest.ok ? 'var(--accent)' : '#f87171',
                            }}
                        >
                            {cloudflareTest.message}
                        </p>
                    )}
                </section>

                <section className="card">
                    <h3>Google Search Console</h3>
                    <p className="card-desc">
                        Файл <code>google….html</code> з головного GSC — один раз тут, далі додається до кожного нового оффера.
                        Нові офери сабмітяться на <strong>головний</strong> Google. Indexed перевіряється по всіх підключених акаунтах
                        (старі домени, які сабмітили в іншому GSC). Перевірка через ~24 год, далі раз на добу; червона — якщо за 7 днів немає.
                    </p>
                    <input
                        ref={gscVerificationInputRef}
                        type="file"
                        accept=".html,text/html"
                        hidden
                        onChange={uploadGscVerificationFile}
                    />
                    <div className="verification-file">
                        {settings.has_gsc_verification_file ? (
                            <>
                                <span className="verification-file__link">{settings.gsc_verification_filename}</span>
                                <button
                                    type="button"
                                    className="verification-file__remove"
                                    disabled={uploadingGscVerification}
                                    onClick={removeGscVerificationFile}
                                    title="Видалити файл"
                                >
                                    ×
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                className="btn btn-ghost btn-sm verification-file__upload"
                                disabled={uploadingGscVerification}
                                onClick={pickGscVerificationFile}
                            >
                                {uploadingGscVerification ? 'Завантаження…' : 'Завантажити GSC файл'}
                            </button>
                        )}
                    </div>

                    {(settings.google_accounts ?? []).length > 0 && (
                        <ul className="google-account-list">
                            {(settings.google_accounts ?? []).map((account) => (
                                <li key={account.id} className="google-account-row">
                                    <div className="google-account-row__meta">
                                        <span className="google-account-row__email">{account.email}</span>
                                        {account.is_primary ? (
                                            <span className="google-account-row__badge">Головний</span>
                                        ) : (
                                            <span className="google-account-row__hint">перевірка</span>
                                        )}
                                        {account.connected_at && (
                                            <span className="field-hint" style={{ margin: 0 }}>
                                                з {account.connected_at}
                                            </span>
                                        )}
                                    </div>
                                    <div className="google-account-row__actions">
                                        {!account.is_primary && (
                                            <button
                                                type="button"
                                                className="btn btn-ghost btn-sm"
                                                onClick={() =>
                                                    router.post(
                                                        route('settings.google.primary', account.id),
                                                        { user_id: settingsUser.id },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Зробити головним
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            className="btn btn-ghost btn-sm"
                                            onClick={() =>
                                                router.delete(route('settings.google.disconnect', account.id), {
                                                    data: { user_id: settingsUser.id },
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            Відключити
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div style={{ marginTop: '1rem', display: 'flex', gap: '0.75rem', alignItems: 'center', flexWrap: 'wrap' }}>
                        <a
                            className={`btn btn-sm ${settings.google_oauth_configured ? '' : 'btn-ghost'}`}
                            href={
                                settings.google_oauth_configured
                                    ? route('settings.google.redirect', { user_id: settingsUser.id })
                                    : undefined
                            }
                            aria-disabled={!settings.google_oauth_configured}
                            onClick={(e) => {
                                if (!settings.google_oauth_configured) {
                                    e.preventDefault();
                                }
                            }}
                        >
                            {(settings.google_accounts ?? []).length > 0 ? 'Додати Google' : 'Підключити Google'}
                        </a>
                    </div>
                    {!settings.google_oauth_configured && (
                        <p className="field-hint" style={{ color: '#f87171', marginTop: '0.5rem' }}>
                            На сервері ще немає GOOGLE_OAUTH_CLIENT_ID / SECRET.
                        </p>
                    )}
                    {settings.google_oauth_configured && !settings.has_gsc_verification_file && (
                        <p className="field-hint" style={{ marginTop: '0.5rem' }}>
                            Для нових оферів завантажте HTML-файл верифікації з головного Google-акаунта.
                            Додаткові акаунти можна додати лише для перевірки Indexed.
                        </p>
                    )}
                    {pageErrors?.gsc_verification && (
                        <p className="field-hint" style={{ color: '#f87171', marginTop: '0.5rem' }}>
                            {pageErrors.gsc_verification}
                        </p>
                    )}
                    {pageErrors?.google_oauth && (
                        <p className="field-hint" style={{ color: '#f87171', marginTop: '0.5rem' }}>
                            {pageErrors.google_oauth}
                        </p>
                    )}
                </section>

                <div className="btn-row">
                    <button type="submit" className="btn btn-primary" disabled={processing}>
                        {processing ? 'Збереження…' : 'Зберегти налаштування'}
                    </button>
                    <Link href={route('offers.create')} className="btn btn-ghost">
                        Далі: створити оффер →
                    </Link>
                </div>

                {recentlySuccessful && (
                    <p className="field-hint" style={{ color: 'var(--accent)', marginTop: '1rem' }}>
                        Збережено
                    </p>
                )}
            </form>
        </PanelLayout>
    );
}
