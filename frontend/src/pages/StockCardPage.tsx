import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { readResource, type Item, type StockCardRow } from '../types'

const noteLimit = 1000
const quantityMessage = 'Enter a quantity other than zero, with up to 3 decimal places.'
const quantityLimitMessage = 'Enter a quantity of at most 999,999,999.999.'

function today(): string {
  const now = new Date()

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function formatOn(value: string): string {
  const [year, month, day] = value.split('-').map(Number)

  if (!year || !month || !day) {
    return value
  }

  return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

function qty(value: string | null): string {
  return value === null ? '—' : value
}

function normalizeAdjustment(value: string): string | null {
  const trimmed = value.trim()
  const normalized = trimmed.startsWith('-.')
    ? `-0${trimmed.slice(1)}`
    : trimmed.startsWith('.')
      ? `0${trimmed}`
      : trimmed

  if (!/^-?(?:0|[1-9]\d*)(?:\.\d{1,3})?$/.test(normalized) || Number(normalized) === 0) {
    return null
  }

  if (Math.abs(Number(normalized)) > 999999999.999) {
    return 'limit'
  }

  return normalized
}

export function StockCardPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [itemId, setItemId] = useState('')
  const [adjustItemId, setAdjustItemId] = useState('')
  const [date, setDate] = useState(today)
  const [quantity, setQuantity] = useState('')
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const editable = user?.role === 'admin'
  const items = useQuery({
    queryKey: ['items'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/items')
      return (response.data.data as Array<{ id: string; attributes: Omit<Item, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const card = useQuery({
    queryKey: ['stock-card', itemId],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get<{ data: StockCardRow[] }>('/api/v1/reports/stock-card', {
        params: itemId === '' ? {} : { item_id: itemId },
      })

      return response.data.data
    },
  })

  const rows = card.data ?? []

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors: Record<string, string> = {}
    const normalized = normalizeAdjustment(quantity)

    if (!adjustItemId) {
      nextErrors.item_id = 'Choose an item.'
    }

    if (!date) {
      nextErrors.date = 'Enter the date.'
    }

    if (normalized === null) {
      nextErrors.quantity = quantityMessage
    } else if (normalized === 'limit') {
      nextErrors.quantity = quantityLimitMessage
    }

    if (note.trim().length > noteLimit) {
      nextErrors.note = 'The note must be 1000 characters or fewer.'
    }

    setErrors(nextErrors)
    setFormError(null)

    if (Object.keys(nextErrors).length > 0 || normalized === null || normalized === 'limit') {
      return
    }

    setPending(true)

    try {
      await api.post('/api/v1/inventory-adjustments', {
        item_id: Number(adjustItemId),
        date,
        quantity: normalized,
        note: note.trim() === '' ? null : note.trim(),
      })
      setQuantity('')
      setNote('')
      await queryClient.invalidateQueries({ queryKey: ['stock-card'] })
      await queryClient.invalidateQueries({ queryKey: ['activity-logs'] })
    } catch (caught) {
      const fields = fieldErrors(caught)
      setErrors(fields)
      if (Object.keys(fields).length === 0) {
        setFormError(errorMessage(caught))
      }
    } finally {
      setPending(false)
    }
  }

  async function remove(row: StockCardRow) {
    if (!row.adjustment_id || !window.confirm(`Delete the adjustment for ${row.item_name}?`)) {
      return
    }

    setFormError(null)

    try {
      await api.delete(`/api/v1/inventory-adjustments/${row.adjustment_id}`)
      await queryClient.invalidateQueries({ queryKey: ['stock-card'] })
      await queryClient.invalidateQueries({ queryKey: ['activity-logs'] })
    } catch (caught) {
      setFormError(errorMessage(caught))
    }
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>Stock card</h1>
          <p className="hint">
            Starting quantity, posted purchases, and posted sales from every business. An adjustment corrects the count.
            Remaining is the running balance.
          </p>
        </div>
        <Link className="button-link" to="/inventories">
          Inventory
        </Link>
      </header>

      <form className="panel inline-form" onSubmit={(event) => event.preventDefault()}>
        <label>
          Item
          <select value={itemId} onChange={(event) => setItemId(event.target.value)}>
            <option value="">All items</option>
            {items.data?.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </select>
        </label>
      </form>

      {card.isLoading && <p className="hint">Loading the stock card…</p>}
      {card.isError && <p className="form-error">The stock card could not be opened.</p>}
      {card.isSuccess && rows.length === 0 && (
        <div className="category-empty panel">
          <strong>No stock movements yet</strong>
          <p>Add a starting quantity under Inventory, or record an adjustment.</p>
        </div>
      )}

      {rows.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Item</th>
                <th className="amount">Purchase qty</th>
                <th className="amount">Sold qty</th>
                <th className="amount">Adjust qty</th>
                <th className="amount">Remaining</th>
                {editable && <th />}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  <td>{formatOn(row.date)}</td>
                  <td>
                    {row.item_name}
                    <span className="item-meta"> {row.unit}</span>
                  </td>
                  <td className="num">{qty(row.purchase_quantity)}</td>
                  <td className="num">{qty(row.sold_quantity)}</td>
                  <td className="num">{qty(row.adjust_quantity)}</td>
                  <td className="num">{row.remaining_quantity}</td>
                  {editable && (
                    <td>
                      {row.adjustment_id && (
                        <button type="button" onClick={() => void remove(row)}>
                          Delete
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editable && (
        <form className="panel stack-form" noValidate onSubmit={(event) => void submit(event)}>
          <h2>Adjust quantity</h2>
          <p className="hint">A positive quantity adds stock. A negative quantity removes it.</p>
          <label>
            Item
            <select
              value={adjustItemId}
              onChange={(event) => setAdjustItemId(event.target.value)}
              required
              aria-invalid={Boolean(errors.item_id)}
            >
              <option value="">Choose an item</option>
              {items.data?.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
            {errors.item_id && <span className="form-error">{errors.item_id}</span>}
          </label>
          <label>
            Date
            <input
              type="date"
              value={date}
              onChange={(event) => setDate(event.target.value)}
              required
              aria-invalid={Boolean(errors.date)}
            />
            {errors.date && <span className="form-error">{errors.date}</span>}
          </label>
          <label>
            Quantity
            <input
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
              required
              inputMode="decimal"
              placeholder="-1 or 2.5"
              aria-invalid={Boolean(errors.quantity)}
            />
            {errors.quantity && <span className="form-error">{errors.quantity}</span>}
          </label>
          <label>
            Note
            <textarea
              value={note}
              onChange={(event) => setNote(event.target.value)}
              maxLength={noteLimit}
              placeholder="Optional"
              aria-invalid={Boolean(errors.note)}
            />
            {errors.note && <span className="form-error">{errors.note}</span>}
          </label>
          {formError && <p className="form-error">{formError}</p>}
          <div className="actions">
            <button type="submit" className="primary" disabled={pending}>
              {pending ? 'Saving…' : 'Add adjustment'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
