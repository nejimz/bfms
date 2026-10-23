import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage } from '../api'
import { useAuth } from '../auth'
import { BusinessSelect } from '../components/BusinessSelect'
import { formatDecimal, normalizeDecimal, toMinor } from '../money'
import type { Account, ChartAccountEntry } from '../types'
import { readChartAccountEntry, readResource } from '../types'

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

function localDate(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}

function buildPayload(date: string, accountId: string, particulars: string, amount: string) {
  if (!accountId) {
    throw new Error('Choose a chart of account.')
  }

  const normalized = normalizeDecimal(amount)
  if (normalized === null) {
    throw new Error('Enter an amount with up to 2 decimal places.')
  }

  if ((toMinor(normalized) ?? 0) <= 0) {
    throw new Error('Enter an amount greater than zero.')
  }

  const narrative = particulars.trim()
  if (narrative === '') {
    throw new Error('Enter the particulars.')
  }

  return {
    date,
    account_id: Number(accountId),
    particulars: narrative,
    amount: normalized,
  }
}

export function ChartAccountEntryFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const companyId = user?.company.id
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(companyId),
    queryFn: async () => {
      const response = await api.get('/api/v1/accounts')
      return (response.data.data as Array<{ id: string; attributes: Omit<Account, 'id'> }>).map(
        (resource) => readResource(resource),
      )
    },
  })
  const entryQuery = useQuery({
    queryKey: ['chart-account-entry', companyId, id],
    enabled: Boolean(companyId) && Boolean(id),
    queryFn: async () => {
      const response = await api.get(`/api/v1/chart-account-entries/${id}`)
      return readChartAccountEntry(response.data)
    },
  })

  const [date, setDate] = useState(localDate)
  const [accountId, setAccountId] = useState('')
  const [particulars, setParticulars] = useState('')
  const [amount, setAmount] = useState('')
  const [formError, setFormError] = useState<string | null>(null)

  useEffect(() => {
    const state = location.state as { formError?: string } | null
    if (state?.formError) {
      setFormError(state.formError)
    }
  }, [location.state])

  useEffect(() => {
    const entry = entryQuery.data
    if (!entry) {
      return
    }

    setDate(entry.date)
    setAccountId(entry.account_id)
    setParticulars(entry.particulars)
    setAmount(entry.amount)
  }, [entryQuery.data])

  const save = useMutation({
    mutationFn: async (postAfter: boolean) => {
      const payload = buildPayload(date, accountId, particulars, amount)
      const response = id
        ? await api.put(`/api/v1/chart-account-entries/${id}`, payload)
        : await api.post('/api/v1/chart-account-entries', payload)
      const entryId = String(response.data.data.id)

      if (postAfter) {
        try {
          await api.post(`/api/v1/chart-account-entries/${entryId}/post`)
        } catch (error) {
          if (!id) {
            navigate(`/chart-account-entries/${entryId}`, {
              replace: true,
              state: { formError: errorMessage(error) },
            })
          }
          throw error
        }
      }

      return entryId
    },
    onSuccess: async (entryId) => {
      setFormError(null)
      await queryClient.invalidateQueries({ queryKey: ['chart-account-entries', companyId] })
      await queryClient.invalidateQueries({ queryKey: ['chart-account-entry', companyId, entryId] })
      if (!id) {
        navigate(`/chart-account-entries/${entryId}`, { replace: true })
      }
    },
    onError: (error) => setFormError(errorMessage(error)),
  })

  const remove = useMutation({
    mutationFn: async () => {
      await api.delete(`/api/v1/chart-account-entries/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['chart-account-entries', companyId] })
      navigate('/chart-account-entries')
    },
    onError: (error) => setFormError(errorMessage(error)),
  })

  const entry = entryQuery.data

  if (entry?.status === 'posted') {
    return <PostedEntry entry={entry} />
  }

  function submit(event: FormEvent, postAfter: boolean) {
    event.preventDefault()
    try {
      buildPayload(date, accountId, particulars, amount)
      save.mutate(postAfter)
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Check the entry.')
    }
  }

  const amountMinor = toMinor(amount)
  const canPost = Boolean(accountId) && particulars.trim() !== '' && amountMinor !== null && amountMinor > 0

  return (
    <form className="page entry-page" onSubmit={(event) => submit(event, false)}>
      <header className="entry-hero">
        <div>
          <p className="eyebrow">{user?.company.currency} · Chart account entry</p>
          <h1>{id ? 'Edit draft' : 'New entry'}</h1>
        </div>
        <Link className="entry-back" to="/chart-account-entries">
          Back to entries
        </Link>
      </header>

      <div className="entry-composer">
        <div className="panel entry-fields">
          <BusinessSelect disabled={Boolean(id)} onSwitched={() => setAccountId('')} />
          <label>
            Date
            <input type="date" value={date} onChange={(event) => setDate(event.target.value)} required />
          </label>
          <label className="entry-account-field">
            Chart of account
            <select value={accountId} onChange={(event) => setAccountId(event.target.value)} required>
              <option value="">Select account</option>
              {accounts.data
                ?.filter((account) => account.is_active || account.id === accountId)
                .map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.code} {account.name}
                  </option>
                ))}
            </select>
          </label>
          <label className="entry-particulars">
            Particulars
            <textarea
              value={particulars}
              onChange={(event) => setParticulars(event.target.value)}
              maxLength={1000}
              placeholder="What is this amount for?"
              required
            />
          </label>
        </div>

        <aside className="panel entry-aside">
          <label>
            Amount
            <input
              className="entry-amount-input"
              inputMode="decimal"
              placeholder="0.00"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              required
            />
          </label>
          <p className="entry-aside-note">{user?.company.currency}. This register stays off the general ledger.</p>
          {formError && <p className="form-error">{formError}</p>}
          <div className="entry-actions">
            <button
              type="button"
              className="primary"
              disabled={save.isPending || !canPost}
              onClick={(event) => submit(event, true)}
            >
              Post
            </button>
            <button type="submit" disabled={save.isPending}>
              Save draft
            </button>
            {id && (
              <button
                type="button"
                className="entry-delete"
                disabled={remove.isPending}
                onClick={() => remove.mutate()}
              >
                Delete draft
              </button>
            )}
          </div>
        </aside>
      </div>
    </form>
  )
}

function PostedEntry({ entry }: { entry: ChartAccountEntry }) {
  const code = entry.account?.code
  const name = entry.account?.name ?? entry.account_id

  return (
    <div className="page entry-page">
      <header className="entry-hero">
        <div>
          <p className="eyebrow">Posted · {entry.currency}</p>
          <h1>Chart account entry</h1>
        </div>
        <div className="entry-hero-actions">
          <BusinessSelect disabled />
          <Link className="entry-back" to="/chart-account-entries">
            Back to entries
          </Link>
        </div>
      </header>

      <article className="panel entry-receipt">
        <p className="entry-figure">{formatDecimal(entry.amount)}</p>
        <p className="entry-receipt-account">
          {code && <span className="entry-code">{code}</span>}
          <span>{name}</span>
        </p>
        <time dateTime={entry.date}>{formatEntryDate(entry.date)}</time>
        <p className="entry-quote">{entry.particulars}</p>
        <p className="entry-aside-note">Posted entries stay off the general ledger.</p>
      </article>
    </div>
  )
}
