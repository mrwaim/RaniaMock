<?php

namespace Klsandbox\RaniaMock\Services;

use App\Services\ProductPricingManager\ProductPricingManagerInterface;
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
    protected $membershipManager;

    public function __construct(BonusManager $bonusManager, UserManager $userManager, ProductPricingManagerInterface $productPricingManager, MembershipManager $membershipManager)
    {
        parent::__construct($userManager, $productPricingManager);
        $this->bonusManager = $bonusManager;
        $this->membershipManager = $membershipManager;
    }

    public function approveOrder(Order $order, $approved_at = null)
    {
        $return = null;
        \DB::transaction(function () use ($return, $order, $approved_at) {
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
        });

        return $return;
    }
}
