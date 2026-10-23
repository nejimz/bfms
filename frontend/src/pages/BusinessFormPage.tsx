import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import type { Business } from '../types'
import { readResource } from '../types'

const limits = { name: 255, description: 1000 }

function clientErrors(name: string, description: string, currency: string): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!name.trim()) {
    errors.name = 'Enter a business name.'
  } else if (name.trim().length > limits.name) {
    errors.name = 'The business name must be 255 characters or fewer.'
  }

  if (!description.trim()) {
    errors.description = 'Enter a description.'
  } else if (description.trim().length > limits.description) {
    errors.description = 'The description must be 1,000 characters or fewer.'
  }

  if (!/^[A-Za-z]{3}$/.test(currency.trim())) {
    errors.currency = 'Use a 3-letter currency code, such as PHP.'
  }

  return errors
}

export function BusinessFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user, refresh } = useAuth()
  const businesses = useQuery({
    queryKey: ['companies', user?.id],
    enabled: editing && Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/companies')
      return (response.data.data as Array<{ id: string; attributes: Omit<Business, 'id'> }>).map(
        (resource) => readResource(resource),
      )
    },
  })
  const business = businesses.data?.find((item) => item.id === id)
  const currencyLocked = (business?.journals_count ?? 0) > 0
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [currency, setCurrency] = useState('PHP')
  const [filled, setFilled] = useState(!editing)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [pending, setPending] = useState(false)

  useEffect(() => {
    if (!business || filled) {
      return
    }

    setName(business.name)
    setDescription(business.description ?? '')
    setCurrency(business.currency)
    setFilled(true)
  }, [business, filled])

  if (!editing && user && user.role !== 'owner') {
    return <Navigate to="/businesses" replace />
  }

  if (editing && businesses.isSuccess && (!business || business.role !== 'owner')) {
    return <Navigate to="/businesses" replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(name, description, currency)
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const payload = {
        name: name.trim(),
        description: description.trim(),
        currency: currency.trim(),
      }
      if (editing) {
        await api.patch(`/api/v1/companies/${id}`, payload)
      } else {
        await api.post('/api/v1/companies', payload)
      }
      await refresh()
      await queryClient.invalidateQueries({
        predicate: (query) => query.queryKey[0] !== 'user',
      })
      navigate(editing ? '/businesses' : '/accounts')
    } catch (caught) {
      setErrors(fieldErrors(caught))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">Directory</p>
          <h1>{editing ? 'Edit business' : 'Add business'}</h1>
          <p className="hint">
            {editing
              ? 'The chart of accounts and journals stay with this business.'
              : 'The new books start with the default chart of accounts.'}
          </p>
        </div>
      </header>

      {editing && businesses.isLoading ? (
        <p className="hint">Loading business…</p>
      ) : (
        <form className="panel stack-form" noValidate onSubmit={(event) => void submit(event)}>
          <label>
            Name
            <input
              value={name}
              onChange={(event) => setName(event.target.value)}
              required
              maxLength={limits.name}
              aria-invalid={Boolean(errors.name)}
            />
            {errors.name && <span className="form-error">{errors.name}</span>}
          </label>
          <label>
            Description
            <textarea
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              required
              maxLength={limits.description}
              aria-invalid={Boolean(errors.description)}
            />
            {errors.description && <span className="form-error">{errors.description}</span>}
          </label>
          <label>
            Currency
            <input
              value={currency}
              onChange={(event) => setCurrency(event.target.value.toUpperCase())}
              required
              minLength={3}
              maxLength={3}
              pattern="[A-Za-z]{3}"
              readOnly={currencyLocked}
              aria-invalid={Boolean(errors.currency)}
            />
            {currencyLocked && (
              <span className="hint">Currency stays fixed once a journal exists.</span>
            )}
            {errors.currency && <span className="form-error">{errors.currency}</span>}
          </label>
          <div className="actions">
            <button type="submit" className="primary" disabled={pending || (editing && !filled)}>
              {pending ? 'Saving…' : editing ? 'Save' : 'Add business'}
            </button>
            <Link className="button-link" to="/businesses">
              Cancel
            </Link>
          </div>
        </form>
      )}
    </div>
  )
}
