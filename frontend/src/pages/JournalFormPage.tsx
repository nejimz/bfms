import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage } from '../api'
import { useAuth } from '../auth'
import { BusinessSelect } from '../components/BusinessSelect'
import { formatDecimal, formatMinor, normalizeDecimal, toMinor } from '../money'
import type { Account, Journal, JournalLine } from '../types'
import { readJournal, readResource } from '../types'

type DraftLine = {
  key: string
  account_id: string
  description: string
  debit: string
  credit: string
}

function localDate(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}

function blankLine(): DraftLine {
  return {
    key: crypto.randomUUID(),
    account_id: '',
    description: '',
    debit: '',
    credit: '',
  }
}

function fromJournalLine(line: JournalLine): DraftLine {
  return {
    key: line.id,
    account_id: line.account_id,
    description: line.description ?? '',
    debit: line.debit === '0.00' ? '' : line.debit,
    credit: line.credit === '0.00' ? '' : line.credit,
  }
}

export function JournalFormPage() {
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
  const journalQuery = useQuery({
    queryKey: ['journal', companyId, id],
    enabled: Boolean(companyId) && Boolean(id),
    queryFn: async () => {
      const response = await api.get(`/api/v1/journals/${id}`)
      return readJournal(response.data)
    },
  })

  const [date, setDate] = useState(localDate)
  const [reference, setReference] = useState('')
  const [memo, setMemo] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([blankLine(), blankLine()])
  const [formError, setFormError] = useState<string | null>(null)

  useEffect(() => {
    const state = location.state as { formError?: string } | null
    if (state?.formError) {
      setFormError(state.formError)
    }
  }, [location.state])

  useEffect(() => {
    const journal = journalQuery.data
    if (!journal) {
      return
    }

    setDate(journal.date)
    setReference(journal.reference ?? '')
    setMemo(journal.memo ?? '')
    setLines(journal.lines.length > 0 ? journal.lines.map(fromJournalLine) : [blankLine(), blankLine()])
  }, [journalQuery.data])

  const totals = useMemo(() => {
    let debit = 0
    let credit = 0
    let valid = true

    for (const line of lines) {
      const debitMinor = toMinor(line.debit)
      const creditMinor = toMinor(line.credit)
      if (debitMinor === null || creditMinor === null) {
        valid = false
        continue
      }
      debit += debitMinor
      credit += creditMinor
    }

    return { debit, credit, difference: debit - credit, valid }
  }, [lines])

  const save = useMutation({
    mutationFn: async (postAfter: boolean) => {
      const payload = buildPayload(date, reference, memo, lines)
      const response = id
        ? await api.put(`/api/v1/journals/${id}`, payload)
        : await api.post('/api/v1/journals', payload)
      const journalId = String(response.data.data.id)

      if (postAfter) {
        try {
          await api.post(`/api/v1/journals/${journalId}/post`)
        } catch (error) {
          if (!id) {
            navigate(`/journals/${journalId}`, {
              replace: true,
              state: { formError: errorMessage(error) },
            })
          }
          throw error
        }
      }

      return journalId
    },
    onSuccess: async (journalId) => {
      setFormError(null)
      await queryClient.invalidateQueries({ queryKey: ['journals', companyId] })
      await queryClient.invalidateQueries({ queryKey: ['journal', companyId, journalId] })
      await queryClient.invalidateQueries({ queryKey: ['trial-balance', companyId] })
      if (!id) {
        navigate(`/journals/${journalId}`, { replace: true })
      }
    },
    onError: (error) => setFormError(errorMessage(error)),
  })

  const journal = journalQuery.data

  if (journal?.status === 'posted') {
    return <PostedJournal journal={journal} accounts={accounts.data ?? []} />
  }

  function updateLine(key: string, patch: Partial<DraftLine>) {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  }

  function submit(event: FormEvent, postAfter: boolean) {
    event.preventDefault()
    try {
      buildPayload(date, reference, memo, lines)
      save.mutate(postAfter)
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Check the journal lines.')
    }
  }

  return (
    <form className="page" onSubmit={(event) => submit(event, false)}>
      <header className="page-header">
        <div>
          <p className="eyebrow">{user?.company.currency} · Journal</p>
          <h1>{id ? 'Edit draft' : 'New journal'}</h1>
        </div>
        <Link to="/journals">Back to journals</Link>
      </header>

      <div className="panel journal-head">
        <BusinessSelect
          disabled={Boolean(id)}
          onSwitched={() =>
            setLines((current) => current.map((line) => ({ ...line, account_id: '' })))
          }
        />
        <label>
          Date
          <input type="date" value={date} onChange={(event) => setDate(event.target.value)} required />
        </label>
        <label>
          Reference
          <input value={reference} onChange={(event) => setReference(event.target.value)} maxLength={50} />
        </label>
        <label className="grow">
          Memo
          <input value={memo} onChange={(event) => setMemo(event.target.value)} maxLength={1000} />
        </label>
      </div>

      <div className="table-wrap">
        <table className="lines">
          <thead>
            <tr>
              <th>Account</th>
              <th>Description</th>
              <th className="amount">Debit</th>
              <th className="amount">Credit</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {lines.map((line) => (
              <tr key={line.key}>
                <td>
                  <select
                    value={line.account_id}
                    onChange={(event) => updateLine(line.key, { account_id: event.target.value })}
                  >
                    <option value="">Select account</option>
                    {accounts.data
                      ?.filter((account) => account.is_active || account.id === line.account_id)
                      .map((account) => (
                        <option key={account.id} value={account.id}>
                          {account.code} {account.name}
                        </option>
                      ))}
                  </select>
                </td>
                <td>
                  <input
                    value={line.description}
                    onChange={(event) => updateLine(line.key, { description: event.target.value })}
                  />
                </td>
                <td>
                  <input
                    className="num"
                    inputMode="decimal"
                    value={line.debit}
                    onChange={(event) => updateLine(line.key, { debit: event.target.value })}
                  />
                </td>
                <td>
                  <input
                    className="num"
                    inputMode="decimal"
                    value={line.credit}
                    onChange={(event) => updateLine(line.key, { credit: event.target.value })}
                  />
                </td>
                <td>
                  <button
                    type="button"
                    onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
                  >
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="journal-footer">
        <button type="button" onClick={() => setLines((current) => [...current, blankLine()])}>
          Add line
        </button>
        <div className="totals">
          <span>Debit {formatMinor(totals.debit)}</span>
          <span>Credit {formatMinor(totals.credit)}</span>
          <strong className={totals.difference === 0 ? 'balanced' : 'unbalanced'}>
            {totals.valid && totals.difference === 0 ? 'In balance' : `Difference ${formatMinor(totals.difference)}`}
          </strong>
        </div>
        <div className="actions">
          <button type="submit" disabled={save.isPending}>
            Save draft
          </button>
          <button
            type="button"
            className="primary"
            disabled={save.isPending || !totals.valid || totals.difference !== 0 || totals.debit === 0}
            onClick={(event) => submit(event, true)}
          >
            Post
          </button>
        </div>
      </div>
      {formError && <p className="form-error">{formError}</p>}
    </form>
  )
}

function PostedJournal({ journal, accounts }: { journal: Journal; accounts: Account[] }) {
  const accountName = (accountId: string) => {
    const account = accounts.find((item) => item.id === accountId)
    return account ? `${account.code} ${account.name}` : accountId
  }

  const debit = journal.lines.reduce((sum, line) => sum + (toMinor(line.debit) ?? 0), 0)
  const credit = journal.lines.reduce((sum, line) => sum + (toMinor(line.credit) ?? 0), 0)

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Posted journal · {journal.currency}</p>
          <h1>{journal.reference || journal.date}</h1>
        </div>
        <BusinessSelect disabled />
        <Link to="/journals">Back to journals</Link>
      </header>
      <p className="posted-note">This journal is posted. A correction is a new reversing entry.</p>
      <dl className="meta-row">
        <div>
          <dt>Date</dt>
          <dd>{journal.date}</dd>
        </div>
        <div>
          <dt>Memo</dt>
          <dd>{journal.memo || '—'}</dd>
        </div>
      </dl>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Account</th>
              <th>Description</th>
              <th className="amount">Debit</th>
              <th className="amount">Credit</th>
            </tr>
          </thead>
          <tbody>
            {journal.lines.map((line) => (
              <tr key={line.id}>
                <td>{accountName(line.account_id)}</td>
                <td>{line.description}</td>
                <td className="num">{formatDecimal(line.debit)}</td>
                <td className="num">{formatDecimal(line.credit)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <td colSpan={2}>Total</td>
              <td className="num">{formatMinor(debit)}</td>
              <td className="num">{formatMinor(credit)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  )
}

function buildPayload(date: string, reference: string, memo: string, lines: DraftLine[]) {
  const prepared = lines.filter(
    (line) => line.account_id || line.description.trim() || line.debit.trim() || line.credit.trim(),
  )

  if (prepared.length === 0) {
    throw new Error('Add at least one line.')
  }

  return {
    date,
    reference: reference.trim() || null,
    memo: memo.trim() || null,
    lines: prepared.map((line) => {
      if (!line.account_id) {
        throw new Error('Every line needs an account.')
      }

      const debit = normalizeDecimal(line.debit)
      const credit = normalizeDecimal(line.credit)
      if (debit === null || credit === null) {
        throw new Error('Enter amounts with up to 2 decimal places.')
      }

      const debitMinor = toMinor(debit) ?? 0
      const creditMinor = toMinor(credit) ?? 0
      if (debitMinor > 0 && creditMinor > 0) {
        throw new Error('A line cannot have both a debit and a credit.')
      }
      if (debitMinor === 0 && creditMinor === 0) {
        throw new Error('Each line needs a debit or a credit.')
      }

      return {
        account_id: Number(line.account_id),
        description: line.description.trim() || null,
        debit,
        credit,
      }
    }),
  }
}
