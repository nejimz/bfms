import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { api } from './api'
import type { Session } from './types'
import { readResource } from './types'

type AuthContextValue = {
  user: Session | null
  isLoading: boolean
  refresh: () => Promise<void>
  selectCompany: (companyId: string) => Promise<void>
  clear: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: ['user'],
    retry: false,
    queryFn: async () => {
      const response = await api.get('/api/v1/user')
      const resource = response.data.data
      const attributes = readResource<{
        name: string
        email: string
        role: string
        company: Session['company']
        companies: Session['companies']
      }>(resource)

      return { ...attributes, id: resource.id }
    },
  })

  const value = useMemo<AuthContextValue>(
    () => ({
      user: query.data ?? null,
      isLoading: query.isLoading,
      refresh: async () => {
        await queryClient.invalidateQueries({ queryKey: ['user'] })
      },
      selectCompany: async (companyId: string) => {
        await api.post('/api/v1/current-company', { company_id: companyId })
        await queryClient.invalidateQueries({ queryKey: ['user'] })
        await queryClient.invalidateQueries({
          predicate: (query) => query.queryKey[0] !== 'user',
        })
      },
      clear: () => {
        // Removing the cache entry leaves this mounted query showing the previous user.
        queryClient.setQueryData(['user'], null)
        queryClient.removeQueries({
          predicate: (query) => query.queryKey[0] !== 'user',
        })
      },
    }),
    [query.data, query.isLoading, queryClient],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (!value) {
    throw new Error('useAuth must be used inside AuthProvider')
  }

  return value
}
