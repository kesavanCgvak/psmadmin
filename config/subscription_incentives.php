<?php

return [
  /*
  |--------------------------------------------------------------------------
  | Provider inventory trial incentive
  |--------------------------------------------------------------------------
  |
  | Base trial (2 months) is configured in subscription_plans.php.
  | Bonus months are granted when qualified marketplace inventory milestones
  | are reached, up to max_total_free_months from signup.
  |
  */
  'enabled' => env('SUBSCRIPTION_INCENTIVE_ENABLED', true),

  'max_total_free_months' => 9,

  'qualified' => [
    'require_product_id' => true,
    'require_rental_price' => true,
    'min_rental_price' => 0.01,
  ],

  'milestones' => [
    ['products' => 75, 'bonus_months' => 3],
    ['products' => 125, 'bonus_months' => 1],
    ['products' => 175, 'bonus_months' => 1],
    ['products' => 225, 'bonus_months' => 1],
    ['products' => 275, 'bonus_months' => 1],
  ],
];
