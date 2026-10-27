import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { readResource, type Category } from '../types'

const nameLimit = 255

function clientErrors(name: string): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!name.trim()) {
    errors.name = 'Enter a category name.'
  } else if (name.trim().length > nameLimit) {
    errors.name = 'The category name must be 255 characters or fewer.'
  }

  return errors
}

export function CategoryFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const categories = useQuery({
    queryKey: ['categories'],
    enabled: editing && Boolean(user),
    queryFn: async () => {
      const response = await api.get('/api/v1/categories')
      return (response.data.data as Array<{ id: string; attributes: Omit<Category, 'id'> }>).map((resource) =>
        readResource(resource),
      )
    },
  })
  const category = categories.data?.find((item) => item.id === id)
  const [name, setName] = useState('')
  const [filled, setFilled] = useState(!editing)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  useEffect(() => {
    if (!category || filled) {
      return
    }

    setName(category.name)
    setFilled(true)
  }, [category, filled])

  if (user && user.role !== 'admin') {
    return <Navigate to="/categories" replace />
  }

  if (editing && categories.isSuccess && !category) {
    return <Navigate to="/categories" replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(name)
    setErrors(nextErrors)
    setFormError(null)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      const payload = { name: name.trim() }
      if (editing) {
        await api.patch(`/api/v1/categories/${id}`, payload)
      } else {
        await api.post('/api/v1/categories', payload)
      }
      await queryClient.invalidateQueries({ queryKey: ['categories'] })
      await queryClient.invalidateQueries({ queryKey: ['classifications'] })
      navigate('/categories')
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
    if (!id || !window.confirm(`Delete ${name.trim() || 'this category'}?`)) {
      return
    }

    setPending(true)
    setFormError(null)

    try {
      await api.delete(`/api/v1/categories/${id}`)
      await queryClient.invalidateQueries({ queryKey: ['categories'] })
      await queryClient.invalidateQueries({ queryKey: ['classifications'] })
      navigate('/categories')
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
          <h1>{editing ? 'Edit category' : 'Add category'}</h1>
          <p className="hint">A classification can belong to one category.</p>
        </div>
      </header>

      {editing && categories.isLoading ? (
        <p className="hint">Loading category…</p>
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
          {editing && (category?.classifications_count ?? 0) > 0 && !formError && (
            <p className="hint">Remove this category from its classifications before deleting it.</p>
          )}
          {formError && <p className="form-error">{formError}</p>}
          <div className="actions">
            <button type="submit" className="primary" disabled={pending || (editing && !filled)}>
              {pending ? 'Saving…' : editing ? 'Save' : 'Add category'}
            </button>
            {editing && (
              <button type="button" disabled={pending} onClick={() => void remove()}>
                Delete
              </button>
            )}
            <Link className="button-link" to="/categories">
              Cancel
            </Link>
          </div>
        </form>
      )}
    </div>
  )
}
