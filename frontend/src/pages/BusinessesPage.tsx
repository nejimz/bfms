import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import type { Business } from '../types'
import { readResource } from '../types'

export function BusinessesPage() {
  const navigate = useNavigate()
  const { user, selectCompany } = useAuth()
  const businesses = useQuery({
    queryKey: ['companies', user?.id],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/companies')
      return (response.data.data as Array<{ id: string; attributes: Omit<Business, 'id'> }>).map(
        (resource) => readResource(resource),
      )
    },
  })

  async function openBooks(companyId: string, path: string) {
    if (user && companyId !== user.company.id) {
      await selectCompany(companyId)
    }
    navigate(path)
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Businesses</h1>
          <p className="hint">Each business keeps its own chart of accounts and journals.</p>
        </div>
        {user?.role === 'owner' && (
          <Link className="primary button-link" to="/businesses/new">
            Add business
          </Link>
        )}
      </header>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Description</th>
              <th>Currency</th>
              <th>Role</th>
              <th>Accounts</th>
              <th>Journals</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {businesses.data?.map((business) => (
              <tr key={business.id}>
                <td>
                  {business.name}
                  {business.id === user?.company.id && <span className="status">Current</span>}
                </td>
                <td>{business.description || 'No description'}</td>
                <td className="num">{business.currency}</td>
                <td className="type">{business.role}</td>
                <td className="num">{business.accounts_count}</td>
                <td className="num">{business.journals_count}</td>
                <td>
                  <div className="actions">
                    {business.role === 'owner' && (
                      <Link className="button-link" to={`/businesses/${business.id}/edit`}>
                        Edit
                      </Link>
                    )}
                    <button type="button" onClick={() => void openBooks(business.id, '/accounts')}>
                      Chart of accounts
                    </button>
                    <button type="button" onClick={() => void openBooks(business.id, '/journals')}>
                      Journals
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {businesses.isLoading && <p className="hint">Loading businesses…</p>}
      </div>
    </div>
  )
}
