import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { OrganizationHeading } from '../organization'
import { chartAccountModule, type ChartAccountModuleKey } from '../chartAccountModules'
import { BusinessSelect } from '../components/BusinessSelect'
import { formatDecimal } from '../money'
import type { ItemActivityCell, ItemActivityGrain, ItemActivityReport, ItemActivityTotal } from '../types'

const months = [
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

function clampYear(year: number): number {
  return Math.min(9999, Math.max(1900, year))
}

function periodLabel(grain: ItemActivityGrain, year: number, month: number): string {
  if (grain === 'year') {
    return 'All years'
  }

  if (grain === 'month') {
    return String(year)
  }

  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', {
    month: 'long',
    year: 'numeric',
  })
}

function cellFor(cells: ItemActivityCell[], key: string): ItemActivityCell | undefined {
  return cells.find((cell) => cell.key === key)
}

function isQuiet(cell: ItemActivityCell | undefined): boolean {
  return cell === undefined || (cell.quantity === '0' && cell.amount === '0.00')
}

export function ItemActivityPage({ moduleKey }: { moduleKey: ChartAccountModuleKey }) {
  const module = chartAccountModule(moduleKey)
  const { user } = useAuth()
  const companyId = user?.company.id
  const today = new Date()
  const [grain, setGrain] = useState<ItemActivityGrain>('month')
  const [year, setYear] = useState(today.getFullYear())
  const [month, setMonth] = useState(today.getMonth() + 1)

  const report = useQuery({
    queryKey: ['item-activity', companyId, module.key, grain, year, month],
    enabled: Boolean(companyId),
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const response = await api.get<ItemActivityReport>('/api/v1/reports/item-activity', {
        params: {
          book: module.key,
          grain,
          ...(grain === 'year' ? {} : { year }),
          ...(grain === 'day' || grain === 'week' ? { month } : {}),
        },
      })

      return response.data
    },
  })

  const rows = report.data?.data ?? []
  const meta = report.data?.meta
  const periods = meta?.periods ?? []
  const years = Array.from(new Set([year, ...(meta?.years ?? [])])).sort((left, right) => left - right)
  const itemLabel = meta?.item_label ?? module.itemLabel
  const atStart = grain === 'month' ? year <= 1900 : year <= 1900 && month === 1
  const atEnd = grain === 'month' ? year >= 9999 : year >= 9999 && month === 12

  function shift(direction: -1 | 1) {
    if (grain === 'month') {
      setYear((current) => clampYear(current + direction))
      return
    }

    const next = new Date(year, month - 1 + direction, 1)
    setYear(clampYear(next.getFullYear()))
    setMonth(next.getMonth() + 1)
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <OrganizationHeading />
          <p className="eyebrow">
            {user?.company.currency}
            {meta ? ` · ${periodLabel(grain, year, month)}` : ''}
          </p>
          <h1>{module.title} by item</h1>
          <p className="hint">Posted quantities and amounts. Each cell is the quantity, with the amount under it.</p>
        </div>
        <div className="entry-hero-actions">
          <BusinessSelect />
          <Link className="button-link" to={module.path}>
            Entries
          </Link>
        </div>
      </header>

      <div className="panel dashboard-filters">
        <label>
          By
          <select value={grain} onChange={(event) => setGrain(event.target.value as ItemActivityGrain)}>
            <option value="day">Day</option>
            <option value="week">Week</option>
            <option value="month">Month</option>
            <option value="year">Year</option>
          </select>
        </label>
        {(grain === 'day' || grain === 'week') && (
          <label>
            Month
            <select value={month} onChange={(event) => setMonth(Number(event.target.value))}>
              {months.map((name, index) => (
                <option key={name} value={index + 1}>
                  {name}
                </option>
              ))}
            </select>
          </label>
        )}
        {grain !== 'year' && (
          <label>
            Year
            <select value={year} onChange={(event) => setYear(clampYear(Number(event.target.value)))}>
              {years.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </label>
        )}
        {grain !== 'year' && (
          <div className="period-actions">
            <button type="button" onClick={() => shift(-1)} disabled={atStart}>
              Previous
            </button>
            <button type="button" onClick={() => shift(1)} disabled={atEnd}>
              Next
            </button>
          </div>
        )}
      </div>

      {report.isLoading && <p className="hint">Opening the item report…</p>}
      {report.isError && <p className="hint">The item report could not be opened.</p>}
      {report.isSuccess && rows.length === 0 && <p className="hint">No posted items in this period.</p>}

      {rows.length > 0 && meta && (
        <div className="table-wrap item-report">
          <table>
            <thead>
              <tr>
                <th className="item-name" scope="col">
                  {itemLabel}
                </th>
                {periods.map((period) => (
                  <th key={period.key} className="amount period" scope="col">
                    {period.label}
                  </th>
                ))}
                <th className="amount total-cell" scope="col">
                  Total
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.name}>
                  <th className="item-name" scope="row">
                    {row.name}
                  </th>
                  {periods.map((period) => (
                    <td key={period.key} className="num period">
                      <ActivityCell cell={cellFor(row.periods, period.key)} />
                    </td>
                  ))}
                  <td className="num total-cell">
                    <TotalCell total={row.total} />
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <th className="item-name" scope="row">
                  Total
                </th>
                {periods.map((period) => (
                  <td key={period.key} className="num period">
                    <ActivityCell cell={cellFor(meta.totals.periods, period.key)} />
                  </td>
                ))}
                <td className="num total-cell">
                  <TotalCell total={meta.totals.total} />
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  )
}

function ActivityCell({ cell }: { cell: ItemActivityCell | undefined }) {
  if (isQuiet(cell) || cell === undefined) {
    return <span className="item-empty-cell">—</span>
  }

  return (
    <span className="item-cell">
      <span className="item-qty">{cell.quantity}</span>
      <span className="item-amount">{formatDecimal(cell.amount)}</span>
    </span>
  )
}

function TotalCell({ total }: { total: ItemActivityTotal }) {
  const entries = `${total.entries} ${total.entries === 1 ? 'entry' : 'entries'}`

  return (
    <span className="item-cell">
      <span className="item-qty">{total.quantity}</span>
      <span className="item-amount">{formatDecimal(total.amount)}</span>
      <span className="item-meta">
        {entries}
        {total.average ? ` · ${formatDecimal(total.average)} each` : ''}
      </span>
    </span>
  )
}
