import { useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { api, ensureCsrf, errorMessage } from '../api'
import { useAuth } from '../auth'

const links = [
  { to: '/accounts', label: 'Chart of accounts' },
  { to: '/journals', label: 'Journals' },
  { to: '/trial-balance', label: 'Trial balance' },
]

export function Layout() {
  const { user, clear, refresh } = useAuth()
  const queryClient = useQueryClient()
  const [name, setName] = useState('')
  const [currency, setCurrency] = useState('PHP')
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  async function logout() {
    await ensureCsrf()
    await api.post('/session/logout')
    clear()
  }

  async function reloadBooks() {
    await refresh()
    await queryClient.invalidateQueries({
      predicate: (query) => query.queryKey[0] !== 'user',
    })
  }

  async function switchBusiness(companyId: string) {
    if (!user || companyId === user.company.id) {
      return
    }

    setError(null)

    try {
      await api.post('/api/v1/current-company', { company_id: companyId })
      await reloadBooks()
    } catch (caught) {
      setError(errorMessage(caught))
    }
  }

  async function addBusiness(event: FormEvent) {
    event.preventDefault()
    setPending(true)
    setError(null)

    try {
      await api.post('/api/v1/companies', { name, currency })
      setName('')
      setCurrency('PHP')
      await reloadBooks()
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setPending(false)
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
            {user.role === 'owner' && (
              <form onSubmit={(event) => void addBusiness(event)}>
                <label>
                  New business
                  <input
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    required
                    maxLength={255}
                  />
                </label>
                <label>
                  Currency
                  <input
                    value={currency}
                    onChange={(event) => setCurrency(event.target.value.toUpperCase())}
                    required
                    minLength={3}
                    maxLength={3}
                    pattern="[A-Za-z]{3}"
                  />
                </label>
                <button type="submit" disabled={pending}>
                  Add business
                </button>
              </form>
            )}
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
