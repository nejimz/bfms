import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../api'
import { formatMinor, toMinor } from '../money'
import type { IncomeExpenseChart, IncomeExpenseChartReport, IncomeExpensePoint } from '../types'

const series = [
  { key: 'sales', label: 'Sales', color: '#0d6b4f' },
  { key: 'operation_expense', label: 'Operation Expenses', color: '#c47b2b' },
  { key: 'purchase_expense', label: 'Purchase Expenses', color: '#8d4a32' },
] as const

type SeriesKey = (typeof series)[number]['key']

const chartBox = { width: 880, height: 248, left: 72, right: 12, top: 12, bottom: 32 }

function signedMinor(value: string): number {
  const negative = value.startsWith('-')
  const minor = toMinor(negative ? value.slice(1) : value) ?? 0

  return negative ? -minor : minor
}

function axisAmount(minor: number): string {
  const negative = minor < 0
  const absolute = Math.abs(minor)
  const whole = Math.floor(absolute / 100)
  const fraction = absolute % 100
  const body =
    fraction === 0
      ? whole.toLocaleString('en-GB')
      : `${whole.toLocaleString('en-GB')}.${String(fraction).padStart(2, '0')}`

  return `${negative ? '-' : ''}${body}`
}

function monthLabel(year: number, month: number): string {
  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', {
    month: 'short',
    year: 'numeric',
  })
}

function shortMonth(year: number, month: number): string {
  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', { month: 'short' })
}

function totalMinor(months: IncomeExpensePoint[], key: SeriesKey): number {
  return months.reduce((sum, month) => sum + signedMinor(month[key]), 0)
}

function chartIsEmpty(chart: IncomeExpenseChart): boolean {
  return chart.months.every((month) => series.every((item) => signedMinor(month[item.key]) === 0))
}

function groupCharts(rows: IncomeExpenseChart[]): Array<{ category: string; charts: IncomeExpenseChart[] }> {
  const groups: Array<{ category: string; charts: IncomeExpenseChart[] }> = []

  for (const chart of rows) {
    const category = chart.category_name ?? 'No category'
    const current = groups[groups.length - 1]

    if (current?.category === category) {
      current.charts.push(chart)
    } else {
      groups.push({ category, charts: [chart] })
    }
  }

  return groups
}

export function IncomeExpensePage() {
  const [group, setGroup] = useState<'business' | 'category'>('business')

  const report = useQuery({
    queryKey: ['income-expense', group],
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const response = await api.get<IncomeExpenseChartReport>('/api/v1/reports/income-expense', {
        params: { group },
      })

      return response.data
    },
  })

  const rows = report.data?.data ?? []
  const months = report.data?.meta.months ?? []
  const groups = group === 'business' ? groupCharts(rows) : [{ category: '', charts: rows }]
  const first = months[0]
  const last = months[months.length - 1]
  const range = first && last ? `${monthLabel(first.year, first.month)} – ${monthLabel(last.year, last.month)}` : ''

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">{range}</p>
          <h1>Income & Expenses</h1>
          <p className="hint">
            Posted sales, operation expenses, and purchase expenses for the last twelve months. Each chart uses one
            currency.
          </p>
        </div>
      </header>

      <div className="panel dashboard-filters">
        <label>
          Charts
          <select value={group} onChange={(event) => setGroup(event.target.value === 'category' ? 'category' : 'business')}>
            <option value="business">Each business</option>
            <option value="category">Each category</option>
          </select>
        </label>
        <ul className="chart-legend">
          {series.map((item) => (
            <li key={item.key}>
              <span className="chart-swatch" style={{ background: item.color }} />
              {item.label}
            </li>
          ))}
        </ul>
      </div>

      {report.isLoading && <p className="hint">Opening the charts…</p>}
      {report.isError && <p className="hint">The charts could not be opened.</p>}
      {report.isSuccess && rows.length === 0 && <p className="hint">No businesses yet.</p>}

      {groups.some((item) => item.charts.length > 0) && (
        <div className="dashboard-groups">
          {groups.map((item) => (
            <section key={item.category || 'charts'} className="dashboard-group" aria-label={item.category || 'Charts'}>
              {group === 'business' && <h2 className="dashboard-category">{item.category}</h2>}
              {item.charts.map((chart) => (
                <ChartCard key={chart.id} chart={chart} labels={months} grouped={group === 'category'} />
              ))}
            </section>
          ))}
        </div>
      )}
    </div>
  )
}

