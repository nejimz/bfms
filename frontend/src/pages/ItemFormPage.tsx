import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { readResource, type Item } from '../types'

const nameLimit = 255
const unitLimit = 50
const descriptionLimit = 1000

function clientErrors(name: string, unit: string, description: string): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!name.trim()) {
    errors.name = 'Enter an item name.'
  } else if (name.trim().length > nameLimit) {
    errors.name = 'The item name must be 255 characters or fewer.'
  }

  if (!unit.trim()) {
    errors.unit = 'Enter a unit.'
  } else if (unit.trim().length > unitLimit) {
    errors.unit = 'The unit must be 50 characters or fewer.'
  }

  if (description.trim().length > descriptionLimit) {
    errors.description = 'The description must be 1000 characters or fewer.'
  }

  return errors
}

export function ItemFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const items = useQuery({
    queryKey: ['items'],
    enabled: editing && Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/items')
      return (response.data.data as Array<{ id: string; attributes: Omit<Item, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const item = items.data?.find((row) => row.id === id)
  const [name, setName] = useState('')
  const [unit, setUnit] = useState('')
  const [description, setDescription] = useState('')
  const [filled, setFilled] = useState(!editing)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  useEffect(() => {
    if (!item || filled) {
      return
    }

    setName(item.name)
    setUnit(item.unit)
    setDescription(item.description ?? '')
    setFilled(true)
  }, [item, filled])

  if (user && user.role !== 'admin') {
    return <Navigate to="/items" replace />
  }

  if (editing && items.isSuccess && !item) {
    return <Navigate to="/items" replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(name, unit, description)
    setErrors(nextErrors)
    setFormError(null)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const payload = {
        name: name.trim(),
        unit: unit.trim(),
        description: description.trim() === '' ? null : description.trim(),
      }
      if (editing) {
        await api.patch(`/api/v1/items/${id}`, payload)
      } else {
        await api.post('/api/v1/items', payload)
      }
      await queryClient.invalidateQueries({ queryKey: ['items'] })
      navigate('/items')
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
    if (!id || !window.confirm(`Delete ${name.trim() || 'this item'}?`)) {
      return
    }

    setPending(true)
    setFormError(null)

    try {
      await api.delete(`/api/v1/items/${id}`)
      await queryClient.invalidateQueries({ queryKey: ['items'] })
      navigate('/items')
    } catch (caught) {
      setFormError(errorMessage(caught))
      setPending(false)
    }
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>{editing ? 'Edit item' : 'Add item'}</h1>
          <p className="hint">Sales, purchases, and expenses choose from this list.</p>
        </div>
      </header>

      {editing && items.isLoading ? (
        <p className="hint">Loading item…</p>
      ) : (
        <form className="panel stack-form" noValidate onSubmit={(event) => void submit(event)}>
          <label>
            Name
            <input
              value={name}
              onChange={(event) => setName(event.target.value)}
              required
              maxLength={nameLimit}
              aria-invalid={Boolean(errors.name)}
            />
            {errors.name && <span className="form-error">{errors.name}</span>}
          </label>
          <label>
            Unit
            <input
              value={unit}
              onChange={(event) => setUnit(event.target.value)}
              required
              maxLength={unitLimit}
              placeholder="kg, piece, sack"
              aria-invalid={Boolean(errors.unit)}
            />
            {errors.unit && <span className="form-error">{errors.unit}</span>}
          </label>
          <label>
            Description
            <textarea
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              maxLength={descriptionLimit}
              placeholder="Optional"
              aria-invalid={Boolean(errors.description)}
            />
            {errors.description && <span className="form-error">{errors.description}</span>}
          </label>
          {editing && (item?.entries_count ?? 0) > 0 && !formError && (
            <p className="hint">Remove this item from its entries before deleting it.</p>
          )}
          {formError && <p className="form-error">{formError}</p>}
          <div className="actions">
            <button type="submit" className="primary" disabled={pending || (editing && !filled)}>
              {pending ? 'Saving…' : editing ? 'Save' : 'Add item'}
            </button>
            {editing && (
              <button type="button" disabled={pending} onClick={() => void remove()}>
                Delete
              </button>
            )}
            <Link className="button-link" to="/items">
              Cancel
            </Link>
          </div>
        </form>
      )}
    </div>
  )
}
