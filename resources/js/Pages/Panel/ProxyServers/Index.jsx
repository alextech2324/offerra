import SecretInput from '@/Components/SecretInput';
import PanelLayout from '@/Layouts/PanelLayout';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function emptyCreate() {
    return {
        host: '',
        port: 22,
        username: 'root',
        password: '',
        label: '',
        is_active: true,
    };
}

function statusBadge(server) {
    const status = server.health?.status || 'unchecked';
    if (!server.is_active) {
        return <span className="badge badge-warn">Вимкнений</span>;
    }
    if (status === 'ok') {
        return <span className="badge badge-ok">OK</span>;
    }
    if (status === 'down') {
        return <span className="badge badge-error">Down</span>;
    }

    return <span className="badge">—</span>;
}

export default function ProxyServersIndex({ servers = [] }) {
    const { flash, errors = {} } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [checkingId, setCheckingId] = useState(null);
    const createForm = useForm(emptyCreate());
    const editForm = useForm({
        host: '',
        port: 22,
        username: 'root',
        password: '',
        label: '',
        is_active: true,
    });

    const startEdit = (server) => {
        setEditingId(server.id);
        editForm.setData({
            host: server.host ?? '',
            port: server.port || 22,
            username: server.username || 'root',
            password: '',
            label: server.label ?? '',
            is_active: server.is_active !== false,
        });
        editForm.clearErrors();
    };

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('proxy-servers.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        if (!editingId) {
            return;
        }
        editForm.post(route('proxy-servers.update', editingId), {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
        });
    };

    const checkServer = (server) => {
        setCheckingId(server.id);
        router.post(route('proxy-servers.check', server.id), {}, {
            preserveScroll: true,
            onFinish: () => setCheckingId(null),
        });
    };

    const removeServer = (server) => {
        if (!window.confirm(`Прибрати проксі ${server.host}? Офери на ньому лишаться, поки їх не перекинеш на CF.`)) {
            return;
        }
        router.delete(route('proxy-servers.destroy', server.id), { preserveScroll: true });
    };

    const createError = createForm.errors.host || createForm.errors.password || errors?.host;

    return (
        <PanelLayout title="Проксі" wide>
            <header className="page-header cf-pool-header">
                <div>
                    <h2>Проксі-сервери</h2>
                    <p>
                        Свій край замість помаранчевої хмари Cloudflare: HTTPS (Let’s Encrypt) тут,
                        HTTP на origin, країна в <code>CF-IPCountry</code>. Тестуй на кількох доменах з оферів → Край.
                    </p>
                </div>
            </header>

            {flash?.warning && (
                <div className="card" style={{ marginBottom: '1rem', borderColor: '#fbbf24' }}>
                    <p className="card-desc" style={{ color: '#fbbf24', margin: 0 }}>{flash.warning}</p>
                </div>
            )}

            <section className="card">
                <h3>Додати проксі</h3>
                <form className="cf-pool-form" onSubmit={submitCreate} autoComplete="off">
                    <div className="field">
                        <label htmlFor="proxy-label">Назва</label>
                        <input
                            id="proxy-label"
                            type="text"
                            value={createForm.data.label}
                            onChange={(e) => createForm.setData('label', e.target.value)}
                            placeholder="Edge proxy"
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="proxy-host">IP</label>
                        <input
                            id="proxy-host"
                            type="text"
                            value={createForm.data.host}
                            onChange={(e) => createForm.setData('host', e.target.value)}
                            placeholder="172.86.71.141"
                            required
                            spellCheck={false}
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="proxy-port">Порт</label>
                        <input
                            id="proxy-port"
                            type="number"
                            value={createForm.data.port}
                            onChange={(e) => createForm.setData('port', Number(e.target.value))}
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="proxy-user">SSH user</label>
                        <input
                            id="proxy-user"
                            type="text"
                            value={createForm.data.username}
                            onChange={(e) => createForm.setData('username', e.target.value)}
                        />
                    </div>
                    <div className="field">
                        <label htmlFor="proxy-pass">SSH пароль</label>
                        <SecretInput
                            id="proxy-pass"
                            value={createForm.data.password}
                            onChange={(e) => createForm.setData('password', e.target.value)}
                            autoComplete="new-password"
                        />
                    </div>
                    <div className="cf-pool-form__actions">
                        {createError && (
                            <p className="field-hint" style={{ color: '#f87171' }}>{createError}</p>
                        )}
                        <button type="submit" className="btn btn-primary" disabled={createForm.processing}>
                            {createForm.processing ? 'Додаю…' : 'Додати'}
                        </button>
                    </div>
                </form>
            </section>

            <div className="table-wrap cf-pool-table">
                <table>
                    <thead>
                        <tr>
                            <th>Назва</th>
                            <th>IP</th>
                            <th>Оферів</th>
                            <th>Статус</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {servers.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="field-hint">Поки порожньо — додай VPS після bootstrap.</td>
                            </tr>
                        ) : servers.map((server) => (
                            <tr key={server.id} className={server.is_active ? undefined : 'is-muted'}>
                                <td>
                                    <strong>{server.display_label}</strong>
                                    {server.health?.message && (
                                        <div className="field-hint">{server.health.message}</div>
                                    )}
                                </td>
                                <td><code>{server.host}</code></td>
                                <td className="col-num">{server.offers_count}</td>
                                <td>{statusBadge(server)}</td>
                                <td>
                                    <div className="offer-actions">
                                        <button
                                            type="button"
                                            className="btn btn-ghost btn-sm"
                                            disabled={checkingId === server.id}
                                            onClick={() => checkServer(server)}
                                        >
                                            {checkingId === server.id ? '…' : 'Перевірити'}
                                        </button>
                                        {editingId === server.id ? (
                                            <form onSubmit={submitEdit} className="cf-pool-form" style={{ margin: 0 }}>
                                                <input
                                                    type="text"
                                                    value={editForm.data.label}
                                                    onChange={(e) => editForm.setData('label', e.target.value)}
                                                    placeholder="назва"
                                                />
                                                <SecretInput
                                                    value={editForm.data.password}
                                                    onChange={(e) => editForm.setData('password', e.target.value)}
                                                    placeholder="новий пароль"
                                                />
                                                <button type="submit" className="btn btn-primary btn-sm" disabled={editForm.processing}>
                                                    Зберегти
                                                </button>
                                                <button type="button" className="btn btn-ghost btn-sm" onClick={() => setEditingId(null)}>
                                                    Скасувати
                                                </button>
                                            </form>
                                        ) : (
                                            <>
                                                <button type="button" className="btn btn-ghost btn-sm" onClick={() => startEdit(server)}>
                                                    Змінити
                                                </button>
                                                <button type="button" className="btn btn-ghost btn-sm" onClick={() => removeServer(server)}>
                                                    Видалити
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </PanelLayout>
    );
}
