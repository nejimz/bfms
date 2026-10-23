import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { formatDecimal, toMinor } from '../money'
import type { ChartAccountColumnReport, ChartAccountColumnRow } from '../types'

const monthNames = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
]

function formatEntryDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number)
  if (!year || !month || !day) {
    return iso
  }

  return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

function decimalFromMinor(minor: number): string {
  const negative = minor < 0
  const absolute = Math.abs(minor)

  return `${negative ? '-' : ''}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
}

function totalFor(rows: ChartAccountColumnRow[], accountId: string): string {
  const minor = rows.reduce((sum, row) => {
    if (row.account_id !== accountId) {
      return sum
    }

    return sum + (toMinor(row.amount) ?? 0)
  }, 0)

  return decimalFromMinor(minor)
}

export function ChartAccountColumnsPage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const companyId = user?.company.id
  const today = new Date()
  const [year, setYear] = useState(today.getFullYear())
  const [month, setMonth] = useState(today.getMonth() + 1)
  const [sort, setSort] = useState<'asc' | 'desc'>('asc')

  const report = useQuery({
    queryKey: ['chart-account-columns', companyId, year, month, sort],
    enabled: Boolean(companyId),
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const response = await api.get<ChartAccountColumnReport>('/api/v1/reports/chart-account-columns', {
        params: { year, month, sort },
      })

      return response.data
    },
  })

  const rows = report.data?.data ?? []
  const columns = report.data?.meta.columns ?? []
  const years = report.data?.meta.years ?? []
  const yearOptions = Array.from(new Set([year, ...years])).sort((left, right) => left - right)

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">
            {user?.company.name} · {report.data?.meta.currency ?? user?.company.currency}
          </p>
          <h1>Account columns</h1>
          <p className="hint">One row per chart account entry. Each amount sits under its account.</p>
        </div>
      </header>

      <div className="panel inline-form column-filters">
        <label>
          Year
          <select value={year} onChange={(event) => setYear(Number(event.target.value))}>
            {yearOptions.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </label>
        <label>
          Month
          <select value={month} onChange={(event) => setMonth(Number(event.target.value))}>
            {monthNames.map((name, index) => (
              <option key={name} value={index + 1}>
                {name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {report.isLoading && <p className="hint">Opening entries…</p>}
      {report.isError && <p className="hint">The account columns could not be opened.</p>}
      {!report.isLoading && !report.isError && report.isFetched && rows.length === 0 && (
        <p className="hint">No entries in this month.</p>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th aria-sort={sort === 'asc' ? 'ascending' : 'descending'}>
                  <button
                    type="button"
                    className="sort-button"
                    onClick={() => setSort((current) => (current === 'asc' ? 'desc' : 'asc'))}
                  >
                    Date
                    <span aria-hidden="true">{sort === 'asc' ? '↑' : '↓'}</span>
                  </button>
                </th>
                <th>Particulars</th>
                {columns.map((column) => (
                  <th key={column.id} className="amount">
                    <span className="account-heading">
                      <span>{column.code}</span>
                      <span>{column.name}</span>
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr
                  key={row.id}
                  className="column-row"
                  onClick={() => navigate(`/chart-account-entries/${row.id}`)}
                >
                  <td>
                    <time dateTime={row.date}>{formatEntryDate(row.date)}</time>
                  </td>
                  <td className="particulars">
                    <Link
                      to={`/chart-account-entries/${row.id}`}
                      onClick={(event) => event.stopPropagation()}
                    >
                      {row.particulars}
                    </Link>
                    <span className={`status ${row.status}`}>{row.status}</span>
                  </td>
                  {columns.map((column) => (
                    <td key={column.id} className="num">
                      {row.account_id === column.id ? formatDecimal(row.amount) : ''}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td>Total</td>
                <td />
                {columns.map((column) => (
                  <td key={column.id} className="num">
                    {formatDecimal(totalFor(rows, column.id))}
                  </td>
                ))}
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  )
}
