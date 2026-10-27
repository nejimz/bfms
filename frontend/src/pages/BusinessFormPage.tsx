import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { PictureField, appendPicture, choosePicture, emptyPicture, imageError, replacePicture, type Picture } from '../components/PictureField'
import type { Business, Classification, Location } from '../types'
import { readResource } from '../types'

const limits = { name: 255, description: 1000 }

function clientErrors(name: string, description: string, currency: string, logo: Picture): Record<string, string> {
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

  const logoError = imageError(logo.file, 'logo')
  if (logoError) {
    errors.logo = logoError
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
  const classifications = useQuery({
    queryKey: ['classifications'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/classifications')
      return (response.data.data as Array<{ id: string; attributes: Omit<Classification, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [classificationId, setClassificationId] = useState('')
  const [locationId, setLocationId] = useState('')
  const [currency, setCurrency] = useState('PHP')
  const [logo, setLogo] = useState<Picture>(emptyPicture)
  const [filled, setFilled] = useState(!editing)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [pending, setPending] = useState(false)
  const locations = useQuery({
    queryKey: ['locations'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/locations')
      return (response.data.data as Array<{ id: string; attributes: Omit<Location, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })

  useEffect(() => {
    if (!business || filled) {
      return
    }

    setName(business.name)
    setDescription(business.description ?? '')
    setClassificationId(business.classification_id ?? '')
    setLocationId(business.location_id ?? '')
    setCurrency(business.currency)
    setFilled(true)
  }, [business, filled])

  useEffect(() => {
    return () => {
      if (logo.previewUrl) {
        URL.revokeObjectURL(logo.previewUrl)
      }
    }
  }, [logo.previewUrl])

  if (user && user.role !== 'admin') {
    return <Navigate to="/businesses" replace />
  }

  if (editing && businesses.isSuccess && !business) {
    return <Navigate to="/businesses" replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(name, description, currency, logo)
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const body = new FormData()
      body.append('name', name.trim())
      body.append('description', description.trim())
      body.append('currency', currency.trim())
      body.append('classification_id', classificationId)
      body.append('location_id', locationId)
      appendPicture(body, 'logo', logo)
      if (editing) {
        body.append('_method', 'PATCH')
        await api.post(`/api/v1/companies/${id}`, body)
        setLogo((current) => replacePicture(current, null))
      } else {
        await api.post('/api/v1/companies', body)
      }
      await refresh()
      await queryClient.invalidateQueries({
        predicate: (query) => query.queryKey[0] !== 'user',
      })
      navigate('/businesses')
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
            Classification
            <select
              value={classificationId}
              onChange={(event) => setClassificationId(event.target.value)}
              aria-invalid={Boolean(errors.classification_id)}
            >
              <option value="">No classification</option>
              {classifications.data?.map((classification) => (
                <option key={classification.id} value={classification.id}>
                  {classification.name}
                </option>
              ))}
            </select>
            {classifications.isError && <span className="form-error">The classifications could not be opened.</span>}
            {errors.classification_id && <span className="form-error">{errors.classification_id}</span>}
          </label>
          <label>
            Location
            <select
              value={locationId}
              onChange={(event) => setLocationId(event.target.value)}
              aria-invalid={Boolean(errors.location_id)}
            >
              <option value="">No location</option>
              {locations.data?.map((location) => (
                <option key={location.id} value={location.id}>
                  {location.name}
                </option>
              ))}
            </select>
            {locations.isError && <span className="form-error">The locations could not be opened.</span>}
            {errors.location_id && <span className="form-error">{errors.location_id}</span>}
          </label>
          <PictureField
            label="Logo"
            noun="logo"
            hint="Shown on this form and in the businesses list. JPG, PNG, or WebP. 2 MB or smaller."
            error={errors.logo}
            savedUrl={business?.logo_url ?? null}
            picture={logo}
            onChoose={(file) => choosePicture(setLogo, file)}
            onRemove={(remove) => setLogo((current) => ({ ...current, remove }))}
            previewClass="logo-preview"
          />
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
