import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import type { Business } from '../types'
import { readResource } from '../types'

export function BusinessesPage() {
  const { user } = useAuth()
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

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Businesses</h1>
          <p className="hint">Each business keeps its own chart of accounts and journals.</p>
        </div>
        {user?.role === 'admin' && (
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
              <th className="center">Classification</th>
              <th className="center">Location</th>
              <th className="center">Currency</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {businesses.data?.map((business) => (
              <tr key={business.id}>
                <td>
                  <span className="business-name">
                    {business.logo_url && <img className="business-logo" src={business.logo_url} alt="" />}
                    <span>
                      {business.name}
                      {business.id === user?.company.id && <span className="status">Current</span>}
                    </span>
                  </span>
                </td>
                <td>{business.description || 'No description'}</td>
                <td className="center">{business.classification_name || 'No classification'}</td>
                <td className="center">{business.location_name || 'No location'}</td>
                <td className="num center">{business.currency}</td>
                <td>
                  <div className="actions">
                    <Link className="button-link" to={`/businesses/${business.id}/edit`}>
                      Edit
                    </Link>
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
