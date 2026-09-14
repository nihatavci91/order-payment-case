<?php

namespace App\Support;

class Money
{
    /**
     * Claude Code desteğiyle yazıldı: getter'larda kullanılan ortak para biçimlendirme.
     *
     * Amounts are stored as integer minor units (kuruş) to avoid float rounding.
     * This helper is for display only, e.g. 25000 => "250,00 TRY".
     */
    public static function format(int $amount, string $currency): string
    {
        return sprintf('%s,%02d %s', number_format(intdiv($amount, 100), 0, ',', '.'), $amount % 100, $currency);
    }
}
