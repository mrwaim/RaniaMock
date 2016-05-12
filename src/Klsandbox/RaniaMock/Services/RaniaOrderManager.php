<?php

namespace Klsandbox\RaniaMock\Services;

use App\Services\UserManager;
use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;

class RaniaOrderManager extends  RaniaOrderManagerWithNoBonus
{
    /**
     * @var BonusManager $bonusManager
     */
    protected $bonusManager;

    public function __construct(BonusManager $bonusManager, UserManager $userManager)
    {
        $this->bonusManager = $bonusManager;
        parent::__construct($userManager);
    }

    public function approveOrder(Order $order, $approved_at = null)
    {
        $return = parent::approveOrder($order, $approved_at);

        foreach ($order->orderItems as $orderItem)
        {
            if ($orderItem->productPricing->product->bonusCategory)
            {
                $this->bonusManager->resolveBonus($orderItem);
            }
        }

        return $return;
    }
}
