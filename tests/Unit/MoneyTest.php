<?php

use App\Domain\Ledger\InvalidMoneyException;
use App\Domain\Ledger\Money;

it('converts peso strings into centavos', function () {
    expect(Money::fromDecimal('10.50')->minor)->toBe(1050)
        ->and(Money::fromDecimal('10.5')->toDecimal())->toBe('10.50')
        ->and(Money::fromDecimal('.5')->toDecimal())->toBe('0.50')
        ->and(Money::ofMinor(1050)->toDecimal())->toBe('10.50');
});

it('rejects amounts that are not centavos', function (string $amount) {
    expect(fn () => Money::fromDecimal($amount))->toThrow(InvalidMoneyException::class);
})->with([
    '10.555',
    '-1.00',
    '1,000.00',
    'abc',
    '',
]);
