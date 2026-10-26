import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { OrganizationHeading } from '../organization'
import { BusinessSelect } from '../components/BusinessSelect'
import { formatDecimal, toMinor } from '../money'
import {
  canWriteReport,
  readResource,
  type AccountColumnReport,
  type ChartAccountColumn,
  type ChartAccountColumnReport,
  type ChartAccountColumnRow,
} from '../types'

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

function sumsFor(rows: ChartAccountColumnRow[], accountId: string): { total: string; cash: string; credit: string } {
  const sums = rows.reduce(
    (sum, row) => {
      if (row.account_id !== accountId) {
        return sum
      }

      return {
        total: sum.total + (toMinor(row.amount) ?? 0),
        cash: sum.cash + (toMinor(row.cash) ?? 0),
        credit: sum.credit + (toMinor(row.credit) ?? 0),
      }
    },
    { total: 0, cash: 0, credit: 0 },
  )

  return {
    total: decimalFromMinor(sums.total),
    cash: decimalFromMinor(sums.cash),
    credit: decimalFromMinor(sums.credit),
  }
}

function rowsForAccount(rows: ChartAccountColumnRow[], accountId: string): ChartAccountColumnRow[] {
  return rows.filter((row) => row.account_id === accountId)
}

export function ChartAccountColumnsPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const companyId = user?.company.id
  const today = new Date()
  const [year, setYear] = useState(today.getFullYear())
  const [sort, setSort] = useState<'asc' | 'desc'>('asc')

  const definition = useQuery({
    queryKey: ['account-column-report', companyId, id],
    enabled: Boolean(companyId && id),
    queryFn: async () => {
      const response = await api.get(`/api/v1/account-column-reports/${id}`)
      return readResource<Omit<AccountColumnReport, 'id'>>(response.data.data)
    },
  })

  const report = useQuery({
    queryKey: ['chart-account-columns', companyId, id, year, sort],
    enabled: Boolean(companyId && id),
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const response = await api.get<ChartAccountColumnReport>('/api/v1/reports/chart-account-columns', {
        params: { report: id, year, sort },
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
          <OrganizationHeading />
          <p className="eyebrow">
            {user?.company.name} · {report.data?.meta.currency ?? user?.company.currency}
          </p>
          <h1>{definition.data?.name ?? 'Account columns'}</h1>
          <p className="hint">Each account lists its own date, particulars, reference, cash, and credit.</p>
        </div>
        <div className="actions">
          <Link className="button-link" to="/chart-account-columns">
            All reports
          </Link>
          {canWriteReport(user?.role) && id && (
            <Link className="button-link" to={`/chart-account-columns/${id}/edit`}>
              Edit report
            </Link>
          )}
        </div>
      </header>

      <div className="panel inline-form column-filters">
        <BusinessSelect />
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
      </div>

      {report.isLoading && <p className="hint">Opening entries…</p>}
      {(report.isError || definition.isError) && (
        <p className="hint">The account columns could not be opened.</p>
      )}
      {!report.isLoading && !report.isError && report.isFetched && columns.length === 0 && (
        <p className="hint">No accounts on this report.</p>
      )}

      {!report.isError && columns.length > 0 && (
        <div className="account-sections">
          {columns.map((column) => (
            <AccountSection
              key={column.id}
              column={column}
              rows={rowsForAccount(rows, column.id)}
              sums={sumsFor(rows, column.id)}
              sort={sort}
              onSort={() => setSort((current) => (current === 'asc' ? 'desc' : 'asc'))}
              onOpen={(entryId) => navigate(`/chart-account-entries/${entryId}`)}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function AccountSection({
  column,
  rows,
  sums,
  sort,
  onSort,
  onOpen,
}: {
  column: ChartAccountColumn
  rows: ChartAccountColumnRow[]
  sums: { total: string; cash: string; credit: string }
  sort: 'asc' | 'desc'
  onSort: () => void
  onOpen: (entryId: string) => void
}) {
  const headingId = `account-${column.id}`

  return (
    <section className="account-section" aria-labelledby={headingId}>
      <header className="account-section-head">
        <h2 id={headingId}>
          <span className="account-code">{column.code}</span>
          {' · '}
          {column.name}
        </h2>
        <p className="account-section-total">
          <span>Total {formatDecimal(sums.total)}</span>
          <span>Cash {formatDecimal(sums.cash)}</span>
          <span>Credit {formatDecimal(sums.credit)}</span>
        </p>
      </header>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th aria-sort={sort === 'asc' ? 'ascending' : 'descending'}>
                <button type="button" className="sort-button" onClick={onSort}>
                  Date
                  <span aria-hidden="true">{sort === 'asc' ? '↑' : '↓'}</span>
                </button>
              </th>
              <th>Particulars</th>
              <th>Reference</th>
              <th className="amount">Total</th>
              <th className="amount">Cash</th>
              <th className="amount">Credit</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={6} className="hint">
                  No entries in this year.
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <tr key={row.id} className="column-row" onClick={() => onOpen(row.id)}>
                <td>
                  <time dateTime={row.date}>{formatEntryDate(row.date)}</time>
                </td>
                <td className="particulars">
                  <Link to={`/chart-account-entries/${row.id}`} onClick={(event) => event.stopPropagation()}>
                    {row.particulars}
                  </Link>
                  <span className={`status ${row.status}`}>{row.status}</span>
                </td>
                <td>{row.reference}</td>
                <td className="num">{formatDecimal(row.amount)}</td>
                <td className="num">{formatDecimal(row.cash)}</td>
                <td className="num">{formatDecimal(row.credit)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
