import { useState, type FormEvent } from 'react'
import { api, errorMessage, fieldErrors } from '../api'
import { useAuth } from '../auth'

function clientErrors(currentPassword: string, password: string, confirmation: string): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!currentPassword) {
    errors.current_password = 'Enter your current password.'
  }

  if (!password) {
    errors.password = 'Enter a new password.'
  } else if (password.length < 8) {
    errors.password = 'The password must be at least 8 characters.'
  } else if (currentPassword && password === currentPassword) {
    errors.password = 'Choose a password that is different from the current one.'
  } else if (password !== confirmation) {
    errors.password = 'The password confirmation does not match.'
  }

  return errors
}

export function PasswordPage() {
  const { user } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [pending, setPending] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    const nextErrors = clientErrors(currentPassword, password, confirmation)
    setErrors(nextErrors)
    setFormError(null)
    setSaved(false)
    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setPending(true)

    try {
      await api.patch('/api/v1/user/password', {
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      })
      setCurrentPassword('')
      setPassword('')
      setConfirmation('')
      setSaved(true)
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

  return (
    <div className="page">
      <header className="page-header">
        <div>
          <p className="eyebrow">{user?.name}</p>
          <h1>Password</h1>
          <p className="hint">Enter your current password, then choose a new one.</p>
        </div>
      </header>

      <form className="panel stack-form" noValidate onSubmit={(event) => void submit(event)}>
        <label>
          Current password
          <input
            type="password"
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
            autoComplete="current-password"
            aria-invalid={Boolean(errors.current_password)}
          />
          {errors.current_password && <span className="form-error">{errors.current_password}</span>}
        </label>
        <label>
          New password
          <input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="new-password"
            aria-invalid={Boolean(errors.password)}
          />
          {errors.password && <span className="form-error">{errors.password}</span>}
        </label>
        <label>
          Confirm new password
          <input
            type="password"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            autoComplete="new-password"
            aria-invalid={Boolean(errors.password_confirmation)}
          />
          {errors.password_confirmation && <span className="form-error">{errors.password_confirmation}</span>}
        </label>
        {formError && <p className="form-error">{formError}</p>}
        {saved && <p className="hint">Your password has been changed.</p>}
        <div className="actions">
          <button type="submit" className="primary" disabled={pending}>
            {pending ? 'Saving…' : 'Change password'}
          </button>
        </div>
      </form>
    </div>
  )
}
