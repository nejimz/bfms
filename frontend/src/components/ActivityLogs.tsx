import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api'
import { readResource, type ActivityLog } from '../types'

export type ActivityModuleName =
  | 'company'
  | 'business'
  | 'classification'
  | 'category'
  | 'location'
  | 'item'
  | 'inventory'
  | 'user'

type ActivityLogPage = {
  rows: ActivityLog[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

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

function pageNumbers(current: number, last: number): number[] {
  const start = Math.max(1, Math.min(current - 2, last - 4))
  const end = Math.min(last, start + 4)

  return Array.from({ length: end - start + 1 }, (_, index) => start + index)
}

export function ActivityLogs({ module }: { module: ActivityModuleName }) {
  const [page, setPage] = useState(1)
  const logs = useQuery({
    queryKey: ['activity-logs', module, page],
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<ActivityLogPage> => {
      const response = await api.get('/api/v1/activity-logs', { params: { module, page } })

      return {
        rows: (response.data.data as Array<{ id: string; attributes: Omit<ActivityLog, 'id'> }>).map((resource) =>
          readResource(resource),
        ),
        meta: response.data.meta,
      }
    },
  })

  const rows = logs.data?.rows ?? []
  const lastPage = logs.data?.meta.last_page ?? 1
  const currentPage = logs.data?.meta.current_page ?? page

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
      {lastPage > 1 && (
        <nav className="entry-pager" aria-label="Log pages">
          <button type="button" onClick={() => setPage(currentPage - 1)} disabled={currentPage <= 1}>
            Previous
          </button>
          {pageNumbers(currentPage, lastPage).map((number) => (
            <button
              key={number}
              type="button"
              aria-current={number === currentPage ? 'page' : undefined}
              onClick={() => setPage(number)}
            >
              {number}
            </button>
          ))}
          <button type="button" onClick={() => setPage(currentPage + 1)} disabled={currentPage >= lastPage}>
            Next
          </button>
        </nav>
      )}
    </section>
  )
}
