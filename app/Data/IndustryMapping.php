<?php

namespace App\Data;

use InvalidArgumentException;

final class IndustryMapping
{
    /** @param array<string, string> $map account id => industry */
    public function __construct(private readonly array $map)
    {
        foreach ($map as $account => $industry) {
            if (! in_array($industry, Industries::ALL, true)) {
                throw new InvalidArgumentException("Unknown industry \"{$industry}\" for account \"{$account}\" in the mapping file.");
            }
        }
    }

    /**
     * @param  array<string, string>  $extra  additional account id => industry
     *      pairs merged on top of the CSV (e.g. database-registered AdsAccount
     *      rows, whose industry is already known directly from their own
     *      column, not from this file - see AppServiceProvider). Keys can't
     *      collide with the CSV's "real-account-N" ids by construction
     *      (AdsAccount::internalId() always returns "db-account-{id}").
     */
    public static function fromCsv(string $path, array $extra = []): self
    {
        $map = [];
        $handle = fopen($path, 'r');
        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) >= 2 && trim($row[0]) !== '') {
                $map[trim($row[0])] = trim($row[1]);
            }
        }
        fclose($handle);

        return new self([...$map, ...$extra]);
    }

    public function industryOf(string $account): string
    {
        return $this->map[$account] ?? Industries::OTHER;
    }
}
