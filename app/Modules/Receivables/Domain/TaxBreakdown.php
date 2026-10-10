<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Domain;

/**
 * Ghana levy breakdown on a net amount (minor units).
 */
final class TaxBreakdown
{
    public function __construct(
        public readonly int $netMinor,
        public readonly int $vatMinor,
        public readonly int $nhilMinor,
        public readonly int $getfundMinor,
        public readonly int $covidMinor,
    ) {
    }

    public function taxTotal(): int
    {
        return $this->vatMinor + $this->nhilMinor + $this->getfundMinor + $this->covidMinor;
    }

    public function grossMinor(): int
    {
        return $this->netMinor + $this->taxTotal();
    }

    /**
     * Compute tax from rates (percentages as decimals, e.g. 0.15 for 15%).
     * Rates default to common Ghana values when rule not found.
     *
     * @param  array{vat?: float, nhil?: float, getfund?: float, covid?: float}  $rates
     */
    public static function fromNet(int $netMinor, array $rates = []): self
    {
        $vatRate = (float) ($rates['vat'] ?? 0.15);
        $nhilRate = (float) ($rates['nhil'] ?? 0.025);
        $getfundRate = (float) ($rates['getfund'] ?? 0.025);
        $covidRate = (float) ($rates['covid'] ?? 0.01);

        return new self(
            netMinor: $netMinor,
            vatMinor: (int) round($netMinor * $vatRate),
            nhilMinor: (int) round($netMinor * $nhilRate),
            getfundMinor: (int) round($netMinor * $getfundRate),
            covidMinor: (int) round($netMinor * $covidRate),
        );
    }
}
