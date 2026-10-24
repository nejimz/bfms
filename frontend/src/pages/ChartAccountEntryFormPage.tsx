import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage } from '../api'
import { useAuth } from '../auth'
import { chartAccountModule, type ChartAccountModule, type ChartAccountModuleKey } from '../chartAccountModules'
import { BusinessSelect } from '../components/BusinessSelect'
import { formatDecimal, formatMinor, normalizeDecimal, toMinor } from '../money'
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

type DraftItem = {
  key: string
  name: string
  quantity: string
  amount: string
}

function blankItem(): DraftItem {
  return { key: crypto.randomUUID(), name: '', quantity: '1', amount: '' }
}

function normalizeQuantity(value: string): string | null {
  const trimmed = value.trim()
  const normalized = trimmed.startsWith('.') ? `0${trimmed}` : trimmed
  if (!/^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/.test(normalized)) {
    return null
  }

  return Number(normalized) > 0 ? normalized : null
}

function entryPath(module: ChartAccountModule | null, entryId: string): string {
  return module ? `${module.path}/${entryId}` : `/chart-account-entries/${entryId}`
}

function plainDecimal(minor: number): string {
  const absolute = Math.abs(minor)

  return `${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
}

function settlementPayload(reference: string, cash: string, credit: string, totalMinor: number) {
  const referenceNo = reference.trim()
  if (referenceNo.length > 50) {
    throw new Error('Enter a reference number of at most 50 characters.')
  }

  if (cash.trim() === '') {
    throw new Error('Enter the cash amount.')
  }

  if (credit.trim() === '') {
    throw new Error('Enter the credit amount.')
  }

  const cashAmount = normalizeDecimal(cash)
  const creditAmount = normalizeDecimal(credit)
  if (cashAmount === null || creditAmount === null) {
    throw new Error('Enter an amount with up to 2 decimal places.')
  }

  if ((toMinor(cashAmount) ?? 0) + (toMinor(creditAmount) ?? 0) !== totalMinor) {
    throw new Error('Cash and credit must equal the total.')
  }

  return {
    reference: referenceNo === '' ? null : referenceNo,
    cash: cashAmount,
    credit: creditAmount,
  }
}

function buildPayload(
  date: string,
  accountId: string,
  particulars: string,
  amount: string,
  partyLabel: string,
) {
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
    throw new Error(`Enter the ${partyLabel.toLowerCase()}.`)
  }

  return {
    date,
    account_id: Number(accountId),
    particulars: narrative,
    amount: normalized,
  }
}

function buildItemPayload(
  date: string,
  accountId: string,
  particulars: string,
  partyLabel: string,
  itemLabel: string,
  reference: string,
  cash: string,
  credit: string,
  items: DraftItem[],
) {
  const header = buildPayload(date, accountId, particulars, '1.00', partyLabel)
  if (items.length === 0) {
    throw new Error('Add an item.')
  }

  const lines = items.map((item) => {
    const name = item.name.trim()
    if (name === '') {
      throw new Error(`Enter the ${itemLabel.toLowerCase()}.`)
    }

    const quantity = normalizeQuantity(item.quantity)
    if (quantity === null) {
      throw new Error('Enter a quantity greater than zero, with up to 3 decimal places.')
    }

    const amount = normalizeDecimal(item.amount)
    if (amount === null) {
      throw new Error('Enter an amount with up to 2 decimal places.')
    }

    if ((toMinor(amount) ?? 0) <= 0) {
      throw new Error('Enter an amount greater than zero.')
    }

    return { name, quantity, amount }
  })

  const totalMinor = lines.reduce((sum, line) => sum + (toMinor(line.amount) ?? 0), 0)

  return {
    date: header.date,
    account_id: header.account_id,
    particulars: header.particulars,
    ...settlementPayload(reference, cash, credit, totalMinor),
    lines,
  }
}

export function ChartAccountEntryFormPage({ moduleKey }: { moduleKey?: ChartAccountModuleKey }) {
  const moduleConfig = moduleKey ? chartAccountModule(moduleKey) : null
  const listPath = moduleConfig?.path ?? '/chart-account-entries'
  const partyLabel = moduleConfig?.partyLabel ?? 'Particulars'
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
  const [reference, setReference] = useState('')
  const [amount, setAmount] = useState('')
  const [cash, setCash] = useState('')
  const [credit, setCredit] = useState('')
  const [settlementEdited, setSettlementEdited] = useState(Boolean(id))
  const [items, setItems] = useState<DraftItem[] | null>(moduleConfig ? [blankItem()] : null)
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
    setReference(entry.reference ?? '')
    setAmount(entry.amount)
    setCash(entry.cash)
    setCredit(entry.credit)
    setSettlementEdited(true)
    if (moduleConfig || entry.lines.length > 0) {
      setItems(
        entry.lines.length > 0
          ? entry.lines.map((line) => ({
              key: line.id,
              name: line.name,
              quantity: line.quantity,
              amount: line.amount,
            }))
          : [blankItem()],
      )
    } else {
      setItems(null)
    }
  }, [entryQuery.data, moduleConfig])

  const lockedAccount = moduleConfig
    ? accounts.data?.find((account) => account.code === moduleConfig.accountCode)
    : undefined
  const accountNotice = moduleConfig && accounts.isError
    ? 'The chart of accounts could not be opened.'
    : moduleAccountNotice(moduleConfig, accounts.isSuccess, lockedAccount)
  const selectedAccountId = moduleConfig ? (lockedAccount?.is_active ? lockedAccount.id : '') : accountId
  const accountBlocked = Boolean(moduleConfig) && (!accounts.isSuccess || Boolean(accountNotice))

  const save = useMutation({
    mutationFn: async (postAfter: boolean) => {
      const payload = entryPayload()
      const response = id
        ? await api.put(`/api/v1/chart-account-entries/${id}`, payload)
        : await api.post('/api/v1/chart-account-entries', payload)
      const entryId = String(response.data.data.id)

      if (postAfter) {
        try {
          await api.post(`/api/v1/chart-account-entries/${entryId}/post`)
        } catch (error) {
          if (!id) {
            navigate(entryPath(moduleConfig, entryId), {
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
        navigate(entryPath(moduleConfig, entryId), { replace: true })
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
      navigate(listPath)
    },
    onError: (error) => setFormError(errorMessage(error)),
  })

  const entry = entryQuery.data
  const amountMinor = amount.trim() === '' ? null : toMinor(amount)
  const itemTotal = items?.reduce((sum, item) => sum + (toMinor(item.amount) ?? 0), 0) ?? 0
  const totalMinor = items ? itemTotal : amountMinor
  const suggestedCash = totalMinor !== null && totalMinor > 0 ? plainDecimal(totalMinor) : ''
  const cashValue = settlementEdited ? cash : suggestedCash
  const creditValue = settlementEdited ? credit : suggestedCash === '' ? '' : '0.00'

  if (entry?.status === 'posted') {
    return <PostedEntry key={entry.id} entry={entry} listPath={listPath} partyLabel={partyLabel} />
  }

  function editCash(value: string) {
    if (!settlementEdited) {
      setCredit(creditValue)
    }
    setSettlementEdited(true)
    setCash(value)
  }

  function editCredit(value: string) {
    if (!settlementEdited) {
      setCash(cashValue)
    }
    setSettlementEdited(true)
    setCredit(value)
  }

  function entryPayload() {
    if (items) {
      return buildItemPayload(
        date,
        selectedAccountId,
        particulars,
        partyLabel,
        moduleConfig?.itemLabel ?? 'item',
        reference,
        cashValue,
        creditValue,
        items,
      )
    }

    const header = buildPayload(date, selectedAccountId, particulars, amount, partyLabel)

    return {
      ...header,
      ...settlementPayload(reference, cashValue, creditValue, toMinor(header.amount) ?? 0),
    }
  }

  function submit(event: FormEvent, postAfter: boolean) {
    event.preventDefault()
    if (accountNotice) {
      setFormError(accountNotice)
      return
    }
    try {
      entryPayload()
      save.mutate(postAfter)
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Check the entry.')
    }
  }
  const itemsReady =
    items !== null &&
    items.length > 0 &&
    items.every(
      (item) => item.name.trim() !== '' && normalizeQuantity(item.quantity) !== null && (toMinor(item.amount) ?? 0) > 0,
    )
  const cashMinor = cashValue.trim() === '' ? null : toMinor(cashValue)
  const creditMinor = creditValue.trim() === '' ? null : toMinor(creditValue)
  const settlementMatches =
    totalMinor !== null &&
    totalMinor > 0 &&
    cashMinor !== null &&
    creditMinor !== null &&
    cashMinor + creditMinor === totalMinor
  const canPost =
    Boolean(selectedAccountId) &&
    !accountBlocked &&
    particulars.trim() !== '' &&
    settlementMatches &&
    (items ? itemsReady : amountMinor !== null && amountMinor > 0)

  return (
    <form className="page entry-page" onSubmit={(event) => submit(event, false)}>
      <header className="entry-hero">
        <div>
          <p className="eyebrow">
            {user?.company.currency} · {moduleConfig?.title ?? 'Chart account entry'}
          </p>
          <h1>{id ? 'Edit draft' : moduleConfig?.title ?? 'New entry'}</h1>
        </div>
        <BackToEntries to={listPath} />
      </header>

      <div className="entry-composer">
        <div className="panel entry-fields">
          <BusinessSelect disabled={Boolean(id)} onSwitched={() => setAccountId('')} />
          <label>
            Date
            <input type="date" value={date} onChange={(event) => setDate(event.target.value)} required />
          </label>
          <label className="entry-reference">
            Reference no.
            <input
              value={reference}
              onChange={(event) => setReference(event.target.value)}
              maxLength={50}
              placeholder="Store receipt, delivery receipt, or other number"
            />
          </label>
          {moduleConfig ? (
            <label className="entry-account-field">
              Chart of account
              <p className="entry-locked-account">
                {lockedAccount ? `${lockedAccount.code} ${lockedAccount.name}` : moduleConfig.accountCode}
              </p>
            </label>
          ) : (
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
          )}
          <label className="entry-particulars">
            {partyLabel}
            <textarea
              value={particulars}
              onChange={(event) => setParticulars(event.target.value)}
              maxLength={1000}
              placeholder={moduleConfig?.partyPlaceholder ?? 'What is this amount for?'}
              required
            />
          </label>
          {items && (
            <div className="entry-items">
              <div className="entry-item-head" aria-hidden="true">
                <span>{moduleConfig?.itemLabel ?? 'Item'}</span>
                <span>Qty</span>
                <span>Amount</span>
              </div>
              {items.map((item) => (
                <div className="entry-item-row" key={item.key}>
                  <input
                    aria-label={moduleConfig?.itemLabel ?? 'Item'}
                    value={item.name}
                    placeholder={moduleConfig?.itemPlaceholder ?? 'Item name'}
                    maxLength={255}
                    onChange={(event) =>
                      setItems(items.map((row) => (row.key === item.key ? { ...row, name: event.target.value } : row)))
                    }
                  />
                  <input
                    aria-label="Quantity"
                    inputMode="decimal"
                    value={item.quantity}
                    onChange={(event) =>
                      setItems(
                        items.map((row) => (row.key === item.key ? { ...row, quantity: event.target.value } : row)),
                      )
                    }
                  />
                  <input
                    aria-label="Amount"
                    inputMode="decimal"
                    placeholder="0.00"
                    value={item.amount}
                    onChange={(event) =>
                      setItems(
                        items.map((row) => (row.key === item.key ? { ...row, amount: event.target.value } : row)),
                      )
                    }
                  />
                  <button
                    type="button"
                    className="entry-remove-item"
                    disabled={items.length === 1}
                    onClick={() => setItems(items.filter((row) => row.key !== item.key))}
                  >
                    Remove
                  </button>
                </div>
              ))}
              <button type="button" onClick={() => setItems([...items, blankItem()])}>
                Add item
              </button>
            </div>
          )}
        </div>

        <aside className="panel entry-aside">
          {items ? (
            <div>
              <p className="entry-aside-note">Total</p>
              <p className="entry-figure">{formatMinor(itemTotal)}</p>
            </div>
          ) : (
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
          )}
          <div className="entry-split">
            <label>
              Cash
              <input
                inputMode="decimal"
                placeholder="0.00"
                value={cashValue}
                onChange={(event) => editCash(event.target.value)}
              />
            </label>
            <label>
              Credit
              <input
                inputMode="decimal"
                placeholder="0.00"
                value={creditValue}
                onChange={(event) => editCredit(event.target.value)}
              />
            </label>
          </div>
          {cashMinor !== null && creditMinor !== null && totalMinor !== null && totalMinor > 0 && !settlementMatches && (
            <p className="form-error">Cash and credit must equal the total.</p>
          )}
          <p className="entry-aside-note">
            Credit is the portion still unpaid. {user?.company.currency}. This register stays off the general ledger.
          </p>
          {accountNotice && <p className="form-error">{accountNotice}</p>}
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
            <button type="submit" disabled={save.isPending || accountBlocked}>
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

function BackToEntries({ to }: { to: string }) {
  return (
    <Link className="entry-back" to={to}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
        <path d="M15 6 9 12l6 6" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M9 12h11" strokeLinecap="round" />
      </svg>
      Back to entries
    </Link>
  )
}

function moduleAccountNotice(
  moduleConfig: ChartAccountModule | null,
  accountsLoaded: boolean,
  lockedAccount: Account | undefined,
): string | null {
  if (!moduleConfig || !accountsLoaded) {
    return null
  }

  if (!lockedAccount) {
    return `${moduleConfig.accountCode} is not on this business.`
  }

  if (!lockedAccount.is_active) {
    return `${lockedAccount.code} ${lockedAccount.name} is inactive.`
  }

  return null
}

function PostedEntry({
  entry,
  listPath,
  partyLabel,
}: {
  entry: ChartAccountEntry
  listPath: string
  partyLabel: string
}) {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const companyId = user?.company.id
  const [reference, setReference] = useState(entry.reference ?? '')
  const [cash, setCash] = useState(entry.cash)
  const [credit, setCredit] = useState(entry.credit)
  const [formError, setFormError] = useState<string | null>(null)
  const code = entry.account?.code
  const name = entry.account?.name ?? entry.account_id
  const totalMinor = toMinor(entry.amount) ?? 0

  const save = useMutation({
    mutationFn: async () => {
      await api.put(`/api/v1/chart-account-entries/${entry.id}`, settlementPayload(reference, cash, credit, totalMinor))
    },
    onSuccess: async () => {
      setFormError(null)
      await queryClient.invalidateQueries({ queryKey: ['chart-account-entries', companyId] })
      await queryClient.invalidateQueries({ queryKey: ['chart-account-entry', companyId, entry.id] })
      await queryClient.invalidateQueries({ queryKey: ['chart-account-columns', companyId] })
    },
    onError: (error) => setFormError(errorMessage(error)),
  })

  function submit(event: FormEvent) {
    event.preventDefault()
    try {
      settlementPayload(reference, cash, credit, totalMinor)
      save.mutate()
    } catch (error) {
      setFormError(error instanceof Error ? error.message : 'Check the entry.')
    }
  }

  return (
    <div className="page entry-page entry-posted">
      <header className="entry-posted-bar">
        <BackToEntries to={listPath} />
        <span className="status posted">Posted</span>
      </header>

      <form className="panel entry-receipt" onSubmit={submit}>
        <div className="entry-receipt-meta">
          <time dateTime={entry.date}>{formatEntryDate(entry.date)}</time>
          <span>{entry.currency}</span>
        </div>
        <p className="entry-figure">{formatDecimal(entry.amount)}</p>
        <p className="entry-receipt-account">
          {code && <span className="entry-code">{code}</span>}
          <span>{name}</span>
        </p>
        <div className="entry-receipt-rule" />
        <p className="entry-party-label">{partyLabel}</p>
        <p className="entry-quote">{entry.particulars}</p>
        {entry.lines.length > 0 && (
          <ul className="entry-item-list">
            {entry.lines.map((line) => (
              <li key={line.id}>
                <span className="entry-item-name">
                  <span className="entry-item-qty">{line.quantity}</span>
                  {line.name}
                </span>
                <span>{formatDecimal(line.amount)}</span>
              </li>
            ))}
          </ul>
        )}
        <label className="entry-reference">
          Reference no.
          <input
            value={reference}
            onChange={(event) => setReference(event.target.value)}
            maxLength={50}
            placeholder="Store receipt, delivery receipt, or other number"
          />
        </label>
        <div className="entry-split">
          <label>
            Cash
            <input inputMode="decimal" placeholder="0.00" value={cash} onChange={(event) => setCash(event.target.value)} />
          </label>
          <label>
            Credit
            <input
              inputMode="decimal"
              placeholder="0.00"
              value={credit}
              onChange={(event) => setCredit(event.target.value)}
            />
          </label>
        </div>
        {formError && <p className="form-error">{formError}</p>}
        <p className="entry-aside-note">
          Credit is the portion still unpaid. The date, items, and total stay locked.
        </p>
        <button type="submit" className="primary" disabled={save.isPending}>
          Save
        </button>
      </form>
    </div>
  )
}
