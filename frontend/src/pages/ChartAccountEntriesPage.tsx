import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { BusinessSelect } from '../components/BusinessSelect'
import { formatDecimal } from '../money'
import type { ChartAccountEntry } from '../types'
import { readChartAccountEntries } from '../types'

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

export function ChartAccountEntriesPage() {
  const { user } = useAuth()
  const companyId = user?.company.id
  const entries = useQuery({
    queryKey: ['chart-account-entries', companyId],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get('/api/v1/chart-account-entries')
      return readChartAccountEntries(response.data)
    },
  })

  const rows = entries.data ?? []
  const drafts = rows.filter((entry) => entry.status === 'draft').length

  return (
    <div className="page entry-page">
      <header className="entry-hero">
        <div>
          <p className="eyebrow">{user?.company.currency}</p>
          <h1>Chart account entry</h1>
          <p className="entry-lede">A private register. Posted lines stay off the general ledger.</p>
        </div>
        <div className="entry-hero-actions">
          {rows.length > 0 && (
            <p className="entry-count">
              {rows.length} {rows.length === 1 ? 'entry' : 'entries'}
              {drafts > 0 ? ` · ${drafts} draft${drafts === 1 ? '' : 's'}` : ''}
            </p>
          )}
          <BusinessSelect />
          <Link className="primary button-link" to="/chart-account-entries/new">
            New entry
          </Link>
        </div>
      </header>

      <section className="entry-sheet" aria-label="Chart account entries">
        {entries.isLoading && <p className="entry-empty">Opening entries…</p>}
        {entries.isError && <p className="entry-empty">The register could not be opened.</p>}
        {entries.isSuccess && rows.length === 0 && (
          <div className="entry-empty">
            <strong>No entries yet</strong>
            <span>Record a date, an account, particulars, and an amount.</span>
          </div>
        )}
        {rows.length > 0 && (
          <>
            <div className="entry-sheet-head" aria-hidden="true">
              <span>Date</span>
              <span>Account</span>
              <span>Particulars</span>
              <span>Amount</span>
              <span>Status</span>
            </div>
            {rows.map((entry) => (
              <EntryRow key={entry.id} entry={entry} />
            ))}
          </>
        )}
      </section>
    </div>
  )
}

function EntryRow({ entry }: { entry: ChartAccountEntry }) {
  const code = entry.account?.code
  const name = entry.account?.name ?? entry.account_id

  return (
    <Link className="entry-row" to={`/chart-account-entries/${entry.id}`}>
      <time dateTime={entry.date}>{formatEntryDate(entry.date)}</time>
      <span className="entry-account">
        {code && <span className="entry-code">{code}</span>}
        <span className="entry-account-name">{name}</span>
      </span>
      <span className="entry-narrative">{entry.particulars}</span>
      <span className="entry-amount">{formatDecimal(entry.amount)}</span>
      <span className={`status ${entry.status}`}>{entry.status}</span>
    </Link>
  )
}
