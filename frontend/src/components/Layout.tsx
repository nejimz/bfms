import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { api, ensureCsrf, errorMessage } from '../api'
import { useAuth } from '../auth'

type MenuIcon = 'businesses' | 'accounts' | 'entry' | 'journals' | 'balance'

type MenuItem = {
  to: string
  label: string
  icon: MenuIcon
}

const linksBeforeSettings: MenuItem[] = [{ to: '/businesses', label: 'Businesses', icon: 'businesses' }]

const settingsLinks: MenuItem[] = [{ to: '/accounts', label: 'Chart Of Accounts', icon: 'accounts' }]

const linksAfterSettings: MenuItem[] = [
  { to: '/chart-account-entries', label: 'Chart Account Entry', icon: 'entry' },
  { to: '/chart-account-columns', label: 'Account columns', icon: 'entry' },
  { to: '/journals', label: 'Journals', icon: 'journals' },
  { to: '/trial-balance', label: 'Trial Balance', icon: 'balance' },
]

function settingsRouteActive(pathname: string): boolean {
  return settingsLinks.some((link) => pathname === link.to || pathname.startsWith(`${link.to}/`))
}

function SettingsMenu() {
  const location = useLocation()
  const active = settingsRouteActive(location.pathname)
  const [open, setOpen] = useState(true)

  useEffect(() => {
    if (active) {
      setOpen(true)
    }
  }, [active])

  return (
    <div className="nav-group">
      <button
        type="button"
        className={active && !open ? 'active' : undefined}
        aria-expanded={open}
        aria-controls="settings-menu"
        onClick={() => setOpen((current) => !current)}
      >
        <span className="nav-label">
          <NavIcon name="settings" />
          Settings
        </span>
        <Chevron />
      </button>
      {open && (
        <div className="nav-sub" id="settings-menu">
          {settingsLinks.map((link) => (
            <MenuLink key={link.to} item={link} />
          ))}
        </div>
      )}
    </div>
  )
}

function MenuLink({ item }: { item: MenuItem }) {
  return (
    <NavLink to={item.to}>
      <NavIcon name={item.icon} />
      {item.label}
    </NavLink>
  )
}

function NavIcon({ name }: { name: MenuIcon | 'settings' }) {
  return (
    <svg
      className="nav-icon"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {name === 'businesses' && (
        <>
          <rect x="3" y="7" width="18" height="13" rx="2" />
          <path d="M8 7V5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2" />
          <path d="M3 12h18" />
        </>
      )}
      {name === 'settings' && (
        <>
          <path d="M4 7h3.5M14 7h6" />
          <circle cx="10" cy="7" r="2.25" />
          <path d="M4 12h8M18.5 12H20" />
          <circle cx="15" cy="12" r="2.25" />
          <path d="M4 17h6M14.5 17H20" />
          <circle cx="11" cy="17" r="2.25" />
        </>
      )}
      {name === 'accounts' && (
        <>
          <rect x="3" y="4" width="18" height="16" rx="2" />
          <path d="M3 9h18M3 14h18M9 9v11" />
        </>
      )}
      {name === 'entry' && (
        <>
          <path d="M14 3H6v18h12V9" />
          <path d="M14 3v6h6" />
          <path d="M12 12v6M9 15h6" />
        </>
      )}
      {name === 'journals' && (
        <>
          <path d="M12 6C10 4.4 7.8 4 5 4v15c2.8 0 5 .4 7 2 2-1.6 4.2-2 7-2V4c-2.8 0-5 .4-7 2z" />
          <path d="M12 6v15" />
        </>
      )}
      {name === 'balance' && (
        <>
          <path d="M12 3v2M12 7v13" />
          <path d="M7 20h10" />
          <path d="M4 7h16" />
          <path d="M7 7 4 14h6z" />
          <path d="M17 7 14 14h6z" />
        </>
      )}
    </svg>
  )
}

function Chevron() {
  return (
    <svg className="nav-chevron" viewBox="0 0 12 12" aria-hidden="true">
      <path
        d="M4.5 2.5 8 6 4.5 9.5"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export function Layout() {
  const { user, clear, selectCompany } = useAuth()
  const [error, setError] = useState<string | null>(null)

  async function logout() {
    await ensureCsrf()
    await api.post('/session/logout')
    clear()
  }

  async function switchBusiness(companyId: string) {
    if (!user || companyId === user.company.id) {
      return
    }

    setError(null)

    try {
      await selectCompany(companyId)
    } catch (caught) {
      setError(errorMessage(caught))
    }
  }

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand-mark">BF</span>
          <div>
            <strong>BFMS</strong>
            <small>General ledger</small>
          </div>
        </div>
        <nav>
          {linksBeforeSettings.map((link) => (
            <MenuLink key={link.to} item={link} />
          ))}
          <SettingsMenu />
          {linksAfterSettings.map((link) => (
            <MenuLink key={link.to} item={link} />
          ))}
        </nav>
        {user && (
          <div className="session">
            {user.companies.length > 1 ? (
              <label>
                Business
                <select
                  value={user.company.id}
                  onChange={(event) => void switchBusiness(event.target.value)}
                >
                  {user.companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name}
                    </option>
                  ))}
                </select>
              </label>
            ) : (
              <strong>{user.company.name}</strong>
            )}
            <span>
              {user.company.currency} · {user.role}
            </span>
            <span>{user.name}</span>
            {error && <p className="form-error">{error}</p>}
            <button type="button" className="logout" onClick={() => void logout()}>
              Log out
            </button>
          </div>
        )}
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  )
}
