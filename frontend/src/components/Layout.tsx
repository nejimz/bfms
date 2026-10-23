import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { api, ensureCsrf, errorMessage } from '../api'
import { useAuth } from '../auth'

const linksBeforeSettings = [{ to: '/businesses', label: 'Businesses' }]

const settingsLinks = [{ to: '/accounts', label: 'Chart of accounts' }]

const linksAfterSettings = [
  { to: '/chart-account-entries', label: 'Chart account entry' },
  { to: '/journals', label: 'Journals' },
  { to: '/trial-balance', label: 'Trial balance' },
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
        Settings
        <Chevron />
      </button>
      {open && (
        <div className="nav-sub" id="settings-menu">
          {settingsLinks.map((link) => (
            <NavLink key={link.to} to={link.to}>
              {link.label}
            </NavLink>
          ))}
        </div>
      )}
    </div>
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
            <NavLink key={link.to} to={link.to}>
              {link.label}
            </NavLink>
          ))}
          <SettingsMenu />
          {linksAfterSettings.map((link) => (
            <NavLink key={link.to} to={link.to}>
              {link.label}
            </NavLink>
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
