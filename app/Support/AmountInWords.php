<?php

namespace App\Support;

/**
 * An amount of rupees in words, on the Indian system (thousand, lakh, crore),
 * for the line "Amount in words" on a printed invoice. 1,25,000.50 reads
 * "Rupees One Lakh Twenty Five Thousand and Fifty Paise Only".
 */
class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(float|int|string $amount): string
    {
        $paise = (int) round(((float) $amount) * 100);
        $negative = $paise < 0;
        $paise = abs($paise);
        $rupees = intdiv($paise, 100);
        $rest = $paise % 100;

        $words = 'Rupees '.($rupees === 0 ? 'Zero' : self::number($rupees));
        if ($rest > 0) {
            $words .= ' and '.self::number($rest).' Paise';
        }

        return ($negative ? 'Minus ' : '').$words.' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$size, $name]) {
            if ($n >= $size) {
                $parts[] = self::number(intdiv($n, $size)).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : self::TENS[intdiv($n, 10)].($n % 10 > 0 ? ' '.self::ONES[$n % 10] : '');
        }

        return implode(' ', $parts);
    }
}
