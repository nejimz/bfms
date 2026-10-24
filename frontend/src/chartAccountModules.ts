export const chartAccountModules = {
  'purchase-expense': {
    key: 'purchase-expense',
    title: 'Purchase Expense',
    accountCode: '5100',
    partyLabel: 'Payee name / supplier',
    partyPlaceholder: 'Who was paid?',
    itemLabel: 'Item purchased',
    itemPlaceholder: 'What was bought?',
    path: '/purchase-expense',
  },
  'operation-expense': {
    key: 'operation-expense',
    title: 'Operation Expenses',
    accountCode: '5000',
    partyLabel: 'Payee name',
    partyPlaceholder: 'Who was paid?',
    itemLabel: 'Item',
    itemPlaceholder: 'What was the cost for?',
    path: '/operation-expense',
  },
  sales: {
    key: 'sales',
    title: 'Sales',
    accountCode: '4000',
    partyLabel: 'Customer',
    partyPlaceholder: 'Who paid?',
    itemLabel: 'Item sold',
    itemPlaceholder: 'What was sold?',
    path: '/sales',
  },
} as const

export type ChartAccountModuleKey = keyof typeof chartAccountModules

export type ChartAccountModule = (typeof chartAccountModules)[ChartAccountModuleKey]

export const chartAccountModuleList: ChartAccountModule[] = Object.values(chartAccountModules)

export function chartAccountModule(key: ChartAccountModuleKey): ChartAccountModule {
  return chartAccountModules[key]
}
