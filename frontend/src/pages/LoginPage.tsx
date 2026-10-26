import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { api, ensureCsrf, errorMessage } from '../api'
import { useAuth } from '../auth'
import { OrganizationMark } from '../organization'
import { homePath } from '../types'

export function LoginPage() {
  const { user, isLoading, refresh } = useAuth()
  const [email, setEmail] = useState('owner@bfms.test')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  if (!isLoading && user) {
    return <Navigate to={homePath(user.role)} replace />
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setPending(true)
    setError(null)

    try {
      await ensureCsrf()
      await api.post('/session/login', { email, password })
      await refresh()
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="login">
      <section className="login-copy">
        <OrganizationMark tone="page" />
        <h1>Books that stay in balance.</h1>
        <p>
          Record journals in Philippine pesos. A draft can be unfinished. A posted entry
          has equal debits and credits, and it stays on the ledger.
        </p>
      </section>
      <form className="panel login-form" onSubmit={(event) => void submit(event)}>
        <h2>Sign in</h2>
        <label>
          Email
          <input
            type="email"
            autoComplete="username"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
          />
        </label>
        <label>
          Password
          <input
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
          />
        </label>
        {error && <p className="form-error">{error}</p>}
        <button type="submit" className="primary" disabled={pending}>
          {pending ? 'Signing in…' : 'Sign in'}
        </button>
        <p className="hint">Demo admin: owner@bfms.test / password</p>
      </form>
    </div>
  )
}