function ChartCard({
  chart,
  labels,
  grouped,
}: {
  chart: IncomeExpenseChart
  labels: IncomeExpenseChartReport['meta']['months']
  grouped: boolean
}) {
  const empty = chartIsEmpty(chart)
  const businessLabel = `${chart.business_count} ${chart.business_count === 1 ? 'business' : 'businesses'}`

  return (
    <section className="dashboard-business" aria-label={`${chart.name} ${chart.currency}`}>
      <div className="account-section-head">
        <h3>
          {chart.name} <span className="account-code">{chart.currency}</span>
        </h3>
        <p className="account-section-total">
          {grouped && <span>{businessLabel}</span>}
          {series.map((item) => (
            <span key={item.key}>
              {item.label} {formatMinor(totalMinor(chart.months, item.key))}
            </span>
          ))}
        </p>
      </div>
      {empty && <p className="hint">No posted sales or expenses in these twelve months.</p>}
      <div className="panel chart-panel">
        <div className="chart-scroll">
          <MonthlyBars chart={chart} labels={labels} />
        </div>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Month</th>
              {series.map((item) => (
                <th key={item.key} className="amount">
                  {item.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {chart.months.map((month, index) => {
              const label = labels[index]

              return (
                <tr key={month.key}>
                  <td>{label ? monthLabel(label.year, label.month) : month.key}</td>
                  {series.map((item) => (
                    <td key={item.key} className="num">
                      {formatMinor(signedMinor(month[item.key]))}
                    </td>
                  ))}
                </tr>
              )
            })}
          </tbody>
          <tfoot>
            <tr>
              <td>Twelve months</td>
              {series.map((item) => (
                <td key={item.key} className="num">
                  {formatMinor(totalMinor(chart.months, item.key))}
                </td>
              ))}
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  )
}

function MonthlyBars({
  chart,
  labels,
}: {
  chart: IncomeExpenseChart
  labels: IncomeExpenseChartReport['meta']['months']
}) {
  const values = chart.months.flatMap((month) => series.map((item) => signedMinor(month[item.key])))
  const min = Math.min(0, ...values)
  const max = Math.max(0, ...values)
  const span = max - min
  const { width, height, left, right, top, bottom } = chartBox
  const plotW = width - left - right
  const plotH = height - top - bottom
  const groupW = plotW / Math.max(chart.months.length, 1)
  const barW = 12
  const barGap = 3
  const clusterW = series.length * barW + (series.length - 1) * barGap

  function yFor(value: number): number {
    if (span === 0) {
      return top + plotH
    }

    return top + ((max - value) / span) * plotH
  }

  const ticks = span === 0 ? [0] : [...new Set([max, Math.round((min + max) / 2), min])]
  const zeroY = yFor(0)

  return (
    <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`${chart.name} monthly sales and expenses`}>
      {ticks.map((tick) => (
        <g key={tick}>
          <line x1={left} x2={width - right} y1={yFor(tick)} y2={yFor(tick)} stroke="var(--line)" />
          <text x={left - 8} y={yFor(tick) + 4} textAnchor="end" fontSize="11" fill="var(--muted)">
            {axisAmount(tick)}
          </text>
        </g>
      ))}
      <line x1={left} x2={width - right} y1={zeroY} y2={zeroY} stroke="var(--ink)" strokeOpacity="0.35" />
      {chart.months.map((month, index) => {
        const label = labels[index]
        const x0 = left + index * groupW + (groupW - clusterW) / 2

        return (
          <g key={month.key}>
            {series.map((item, seriesIndex) => {
              const value = signedMinor(month[item.key])
              const y = yFor(value)
              const barTop = Math.min(y, zeroY)
              const barHeight = Math.abs(y - zeroY)

              return (
                <rect
                  key={item.key}
                  x={x0 + seriesIndex * (barW + barGap)}
                  y={barTop}
                  width={barW}
                  height={barHeight}
                  fill={item.color}
                  rx="1"
                >
                  <title>
                    {label ? monthLabel(label.year, label.month) : month.key} {item.label}{' '}
                    {formatMinor(value)}
                  </title>
                </rect>
              )
            })}
            <text
              x={left + index * groupW + groupW / 2}
              y={height - 10}
              textAnchor="middle"
              fontSize="11"
              fill="var(--muted)"
            >
              {label ? shortMonth(label.year, label.month) : month.key}
            </text>
          </g>
        )
      })}
    </svg>
  )
}
