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

    public static function fromCsv(string $path): self
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

        return new self($map);
    }

    public function industryOf(string $account): string
    {
        return $this->map[$account] ?? Industries::OTHER;
    }
}
