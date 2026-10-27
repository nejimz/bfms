import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { BusinessSelect } from '../components/BusinessSelect'
import { canWriteReport, readResource, type AccountColumnReport } from '../types'

export function AccountColumnReportsPage() {
  const { user } = useAuth()
  const companyId = user?.company?.id
  const reports = useQuery({
    queryKey: ['account-column-reports', companyId],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get('/api/v1/account-column-reports')
      return (response.data.data as Array<{ id: string; attributes: Omit<AccountColumnReport, 'id'> }>).map(
        (resource) => readResource(resource),
      )
    },
  })

  const rows = reports.data ?? []
  const writable = canWriteReport(user?.role)

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">
            {user?.company?.name} · {user?.company?.currency}
          </p>
          <h1>Account columns</h1>
          <p className="hint">Each report keeps a name and the chart accounts shown as columns.</p>
        </div>
        {writable && (
          <Link className="primary button-link" to="/chart-account-columns/new">
            New report
          </Link>
        )}
      </header>

      <div className="panel inline-form column-filters">
        <BusinessSelect />
      </div>

      {reports.isLoading && <p className="hint">Opening reports…</p>}
      {reports.isError && <p className="hint">The reports could not be opened.</p>}
      {reports.isSuccess && rows.length === 0 && (
        <p className="hint">No reports yet. Name one and choose the accounts to show.</p>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Accounts</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {rows.map((report) => (
                <tr key={report.id}>
                  <td>{report.name}</td>
                  <td>{report.accounts.map((account) => `${account.code} ${account.name}`).join(', ')}</td>
                  <td>
                    <div className="actions">
                      <Link className="button-link" to={`/chart-account-columns/${report.id}`}>
                        Open
                      </Link>
                      {writable && (
                        <Link className="button-link" to={`/chart-account-columns/${report.id}/edit`}>
                          Edit
                        </Link>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
