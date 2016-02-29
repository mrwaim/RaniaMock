<?php

namespace Klsandbox\RaniaMock\Services;

use App\Services\UserManager;
use Klsandbox\NotificationService\Models\NotificationRequest;
use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;
use Klsandbox\OrderModel\Models\OrderStatus;
use App\Models\User;
use Carbon\Carbon;
use Auth;
use Klsandbox\OrderModel\Services\OrderManager;
use Klsandbox\SiteModel\Site;
use Log;

class RaniaOrderManager implements OrderManager
{
    protected $bonusManager;
    protected $userManager;

    public function __construct(BonusManager $bonusManager, UserManager $userManager)
    {
        $this->bonusManager = $bonusManager;
        $this->userManager = $userManager;
    }

    public function approveOrder(Order $order, $approved_at = null)
    {
        assert(
            $order->order_status_id == OrderStatus::NewOrderStatus()->id || $order->order_status_id == OrderStatus::FirstOrder()->id || $order->order_status_id == OrderStatus::PaymentUploaded()->id, "Invalid Order to approve $order->id - Status {$order->orderStatus->name}");

        Site::protect($order, "Order");

        if ($order->order_status_id == OrderStatus::FirstOrder()->id) {
            Site::protect($order->user, "User");

            $this->userManager->approveNewMember($order->user);
        }

        $order->order_status_id = OrderStatus::Approved()->id;

        if (!$approved_at) {
            $approved_at = new Carbon();
        }

        $order->approved_at = $approved_at;
        $order->approved_by_id = Auth::user()->id;
        $order->save();

        $approveId = (Auth::user() ? Auth::user()->id : 0);
        Log::info("Order approved:$order->id by:" . $approveId);

        User::createUserEvent($order->user, ['created_at' => $approved_at, 'controller' => 'timeline', 'route' => '/order-approved', 'target_id' => $order->id, 'parameter_id' => $approveId]);
        NotificationRequest::create(['target_id' => $order->id, 'route' => 'order-approved', 'channel' => 'Sms', 'to_user_id' => $order->user->id]);

        $this->bonusManager->resolveBonus($order);
    }

    public function rejectOrder(Order $order)
    {
        assert(
            $order->order_status_id == OrderStatus::NewOrderStatus()->id || $order->order_status_id == OrderStatus::FirstOrder()->id || $order->order_status_id == OrderStatus::PaymentUploaded()->id, "Invalid Order to approve $order->id - Status {$order->orderStatus->name}");

        Site::protect($order, "Order");

        if ($order->order_status_id == OrderStatus::FirstOrder()->id) {
            Site::protect($order->user, "User");
            $this->userManager->rejectNewMember($order->user);
        }

        $order->rejected_at = new Carbon();
        $order->rejected_by_id = Auth::user()->id;
        $order->save();

        $order->order_status_id = OrderStatus::Rejected()->id;
        $order->save();

        Log::info("Order rejected:$order->id by:" . Auth::user()->id);

        User::createUserEvent($order->user, ['controller' => 'timeline', 'route' => '/order-rejected', 'target_id' => $order->id, 'parameter_id' => Auth::user()->id]);
        NotificationRequest::create(['target_id' => $order->id, 'route' => 'order-rejected', 'channel' => 'Sms', 'to_user_id' => $order->user->id]);
    }

    public function shipOrder(Order $order, $trackingId)
    {
        $order->tracking_id = $trackingId;
        $order->order_status_id = OrderStatus::Shipped()->id;
        $order->save();

        User::createUserEvent($order->user, ['controller' => 'timeline', 'route' => '/order-shipped', 'target_id' => $order->id, 'parameter_id' => Auth::user()->id]);
        NotificationRequest::create(['target_id' => $order->id, 'route' => 'order-shipped', 'channel' => 'Sms', 'to_user_id' => $order->user->id]);
    }

    public function orderCreated(Order $order) {
        Log::info("created\t#order:$order->id user:{$order->user->id} status:{$order->orderStatus->name}");

        NotificationRequest::create(['target_id' => $order->id, 'route' => 'new-order', 'channel' => 'Sms', 'to_user_id' => User::admin()->id]);

        NotificationRequest::create(['target_id' => $order->id, 'route' => 'new-downlevel-order', 'channel' => 'Sms', 'to_user_id' => $order->user->referral_id]);

        User::createUserEvent($order->user, ['created_at' => $order->created_at, 'controller' => 'timeline', 'route' => '/new-order', 'target_id' => $order->id]);
    }
}
