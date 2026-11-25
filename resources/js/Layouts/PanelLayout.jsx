import FlashToast from '@/Components/FlashToast';
import PanelNavLink from '@/Components/PanelNavLink';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const navItems = [
    { href: '/dashboard', icon: '📊', label: 'Дашборд' },
    { href: '/offers', icon: '📋', label: 'Оффери' },
    { href: '/offers/stats', icon: '📈', label: 'Статистика' },
    { href: '/leads', icon: '📨', label: 'Ліди' },
    { href: '/mirrors', icon: '🪞', label: 'Дзеркала' },
    { href: '/offers/archive', icon: '📦', label: 'Архів доменів' },
    { href: '/offers/create?fresh=1', icon: '➕', label: 'Створити оффер' },
    { href: '/templates', icon: '🎨', label: 'Шаблони' },
    { href: '/settings', icon: '⚙️', label: 'Налаштування' },
];

const adminNavItems = [
    { href: '/users', icon: '👥', label: 'Користувачі' },
    { href: '/origin-servers', icon: '🖥️', label: 'Origin-сервери' },
    { href: '/cloudflare-accounts', icon: '☁️', label: 'Запасні CF' },
    { href: '/backup', icon: '💾', label: 'Бекап' },
    { href: '/min-deposits', icon: '💵', label: 'Мін. депи' },
    { href: '/funnel-alerts', icon: '🔔', label: 'Алерти воронок' },
];

export default function PanelLayout({ title, children, wide = false, fullWidth = false }) {
    const { url, auth, panel } = usePage().props;
    const [menuOpen, setMenuOpen] = useState(false);

    const isActive = (href) => {
        const path = url ?? '';
        if (href === '/dashboard') {
            return path === '/dashboard';
        }
        if (href === '/offers') {
            return path === '/offers';
        }
        if (href === '/offers/stats') {
            return path === '/offers/stats' || path.startsWith('/offers/stats?');
        }
        if (href === '/offers/archive') {
            return path === '/offers/archive';
        }
        if (href === '/mirrors') {
            return path === '/mirrors' || path.startsWith('/mirrors/');
        }
        if (href === '/leads') {
            return path === '/leads' || path.startsWith('/leads');
        }

        return path === href || path.startsWith(`${href}/`);
    };

    const user = auth?.user;

    const roleLabel = user?.role === 'admin'
        ? 'Адмін'
        : (user?.role_label ?? 'Користувач');

    useEffect(() => {
        setMenuOpen(false);
    }, [url]);

    useEffect(() => {
        if (!menuOpen) {
            return undefined;
        }

        const onKey = (event) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        };

        document.body.classList.add('panel-menu-open');
        window.addEventListener('keydown', onKey);

        return () => {
            document.body.classList.remove('panel-menu-open');
            window.removeEventListener('keydown', onKey);
        };
    }, [menuOpen]);

    const nav = (
        <>
            <nav className="sidebar-nav" onClick={() => setMenuOpen(false)}>
                {navItems.map((item) => (
                    <PanelNavLink
                        key={item.href}
                        href={item.href}
                        active={isActive(item.href)}
                        icon={item.icon}
                    >
                        {item.label}
                    </PanelNavLink>
                ))}
                {user?.role === 'admin' && adminNavItems.map((item) => (
                    <PanelNavLink
                        key={item.href}
                        href={item.href}
                        active={isActive(item.href)}
                        icon={item.icon}
                    >
                        {item.label}
                    </PanelNavLink>
                ))}
            </nav>
            <div className="sidebar-footer">
                {user && (
                    <dl className="sidebar-account">
                        <div className="sidebar-account-row">
                            <dt>Affiliate</dt>
                            <dd>{panel?.affiliate_tag ?? 'BRO'}</dd>
                        </div>
                        <div className="sidebar-account-row">
                            <dt>Email</dt>
                            <dd>{user.email}</dd>
                        </div>
                        <div className="sidebar-account-row">
                            <dt>Роль</dt>
                            <dd>
                                <span
                                    className={`sidebar-role${
                                        user.role === 'admin' ? ' sidebar-role--admin' : ''
                                    }`}
                                >
                                    {roleLabel}
                                </span>
                            </dd>
                        </div>
                    </dl>
                )}
                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="sidebar-logout"
                >
                    <span className="icon">🚪</span>
                    Вийти з акаунта
                </Link>
            </div>
        </>
    );

    return (
        <>
            <Head title={title} />
            <div className={`app${menuOpen ? ' is-menu-open' : ''}`}>
                <header className="mobile-topbar">
                    <button
                        type="button"
                        className="mobile-topbar__menu"
                        aria-label={menuOpen ? 'Закрити меню' : 'Відкрити меню'}
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        <span className="mobile-topbar__burger" aria-hidden="true">
                            <span />
                            <span />
                            <span />
                        </span>
                    </button>
                    <div className="mobile-topbar__brand">
                        <strong>Offerra</strong>
                        {title ? <span>{title}</span> : null}
                    </div>
                    <Link
                        href={route('offers.create')}
                        className="mobile-topbar__create"
                        aria-label="Створити оффер"
                    >
                        +
                    </Link>
                </header>

                <div
                    className="mobile-nav-backdrop"
                    hidden={!menuOpen}
                    onClick={() => setMenuOpen(false)}
                    aria-hidden="true"
                />

                <aside className={`sidebar${menuOpen ? ' is-open' : ''}`}>
                    <div className="sidebar-brand">
                        <h1>Offerra</h1>
                        <p>панель керування лендами</p>
                    </div>
                    {nav}
                </aside>

                <main
                    className={[
                        'main',
                        fullWidth ? 'main--full' : '',
                        wide ? 'main--wide' : '',
                    ].filter(Boolean).join(' ')}
                >
                    <div className="main-inner">{children}</div>
                </main>
            </div>
            <FlashToast />
        </>
    );
}
