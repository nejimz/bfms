import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import { OrganizationHeading } from '../organization'
import { formatDecimal } from '../money'
import type { TrialBalance } from '../types'

function monthBounds(): { from: string; to: string } {
  const now = new Date()
  const from = new Date(now.getFullYear(), now.getMonth(), 1)
  const to = new Date(now.getFullYear(), now.getMonth() + 1, 0)
  const format = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

  return { from: format(from), to: format(to) }
}

export function TrialBalancePage() {
  const { user } = useAuth()
  const companyId = user?.company.id
  const initial = monthBounds()
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [applied, setApplied] = useState(initial)

  const report = useQuery({
    queryKey: ['trial-balance', companyId, applied.from, applied.to],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get<TrialBalance>('/api/v1/reports/trial-balance', {
        params: applied,
      })
      return response.data
    },
  })

  const balanced = report.data?.meta.difference === '0.00'

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <OrganizationHeading />
          <p className="eyebrow">
            {user?.company.name} · {report.data?.meta.currency ?? user?.company.currency}
          </p>
          <h1>Trial balance</h1>
        </div>
      </header>
      <form
        className="panel inline-form"
        onSubmit={(event) => {
          event.preventDefault()
          setApplied({ from, to })
        }}
      >
        <label>
          From
          <input type="date" value={from} onChange={(event) => setFrom(event.target.value)} required />
        </label>
        <label>
          To
          <input type="date" value={to} onChange={(event) => setTo(event.target.value)} required />
        </label>
        <button type="submit" className="primary">
          Run report
        </button>
      </form>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Account</th>
              <th>Type</th>
              <th className="amount">Debit</th>
              <th className="amount">Credit</th>
            </tr>
          </thead>
          <tbody>
            {report.data?.data.map((row) => (
              <tr key={row.account_id}>
                <td className="num">{row.code}</td>
                <td>{row.name}</td>
                <td className="type">{row.type}</td>
                <td className="num">{formatDecimal(row.debit)}</td>
                <td className="num">{formatDecimal(row.credit)}</td>
              </tr>
            ))}
          </tbody>
          {report.data && (
            <tfoot>
              <tr>
                <td colSpan={3}>{balanced ? 'In balance' : 'Out of balance'}</td>
                <td className="num">{formatDecimal(report.data.meta.debit_total)}</td>
                <td className="num">{formatDecimal(report.data.meta.credit_total)}</td>
              </tr>
            </tfoot>
          )}
        </table>
        {report.data?.data.length === 0 && <p className="hint">No posted activity in this range.</p>}
      </div>
    </div>
  )
}
