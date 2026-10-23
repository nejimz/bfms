import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { api, ensureCsrf, errorMessage } from '../api'
import { useAuth } from '../auth'

const links = [
  { to: '/businesses', label: 'Businesses' },
  { to: '/accounts', label: 'Chart of accounts' },
  { to: '/journals', label: 'Journals' },
  { to: '/trial-balance', label: 'Trial balance' },
]

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
          {links.map((link) => (
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
