import axios from 'axios'

export const api = axios.create({
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
  },
})

export async function ensureCsrf(): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
}

type ErrorBody = { message?: string; errors?: Record<string, string[]> }

function errorBody(error: unknown): ErrorBody | undefined {
  if (!axios.isAxiosError(error)) {
    return undefined
  }

  return error.response?.data as ErrorBody | undefined
}

export function fieldErrors(error: unknown): Record<string, string> {
  const errors = errorBody(error)?.errors
  if (!errors) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(errors).map(([key, messages]) => [key, messages[0] ?? 'This value is invalid.']),
  )
}

export function isUnauthorized(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 401
}

export function errorMessage(error: unknown): string {
  const data = errorBody(error)
  const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined

  return first || data?.message || 'Something went wrong. Try again.'
}
