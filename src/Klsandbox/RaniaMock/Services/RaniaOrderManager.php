<?php

namespace Klsandbox\RaniaMock\Services;

use App\Services\UserManager;
use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;
use App\Services\MembershipManager\MembershipManagerInterface as MembershipManager;
use Log;

class RaniaOrderManager extends  RaniaOrderManagerWithNoBonus
{
    protected $debug = true;

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

        foreach ($order->orderItems as $orderItem) {
            if ($this->debug) {
                Log::debug('processing-order ' . $orderItem->productPricing->product->name);
            }

            $this->membershipManager->processOrderItem($orderItem);
            if ($orderItem->productPricing->product->bonusCategory) {
                $this->bonusManager->resolveBonus($orderItem);
            } else {
                if ($this->debug) {
                    \Log::debug('order-item no-bonus-category');
                }
            }
        }

        return $return;
    }
}
