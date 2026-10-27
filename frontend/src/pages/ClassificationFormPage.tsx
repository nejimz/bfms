import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { PictureField, appendPicture, choosePicture, emptyPicture, imageError, replacePicture, type Picture } from '../components/PictureField'
import { readResource, type Category, type Classification } from '../types'

const nameLimit = 255

function clientErrors(name: string, logo: Picture): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!name.trim()) {
    errors.name = 'Enter a classification name.'
  } else if (name.trim().length > nameLimit) {
    errors.name = 'The classification name must be 255 characters or fewer.'
  }

  const logoError = imageError(logo.file, 'logo')
  if (logoError) {
    errors.logo = logoError
  }

  return errors
}

export function ClassificationFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const classifications = useQuery({
    queryKey: ['classifications'],
    enabled: editing && Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/classifications')
      return (response.data.data as Array<{ id: string; attributes: Omit<Classification, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const classification = classifications.data?.find((item) => item.id === id)
  const categories = useQuery({
    queryKey: ['categories'],
    enabled: Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/categories')
      return (response.data.data as Array<{ id: string; attributes: Omit<Category, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const [name, setName] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [logo, setLogo] = useState<Picture>(emptyPicture)
  const [filled, setFilled] = useState(!editing)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  useEffect(() => {
    if (!classification || filled) {
      return
    }

    setName(classification.name)
    setCategoryId(classification.category_id ?? '')
    setFilled(true)
  }, [classification, filled])

  useEffect(() => {
    return () => {
      if (logo.previewUrl) {
        URL.revokeObjectURL(logo.previewUrl)
      }
    }
  }, [logo.previewUrl])

  if (user && user.role !== 'admin') {
    return <Navigate to="/classifications" replace />
  }

  if (editing && classifications.isSuccess && !classification) {
    return <Navigate to="/classifications" replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(name, logo)
    setErrors(nextErrors)
    setFormError(null)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const body = new FormData()
      body.append('name', name.trim())
      body.append('category_id', categoryId)
      appendPicture(body, 'logo', logo)
      if (editing) {
        body.append('_method', 'PATCH')
        await api.post(`/api/v1/classifications/${id}`, body)
        setLogo((current) => replacePicture(current, null))
      } else {
        await api.post('/api/v1/classifications', body)
      }
      await queryClient.invalidateQueries({ queryKey: ['classifications'] })
      await queryClient.invalidateQueries({ queryKey: ['categories'] })
      await queryClient.invalidateQueries({ queryKey: ['companies'] })
      navigate('/classifications')
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
    if (!id || !window.confirm(`Delete ${name.trim() || 'this classification'}?`)) {
      return
    }

    setPending(true)
    setFormError(null)

    try {
      await api.delete(`/api/v1/classifications/${id}`)
      await queryClient.invalidateQueries({ queryKey: ['classifications'] })
      await queryClient.invalidateQueries({ queryKey: ['categories'] })
      await queryClient.invalidateQueries({ queryKey: ['companies'] })
      navigate('/classifications')
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
          <h1>{editing ? 'Edit classification' : 'Add classification'}</h1>
          <p className="hint">A business can belong to one classification.</p>
        </div>
      </header>

      {editing && classifications.isLoading ? (
        <p className="hint">Loading classification…</p>
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
            Category
            <select
              value={categoryId}
              onChange={(event) => setCategoryId(event.target.value)}
              aria-invalid={Boolean(errors.category_id)}
            >
              <option value="">No category</option>
              {categories.data?.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
            {categories.isError && <span className="form-error">The categories could not be opened.</span>}
            {errors.category_id && <span className="form-error">{errors.category_id}</span>}
          </label>
          <PictureField
            label="Logo"
            noun="logo"
            hint="Shown on this form and in the classifications list. JPG, PNG, or WebP. 2 MB or smaller."
            error={errors.logo}
            savedUrl={classification?.logo_url ?? null}
            picture={logo}
            onChoose={(file) => choosePicture(setLogo, file)}
            onRemove={(remove) => setLogo((current) => ({ ...current, remove }))}
            previewClass="logo-preview"
          />
          {editing && (classification?.businesses_count ?? 0) > 0 && !formError && (
            <p className="hint">Remove this classification from its businesses before deleting it.</p>
          )}
          {formError && <p className="form-error">{formError}</p>}
          <div className="actions">
            <button type="submit" className="primary" disabled={pending || (editing && !filled)}>
              {pending ? 'Saving…' : editing ? 'Save' : 'Add classification'}
            </button>
            {editing && (
              <button type="button" disabled={pending} onClick={() => void remove()}>
                Delete
              </button>
            )}
            <Link className="button-link" to="/classifications">
              Cancel
            </Link>
          </div>
        </form>
      )}
    </div>
  )
}
