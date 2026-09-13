<?php

return [
    'stock_reservation_ttl_minutes' => (int)env(
        'STOCK_RESERVATION_TTL_MINUTES',
        15
    ),
];
