<?php

namespace Klsandbox\RaniaMock\Services;

use App\Models\User;
use App\Services\OrderItemsUnitManager;
use App\Services\UserManager;
use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;
use App\Services\MembershipManager\MembershipManagerInterface as MembershipManager;
use App\Services\ProductManager\ProductManagerInterface;
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

    public function __construct(BonusManager $bonusManager, UserManager $userManager, ProductManagerInterface $productManager, MembershipManager $membershipManager)
    {
        parent::__construct($userManager, $productManager);
        $this->bonusManager = $bonusManager;
        $this->membershipManager = $membershipManager;
    }

    public function approveOrder(User $user, Order $order, $approved_at = null)
    {
        $return = null;
        \DB::transaction(function () use ($return, $user, $order, $approved_at) {
            $return = parent::approveOrder($user, $order, $approved_at);

            foreach ($order->orderItems as $orderItem) {
                assert($orderItem->product);

                if ($this->debug) {
                    Log::debug('processing-order ' . $orderItem->product->name);
                }

                $this->membershipManager->processOrderItem($user, $orderItem);
                if ($orderItem->product->bonusCategory) {
                    $this->bonusManager->resolveBonus($orderItem);
                } else {
                    if ($this->debug) {
                        \Log::debug('order-item no-bonus-category');
                    }
                }

                //record order quantity
                OrderItemsUnitManager::create($orderItem);
            }
        });

        return $return;
    }
}
