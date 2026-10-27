import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { readResource, type Inventory, type Item } from '../types'

const noteLimit = 1000
const quantityMessage = 'Enter a quantity of zero or more, with up to 3 decimal places.'
const quantityLimitMessage = 'Enter a quantity of at most 999,999,999.999.'

function quantityError(value: string): string | null {
  const trimmed = value.trim()
  const normalized = trimmed.startsWith('.') ? `0${trimmed}` : trimmed

  if (!/^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/.test(normalized)) {
    return quantityMessage
  }

  if (Number(normalized) > 999999999.999) {
    return quantityLimitMessage
  }

  return null
}

function clientErrors(itemId: string, quantity: string, note: string): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!itemId) {
    errors.item_id = 'Choose an item.'
  }

  const quantityProblem = quantityError(quantity)
  if (quantityProblem) {
    errors.quantity = quantityProblem
  }

  if (note.trim().length > noteLimit) {
    errors.note = 'The note must be 1000 characters or fewer.'
  }

  return errors
}

export function InventoryFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
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
  const inventories = useQuery({
    queryKey: ['inventories'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/inventories')
      return (response.data.data as Array<{ id: string; attributes: Omit<Inventory, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const inventory = inventories.data?.find((row) => row.id === id)
  const taken = new Set(
    (inventories.data ?? []).filter((row) => row.id !== id).map((row) => row.item_id),
  )
  const choices = (items.data ?? []).filter((item) => !taken.has(item.id))
  const [itemId, setItemId] = useState('')
  const [quantity, setQuantity] = useState('')
  const [note, setNote] = useState('')
  const [filled, setFilled] = useState(!editing)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)
  const selected = choices.find((item) => item.id === itemId) ?? items.data?.find((item) => item.id === itemId)

  useEffect(() => {
    if (!inventory || filled) {
      return
    }

    setItemId(inventory.item_id)
    setQuantity(inventory.quantity)
    setNote(inventory.note ?? '')
    setFilled(true)
  }, [inventory, filled])

  if (user && user.role !== 'admin') {
    return <Navigate to="/inventories" replace />
  }

  if (editing && inventories.isSuccess && !inventory) {
    return <Navigate to="/inventories" replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(itemId, quantity, note)
    setErrors(nextErrors)
    setFormError(null)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    const normalized = quantity.trim().startsWith('.') ? `0${quantity.trim()}` : quantity.trim()
    setPending(true)

    try {
      const payload = {
        item_id: Number(itemId),
        quantity: normalized,
        note: note.trim() === '' ? null : note.trim(),
      }
      if (editing) {
        await api.patch(`/api/v1/inventories/${id}`, payload)
      } else {
        await api.post('/api/v1/inventories', payload)
      }
      await queryClient.invalidateQueries({ queryKey: ['inventories'] })
      await queryClient.invalidateQueries({ queryKey: ['items'] })
      navigate('/inventories')
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

  async function remove() {
    if (!id || !window.confirm(`Delete inventory for ${selected?.name ?? 'this item'}?`)) {
      return
    }

    setPending(true)
    setFormError(null)

    try {
      await api.delete(`/api/v1/inventories/${id}`)
      await queryClient.invalidateQueries({ queryKey: ['inventories'] })
      await queryClient.invalidateQueries({ queryKey: ['items'] })
      navigate('/inventories')
    } catch (caught) {
      setFormError(errorMessage(caught))
      setPending(false)
    }
  }

  const loading = items.isLoading || inventories.isLoading || (editing && !filled)

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>{editing ? 'Edit inventory' : 'Add inventory'}</h1>
          <p className="hint">One quantity on hand for each item.</p>
        </div>
      </header>

      {loading ? (
        <p className="hint">Loading inventory…</p>
      ) : (
        <form className="panel stack-form" noValidate onSubmit={(event) => void submit(event)}>
          <label>
            Item
            <select
              value={itemId}
              onChange={(event) => setItemId(event.target.value)}
              required
              aria-invalid={Boolean(errors.item_id)}
            >
              <option value="">Choose an item</option>
              {choices.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
            {items.isError && <span className="form-error">The items could not be opened.</span>}
            {!items.isError && (items.data?.length ?? 0) === 0 && (
              <span className="hint">Add an item first.</span>
            )}
            {!items.isError && (items.data?.length ?? 0) > 0 && choices.length === 0 && (
              <span className="hint">Every item already has inventory. Add an item first.</span>
            )}
            {errors.item_id && <span className="form-error">{errors.item_id}</span>}
          </label>
          <label>
            Quantity{selected ? ` (${selected.unit})` : ''}
            <input
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
              required
              inputMode="decimal"
              placeholder="0"
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
            <button type="submit" className="primary" disabled={pending || choices.length === 0}>
              {pending ? 'Saving…' : editing ? 'Save' : 'Add inventory'}
            </button>
            {editing && (
              <button type="button" disabled={pending} onClick={() => void remove()}>
                Delete
              </button>
            )}
            <Link className="button-link" to="/inventories">
              Cancel
            </Link>
          </div>
        </form>
      )}
    </div>
  )
}
