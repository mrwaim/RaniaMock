<?php

namespace Klsandbox\RaniaMock\Services;

use App\Models\Group;
use App\Models\User;
use App\Services\UserManager;
use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;
use App\Services\MembershipManager\MembershipManagerInterface as MembershipManager;
use Klsandbox\OrderModel\Models\OrderItem;
use Klsandbox\OrderModel\Models\Product;

class RaniaOrderManager extends  RaniaOrderManagerWithNoBonus
{
    /**
     * @var BonusManager $bonusManager
     */
    protected $bonusManager;
    /**
     * @var MembershipManager
     */
    private $membershipManager;

    public function __construct(BonusManager $bonusManager, UserManager $userManager, MembershipManager $membershipManager)
    {
        $this->bonusManager = $bonusManager;
        parent::__construct($userManager);
        $this->membershipManager = $membershipManager;
    }

    public function approveOrder(Order $order, $approved_at = null)
    {
        $return = parent::approveOrder($order, $approved_at);

        foreach ($order->orderItems as $orderItem)
        {
            $this->membershipManager->processOrderItem($orderItem);
            if ($orderItem->productPricing->product->bonusCategory)
            {
                $this->bonusManager->resolveBonus($orderItem);
            }
        }

        return $return;
    }

}
