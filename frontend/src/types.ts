export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense'

export type Account = {
  id: string
  code: string
  name: string
  type: AccountType
  is_active: boolean
}

export type JournalLine = {
  id: string
  account_id: string
  description: string | null
  debit: string
  credit: string
}

export type Journal = {
  id: string
  date: string
  reference: string | null
  memo: string | null
  currency: string
  status: 'draft' | 'posted'
  posted_at: string | null
  lines: JournalLine[]
}

export type Membership = {
  id: string
  name: string
  currency: string
  role: string
}

export type Session = {
  id: string
  name: string
  email: string
  role: string
  company: {
    id: string
    name: string
    currency: string
  }
  companies: Membership[]
}

export type TrialBalance = {
  data: Array<{
    account_id: string
    code: string
    name: string
    type: AccountType
    debit: string
    credit: string
  }>
  meta: {
    from: string
    to: string
    currency: string
    debit_total: string
    credit_total: string
    difference: string
  }
}

type Resource<T> = {
  id: string
  type?: string
  attributes: T
}

export function readResource<T>(resource: Resource<T>): T & { id: string } {
  return { id: resource.id, ...resource.attributes }
}

export function readJournal(payload: {
  data: Resource<Omit<Journal, 'id' | 'lines'>>
  included?: Array<Resource<Omit<JournalLine, 'id'>>>
}): Journal {
  const lines = (payload.included ?? [])
    .filter((item) => item.type === 'journal-lines')
    .map((item) => readResource(item))

  return { ...readResource(payload.data), lines }
}
