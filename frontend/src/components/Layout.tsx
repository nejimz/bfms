import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, Link } from 'react-router-dom'
import { api, ensureCsrf, errorMessage, isUnauthorized } from '../api'
import { useAuth } from '../auth'
import { chartAccountModuleList } from '../chartAccountModules'
import { canViewDashboard } from '../types'

type MenuIcon = 'dashboard' | 'chart' | 'summary' | 'businesses' | 'categories' | 'accounts' | 'entry' | 'journals' | 'balance' | 'users'

type MenuItem = {
  to: string
  label: string
  icon: MenuIcon
}

const settingsLinks: MenuItem[] = [
  { to: '/businesses', label: 'Businesses', icon: 'businesses' },
  { to: '/users', label: 'Users', icon: 'users' },
  { to: '/categories', label: 'Categories', icon: 'categories' },
  { to: '/chart-account-columns', label: 'Account Columns', icon: 'entry' },
  { to: '/chart-account-entries', label: 'Chart Account Entry', icon: 'entry' },
  { to: '/accounts', label: 'Chart Of Accounts', icon: 'accounts' },
]

const moduleLinks: MenuItem[] = chartAccountModuleList.map((module) => ({
  to: module.path,
  label: module.title,
  icon: 'entry',
}))

const dashboardLink: MenuItem = { to: '/dashboard', label: 'Dashboard', icon: 'dashboard' }
const incomeExpenseLink: MenuItem = { to: '/income-expense', label: 'Income & Expenses', icon: 'chart' }
const yearlySummaryLink: MenuItem = { to: '/yearly-summary', label: 'Yearly Summary', icon: 'summary' }

const linksAfterSettings: MenuItem[] = [
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
      {name === 'dashboard' && (
        <>
          <path d="M4 19V10" />
          <path d="M10 19V5" />
          <path d="M16 19v-7" />
          <path d="M20 19H4" />
        </>
      )}
      {name === 'chart' && (
        <>
          <path d="M4 19V10" />
          <path d="M4 19h16" />
          <rect x="7" y="12" width="3" height="7" />
          <rect x="12" y="7" width="3" height="12" />
          <rect x="17" y="14" width="3" height="5" />
        </>
      )}
      {name === 'summary' && (
        <>
          <path d="M8 7h11" />
          <path d="M8 12h11" />
          <path d="M8 17h7" />
          <path d="M4 7h.01" />
          <path d="M4 12h.01" />
          <path d="M4 17h.01" />
        </>
      )}
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
      {name === 'users' && (
        <>
          <circle cx="12" cy="8" r="3" />
          <path d="M5 19c1.2-3 3.6-4.5 7-4.5s5.8 1.5 7 4.5" />
        </>
      )}
      {name === 'categories' && (
        <>
          <path d="M4 10.5 10.5 4H20v9.5L13.5 20a2 2 0 0 1-2.8 0z" />
          <circle cx="9" cy="9" r="1" />
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
  const [loggingOut, setLoggingOut] = useState(false)

  async function logout() {
    setError(null)
    setLoggingOut(true)

    try {
      await ensureCsrf()
      await api.post('/session/logout')
      clear()
    } catch (caught) {
      if (isUnauthorized(caught)) {
        clear()

        return
      }

      setError(errorMessage(caught))
      setLoggingOut(false)
    }
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
          {user && canViewDashboard(user.role) && (
            <>
              <MenuLink item={dashboardLink} />
              <MenuLink item={yearlySummaryLink} />
              <MenuLink item={incomeExpenseLink} />
            </>
          )}
          {user?.role === 'admin' && (
            <>
              <SettingsMenu />
              {linksAfterSettings.map((link) => (
                <MenuLink key={link.to} item={link} />
              ))}
            </>
          )}
          {moduleLinks.map((link) => (
            <MenuLink key={link.to} item={link} />
          ))}
        </nav>
        {user && (
          <div className="session">
            <div className="session-identity">
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
            </div>
            <div className="session-actions">
              <Link className="button-link session-link" to="/password">
                Password
              </Link>
              <button type="button" className="logout" onClick={() => void logout()} disabled={loggingOut}>
                {loggingOut ? 'Logging out…' : 'Log out'}
              </button>
            </div>
          </div>
        )}
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  )
}
