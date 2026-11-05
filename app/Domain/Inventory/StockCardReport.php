<?php

namespace App\Domain\Inventory;

use App\Enums\ChartAccountEntryStatus;
use App\Models\ChartAccountEntryLine;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Item;

class StockCardReport
{
    private const PURCHASE_CODE = '5100';

    private const SALES_CODE = '4000';

    /**
     * Posted purchases and sales from every business, the starting quantity, and adjustments.
     * Remaining is the running balance for that item.
     *
     * @return array{
     *     data: list<array{
     *         id: string,
     *         date: string,
     *         item_id: string,
     *         item_name: string,
     *         unit: string,
     *         purchase_quantity: string|null,
     *         sold_quantity: string|null,
     *         adjust_quantity: string|null,
     *         remaining_quantity: string,
     *         source: string,
     *         adjustment_id: string|null
     *     }>
     * }
     */
    public function build(?int $itemId): array
    {
        $movements = $this->movements($itemId);

        usort($movements, function (array $left, array $right): int {
            return [$left['item_name'], $left['date'], $left['rank'], $left['sort_id']]
                <=> [$right['item_name'], $right['date'], $right['rank'], $right['sort_id']];
        });

        /** @var array<int, string> $balances */
        $balances = [];
        $rows = [];

        foreach ($movements as $movement) {
            $itemKey = $movement['item_id'];
            $balances[$itemKey] = $this->apply($balances[$itemKey] ?? '0.000', $movement['kind'], $movement['quantity']);
            $label = InventoryAdjustment::label($movement['quantity']);
            $rows[] = [
                'id' => $movement['kind'].'-'.$movement['sort_id'],
                'date' => $movement['date'],
                'item_id' => (string) $movement['item_id'],
                'item_name' => $movement['item_name'],
                'unit' => $movement['unit'],
                'purchase_quantity' => $movement['kind'] === 'purchase' ? $label : null,
                'sold_quantity' => $movement['kind'] === 'sale' ? $label : null,
                'adjust_quantity' => in_array($movement['kind'], ['opening', 'adjustment'], true) ? $label : null,
                'remaining_quantity' => InventoryAdjustment::label($balances[$itemKey]),
                'source' => $movement['kind'],
                'adjustment_id' => $movement['kind'] === 'adjustment' ? (string) $movement['sort_id'] : null,
            ];
        }

        return ['data' => $rows];
    }

    /**
     * @return list<array{kind: string, rank: int, sort_id: int, date: string, item_id: int, item_name: string, unit: string, quantity: string}>
     */
    private function movements(?int $itemId): array
    {
        $movements = [];

        $inventories = Inventory::query()
            ->with('item')
            ->when($itemId !== null, fn ($query) => $query->where('item_id', $itemId))
            ->get();

        foreach ($inventories as $inventory) {
            if (! $inventory->item instanceof Item || $inventory->date === null) {
                continue;
            }

            $movements[] = $this->movement(
                'opening',
                0,
                $inventory->id,
                $inventory->date->toDateString(),
                $inventory->item,
                (string) $inventory->quantity,
            );
        }

        $adjustments = InventoryAdjustment::query()
            ->with('item')
            ->when($itemId !== null, fn ($query) => $query->where('item_id', $itemId))
            ->get();

        foreach ($adjustments as $adjustment) {
            if (! $adjustment->item instanceof Item) {
                continue;
            }

            $movements[] = $this->movement(
                'adjustment',
                3,
                $adjustment->id,
                $adjustment->date->toDateString(),
                $adjustment->item,
                (string) $adjustment->quantity,
            );
        }

        $lines = ChartAccountEntryLine::query()
            ->withoutGlobalScope('company')
            ->join('chart_account_entries', 'chart_account_entries.id', '=', 'chart_account_entry_lines.chart_account_entry_id')
            ->join('accounts', 'accounts.id', '=', 'chart_account_entries.account_id')
            ->join('items', 'items.id', '=', 'chart_account_entry_lines.item_id')
            ->where('chart_account_entries.status', ChartAccountEntryStatus::Posted->value)
            ->whereIn('accounts.code', [self::PURCHASE_CODE, self::SALES_CODE])
            ->whereNotNull('chart_account_entry_lines.item_id')
            ->when($itemId !== null, fn ($query) => $query->where('chart_account_entry_lines.item_id', $itemId))
            ->orderBy('chart_account_entry_lines.id')
            ->toBase()
            ->get([
                'chart_account_entry_lines.id as id',
                'chart_account_entry_lines.item_id as item_id',
                'chart_account_entry_lines.quantity as quantity',
                'chart_account_entries.date as date',
                'accounts.code as code',
                'items.name as item_name',
                'items.unit as unit',
            ]);

        foreach ($lines as $line) {
            $purchase = (string) $line->code === self::PURCHASE_CODE;

            $movements[] = [
                'kind' => $purchase ? 'purchase' : 'sale',
                'rank' => $purchase ? 1 : 2,
                'sort_id' => (int) $line->id,
                'date' => substr((string) $line->date, 0, 10),
                'item_id' => (int) $line->item_id,
                'item_name' => (string) $line->item_name,
                'unit' => (string) $line->unit,
                'quantity' => (string) $line->quantity,
            ];
        }

        return $movements;
    }

    /**
     * @return array{kind: string, rank: int, sort_id: int, date: string, item_id: int, item_name: string, unit: string, quantity: string}
     */
    private function movement(string $kind, int $rank, int $id, string $date, Item $item, string $quantity): array
    {
        return [
            'kind' => $kind,
            'rank' => $rank,
            'sort_id' => $id,
            'date' => $date,
            'item_id' => $item->id,
            'item_name' => $item->name,
            'unit' => $item->unit,
            'quantity' => $quantity,
        ];
    }

    private function apply(string $balance, string $kind, string $quantity): string
    {
        if ($kind === 'sale') {
            return bcsub($balance, $quantity, 3);
        }

        return bcadd($balance, $quantity, 3);
    }
}
