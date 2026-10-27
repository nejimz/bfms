import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Fragment, useState } from 'react'
import { api } from '../api'
import { OrganizationHeading } from '../organization'
import { formatDecimal, toMinor } from '../money'
import type {
  YearlySummaryBusiness,
  YearlySummaryClassification,
  YearlySummaryDays,
  YearlySummaryRecords,
  YearlySummaryReport,
} from '../types'

function clampYear(year: number): number {
  return Math.min(9999, Math.max(1900, year))
}

function businessLabel(count: number): string {
  if (count === 0) {
    return 'No businesses'
  }

  return count === 1 ? '1 business' : `${count} businesses`
}

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '·'
}

function isZeroAmount(value: string): boolean {
  const negative = value.startsWith('-')

  return toMinor(negative ? value.slice(1) : value) === 0
}

function amountClass(value: string): string {
  return isZeroAmount(value) ? 'num is-zero' : 'num'
}

function signedMinor(value: string): number {
  const negative = value.startsWith('-')
  const minor = toMinor(negative ? value.slice(1) : value) ?? 0

  return negative ? -minor : minor
}

function sumDecimals(values: string[]): string {
  const minor = values.reduce((total, value) => total + signedMinor(value), 0)
  const negative = minor < 0
  const absolute = Math.abs(minor)

  return `${negative ? '-' : ''}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
}

const figures = [
  { key: 'sales', code: '4000', label: 'Sales' },
  { key: 'operation_expense', code: '5000', label: 'Operation Expenses' },
  { key: 'purchase_expense', code: '5100', label: 'Purchase Expenses' },
] as const

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

export function YearlySummaryPage() {
  const today = new Date()
  const [year, setYear] = useState(today.getFullYear())

  const report = useQuery({
    queryKey: ['yearly-summary', year],
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const response = await api.get<YearlySummaryReport>('/api/v1/reports/yearly-summary', {
        params: { year },
      })

      return response.data
    },
  })

  const groups = report.data?.data ?? []
  const years = Array.from(new Set([year, ...(report.data?.meta.years ?? [])])).sort((left, right) => left - right)

  return (
    <div className="page year-page">
      <header className="page-header">
        <div>
          <OrganizationHeading />
          <p className="eyebrow">{year}</p>
          <h1>Yearly Summary</h1>
          <p className="hint">
            Posted sales, operation expenses, and purchase expenses for each business. Open a day to see its records.
            Drafts stay out of the money.
          </p>
        </div>
        <div className="year-switch">
          <button type="button" onClick={() => setYear((current) => clampYear(current - 1))} disabled={year <= 1900}>
            Previous
          </button>
          <select aria-label="Year" value={year} onChange={(event) => setYear(clampYear(Number(event.target.value)))}>
            {years.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
          <button type="button" onClick={() => setYear((current) => clampYear(current + 1))} disabled={year >= 9999}>
            Next
          </button>
        </div>
      </header>

      {report.isLoading && <p className="hint">Opening the yearly summary…</p>}
      {report.isError && <p className="hint">The yearly summary could not be opened.</p>}
      {report.isSuccess && groups.length === 0 && <p className="hint">No classifications yet.</p>}

      {groups.length > 0 && (
        <div className="year-categories">
          {groups.map((group) => (
            <ClassificationAccordion key={group.id ?? 'none'} classification={group} year={year} />
          ))}
        </div>
      )}
    </div>
  )
}

function ClassificationAccordion({ classification, year }: { classification: YearlySummaryClassification; year: number }) {
  return (
    <details className="year-category">
      <summary>
        <span className="category-mark" aria-hidden="true">
          {initial(classification.name)}
        </span>
        <span className="year-category-name">{classification.name}</span>
        <span className="year-summary-aside">
          <span className="category-count">{businessLabel(classification.businesses.length)}</span>
        </span>
      </summary>
      {classification.businesses.length === 0 ? (
        <p className="hint year-empty">No businesses in this classification.</p>
      ) : (
        <div className="year-businesses">
          {classification.businesses.map((business) => (
            <BusinessAccordion key={business.id} business={business} year={year} />
          ))}
        </div>
      )}
    </details>
  )
}

function BusinessAccordion({ business, year }: { business: YearlySummaryBusiness; year: number }) {
  const [month, setMonth] = useState<number | null>(null)

  function toggleMonth(next: number) {
    setMonth((current) => (current === next ? null : next))
  }

  return (
    <details className="year-business">
      <summary>
        <span className="year-business-name">{business.name}</span>
        <span className="year-summary-aside">
          <span className="year-business-totals">
            {figures.map((figure) => (
              <span key={figure.key} className={`year-figure ${figure.key}`}>
                <span className="year-figure-label">{figure.label}</span>
                <span className={amountClass(business[figure.key])}>{formatDecimal(business[figure.key])}</span>
              </span>
            ))}
            <span className="year-figure">
              <span className="year-figure-label">Currency</span>
              <span className="account-code">{business.currency}</span>
            </span>
          </span>
        </span>
      </summary>
      <div className="year-months">
        <table>
          <thead>
            <tr>
              <th>Month</th>
              {figures.map((figure) => (
                <th key={figure.key} className={`amount ${figure.key}`}>
                  {figure.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {business.months.map((row) => {
              const open = month === row.month

              return (
                <Fragment key={row.month}>
                  <tr className={open ? 'year-month-open' : undefined}>
                    <td>
                      <button
                        type="button"
                        className="month-link"
                        aria-expanded={open}
                        onClick={() => toggleMonth(row.month)}
                      >
                        {monthNames[row.month - 1]}
                      </button>
                    </td>
                    {figures.map((figure) => (
                      <td key={figure.key} className={amountClass(row[figure.key])}>
                        {formatDecimal(row[figure.key])}
                      </td>
                    ))}
                  </tr>
                  {open && (
                    <tr className="year-days-row">
                      <td colSpan={4}>
                        <DailyMonth businessId={business.id} year={year} month={row.month} />
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
          <tfoot>
            <tr>
              <td className="year-total-label">Year</td>
              {figures.map((figure) => (
                <td key={figure.key} className={amountClass(business[figure.key])}>
                  {formatDecimal(business[figure.key])}
                </td>
              ))}
            </tr>
          </tfoot>
        </table>
      </div>
    </details>
  )
}

function dayLabel(year: number, month: number, day: number): string {
  return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'long',
  })
}

function DailyMonth({ businessId, year, month }: { businessId: string; year: number; month: number }) {
  const [day, setDay] = useState<number | null>(null)

  function toggleDay(next: number) {
    setDay((current) => (current === next ? null : next))
  }

  const report = useQuery({
    queryKey: ['yearly-summary-days', businessId, year, month],
    queryFn: async () => {
      const response = await api.get<YearlySummaryDays>('/api/v1/reports/yearly-summary/days', {
        params: { business: businessId, year, month },
      })

      return response.data.data
    },
  })

  const days = report.data

  if (report.isLoading) {
    return <p className="hint year-days-status">Opening {monthNames[month - 1]}…</p>
  }

  if (report.isError || !days) {
    return <p className="hint year-days-status">The days could not be opened.</p>
  }

  return (
    <div className="year-days">
      <table>
        <thead>
          <tr>
            <th>Day</th>
            {figures.map((figure) => (
              <th key={figure.key} className={`amount ${figure.key}`}>
                {figure.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {days.days.map((row) => {
            const open = day === row.day

            return (
              <Fragment key={row.day}>
                <tr className={open ? 'year-month-open' : undefined}>
                  <td>
                    <button
                      type="button"
                      className="month-link"
                      aria-expanded={open}
                      onClick={() => toggleDay(row.day)}
                    >
                      {dayLabel(year, month, row.day)}
                    </button>
                  </td>
                  {figures.map((figure) => (
                    <td key={figure.key} className={amountClass(row[figure.key])}>
                      {formatDecimal(row[figure.key])}
                    </td>
                  ))}
                </tr>
                {open && (
                  <tr className="year-days-row">
                    <td colSpan={4}>
                      <DailyRecords businessId={businessId} year={year} month={month} day={row.day} />
                    </td>
                  </tr>
                )}
              </Fragment>
            )
          })}
        </tbody>
        <tfoot>
          <tr>
            <td className="year-total-label">Month</td>
            {figures.map((figure) => (
              <td key={figure.key} className={amountClass(days[figure.key])}>
                {formatDecimal(days[figure.key])}
              </td>
            ))}
          </tr>
        </tfoot>
      </table>
    </div>
  )
}

function DailyRecords({
  businessId,
  year,
  month,
  day,
}: {
  businessId: string
  year: number
  month: number
  day: number
}) {
  const report = useQuery({
    queryKey: ['yearly-summary-records', businessId, year, month, day],
    queryFn: async () => {
      const response = await api.get<YearlySummaryRecords>('/api/v1/reports/yearly-summary/records', {
        params: { business: businessId, year, month, day },
      })

      return response.data.data
    },
  })

  const records = report.data

  if (report.isLoading) {
    return <p className="hint year-days-status">Opening {dayLabel(year, month, day)}…</p>
  }

  if (report.isError || !records) {
    return <p className="hint year-days-status">The records could not be opened.</p>
  }

  return (
    <div className="year-records">
      {figures.map((figure) => {
        const entries = records.entries.filter((entry) => entry.account_code === figure.code)
        const total = sumDecimals(entries.map((entry) => entry.amount))

        return (
          <details key={figure.key} className={`year-record-group ${figure.key}${isZeroAmount(total) ? ' is-zero' : ''}`}>
            <summary>
              <span className="year-record-group-name">{figure.label}</span>
              <span className={amountClass(total)}>{formatDecimal(total)}</span>
            </summary>
            {entries.length === 0 ? (
              <p className="hint year-days-status">No posted records.</p>
            ) : (
              entries.map((entry) => (
                <article key={entry.id} className="year-record">
                  <div className="year-record-main">
                    <p className="year-record-account">
                      <span className="account-code">{entry.account_code}</span>
                      <strong>{entry.account_name}</strong>
                    </p>
                    {(entry.particulars || entry.reference) && (
                      <p className="year-record-particulars">
                        {entry.particulars}
                        {entry.reference && <span className="account-code">{entry.reference}</span>}
                      </p>
                    )}
                  </div>
                  <dl className="year-record-money">
                    <div>
                      <dt>Amount</dt>
                      <dd className={amountClass(entry.amount)}>{formatDecimal(entry.amount)}</dd>
                    </div>
                    <div>
                      <dt>Cash</dt>
                      <dd className={amountClass(entry.cash)}>{formatDecimal(entry.cash)}</dd>
                    </div>
                    <div>
                      <dt>Credit</dt>
                      <dd className={amountClass(entry.credit)}>{formatDecimal(entry.credit)}</dd>
                    </div>
                  </dl>
                  {entry.lines.length > 0 && (
                    <div className="year-record-lines">
                      <table>
                        <thead>
                          <tr>
                            <th>Item</th>
                            <th className="amount">Quantity</th>
                            <th className="amount">Amount</th>
                          </tr>
                        </thead>
                        <tbody>
                          {entry.lines.map((line) => (
                            <tr key={`${entry.id}-${line.name}-${line.quantity}-${line.amount}`}>
                              <td>{line.name}</td>
                              <td className="num">{line.quantity}</td>
                              <td className={amountClass(line.amount)}>{formatDecimal(line.amount)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </article>
              ))
            )}
          </details>
        )
      })}
    </div>
  )
}
