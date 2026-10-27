import { useQuery } from '@tanstack/react-query'
import { api } from '../api'
import { readResource, type ActivityLog } from '../types'

export type ActivityModuleName = 'company' | 'business' | 'classification' | 'category' | 'location' | 'user'

const actionLabel: Record<ActivityLog['action'], string> = {
  added: 'Added',
  changed: 'Changed',
  deleted: 'Deleted',
}

function formatWhen(value: string): string {
  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(date)
}

export function ActivityLogs({ module }: { module: ActivityModuleName }) {
  const logs = useQuery({
    queryKey: ['activity-logs', module],
    queryFn: async () => {
      const response = await api.get('/api/v1/activity-logs', { params: { module } })

      return (response.data.data as Array<{ id: string; attributes: Omit<ActivityLog, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  const rows = logs.data ?? []

  return (
    <section className="activity-logs" aria-labelledby={`${module}-logs`}>
      <h2 id={`${module}-logs`}>Logs</h2>
      {logs.isLoading && <p className="hint">Loading logs…</p>}
      {logs.isError && <p className="hint">The logs could not be opened.</p>}
      {logs.isSuccess && rows.length === 0 && <p className="hint">No logs yet.</p>}
      {rows.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>When</th>
                <th>Who</th>
                <th>Record</th>
                <th>Action</th>
                <th>Changes</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((log) => (
                <tr key={log.id}>
                  <td>{formatWhen(log.created_at)}</td>
                  <td>{log.actor_name}</td>
                  <td>{log.record_name}</td>
                  <td>{actionLabel[log.action]}</td>
                  <td>
                    {log.changes.length === 0 ? (
                      '—'
                    ) : (
                      <ul className="activity-changes">
                        {log.changes.map((change) => (
                          <li key={`${change.field}-${change.from}-${change.to}`}>
                            {change.field}: {change.from} → {change.to}
                          </li>
                        ))}
                      </ul>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
