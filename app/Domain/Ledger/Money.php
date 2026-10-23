<?php

namespace App\Domain\Ledger;

/**
 * An amount in minor units. Philippine pesos use 2 decimal places (centavos).
 */
final class Money
{
    public const SCALE = 2;

    public function __construct(public readonly int $minor) {}

    public static function fromDecimal(string $amount): self
    {
        $amount = trim($amount);

        if (str_starts_with($amount, '.')) {
            $amount = '0'.$amount;
        }

        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidMoneyException('Enter an amount with up to 2 decimal places.');
        }

        $minor = bcmul($amount, '100', 0);

        if (! is_string($minor) || ! preg_match('/^\d+$/', $minor) || strlen(ltrim($minor, '0')) > 15) {
            throw new InvalidMoneyException('That amount is too large.');
        }

        return new self((int) $minor);
    }

    public static function ofMinor(int $minor): self
    {
        return new self($minor);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function toDecimal(): string
    {
        $negative = $this->minor < 0;
        $absolute = str_pad((string) abs($this->minor), 3, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($absolute, 0, -2).'.'.substr($absolute, -2);
    }

    public function plus(self $other): self
    {
        $sum = $this->minor + $other->minor;

        if (! is_int($sum)) {
            throw new InvalidMoneyException('That amount is too large.');
        }

        return new self($sum);
    }
}
