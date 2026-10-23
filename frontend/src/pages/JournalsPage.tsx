import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { BusinessSelect } from '../components/BusinessSelect'
import type { Journal } from '../types'
import { readResource } from '../types'

export function JournalsPage() {
  const { user } = useAuth()
  const companyId = user?.company.id
  const journals = useQuery({
    queryKey: ['journals', companyId],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get('/api/v1/journals')
      return (
        response.data.data as Array<{ id: string; attributes: Omit<Journal, 'id' | 'lines'> }>
      ).map((resource) => readResource(resource))
    },
  })

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">{user?.company.currency}</p>
          <h1>Journals</h1>
        </div>
        <BusinessSelect />
        <Link className="primary button-link" to="/journals/new">
          New journal
        </Link>
      </header>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Reference</th>
              <th>Memo</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {journals.data?.map((journal) => (
              <tr key={journal.id}>
                <td className="num">{journal.date}</td>
                <td>
                  <Link to={`/journals/${journal.id}`}>{journal.reference || 'No reference'}</Link>
                </td>
                <td>{journal.memo}</td>
                <td>
                  <span className={`status ${journal.status}`}>{journal.status}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {journals.data?.length === 0 && <p className="hint">No journals yet.</p>}
      </div>
    </div>
  )
}
