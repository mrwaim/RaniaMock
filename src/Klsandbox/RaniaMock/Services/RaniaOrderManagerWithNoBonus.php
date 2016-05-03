<?php

namespace Klsandbox\RaniaMock\Services;

use App\Services\UserManager;
use Klsandbox\NotificationService\Models\NotificationRequest;
//use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;
use Klsandbox\OrderModel\Models\OrderItem;
use Klsandbox\OrderModel\Models\OrderStatus;
use App\Models\User;
use Carbon\Carbon;
use Auth;
use Klsandbox\OrderModel\Models\ProductPricing;
use Klsandbox\OrderModel\Services\OrderManager;
use Klsandbox\SiteModel\Site;
use Log;

class RaniaOrderManagerWithNoBonus implements OrderManager
{
    /**
     * @var UserManager $userManager
     */
    protected $userManager;

    public function __construct(UserManager $userManager)
    {
        $this->userManager = $userManager;
    }

    public function approveOrder(Order $order, $approved_at = null)
    {
        $allowedStatus = [
            OrderStatus::NewOrderStatus()->id,
            OrderStatus::FirstOrder()->id,
            OrderStatus::PaymentUploaded()->id,

            // TODO: This is for online only, consider separate end point
            OrderStatus::Draft()->id,
        ];

        assert(in_array($order->order_status_id, $allowedStatus), "Invalid Order to approve $order->id - Status {$order->orderStatus->name}");

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

        if (Auth::user())
        {
            $order->approved_by_id = Auth::user()->id;
        }

        $order->save();

        $approveId = (Auth::user() ? Auth::user()->id : 0);
        Log::info("Order approved:$order->id by:" . $approveId);

        if (Auth::user()) {
            User::createUserEvent($order->user, ['created_at' => $approved_at, 'controller' => 'timeline', 'route' => '/order-approved', 'target_id' => $order->id, 'parameter_id' => $approveId]);
        }
        else
        {
            User::createUserEvent($order->user, ['created_at' => $approved_at, 'controller' => 'timeline', 'route' => '/order-auto-approved', 'target_id' => $order->id]);
        }

        NotificationRequest::create(['target_id' => $order->id, 'route' => 'order-approved', 'channel' => 'Sms', 'to_user_id' => $order->user->id]);
    }

    public function rejectOrder(Order $order)
    {
        $allowedStatus = [
            OrderStatus::NewOrderStatus()->id,
            OrderStatus::FirstOrder()->id,
            OrderStatus::PaymentUploaded()->id,

            // TODO: This is for online only, consider separate end point
            OrderStatus::Draft()->id,
        ];

        assert(in_array($order->order_status_id, $allowedStatus), "Invalid Order to reject $order->id - Status {$order->orderStatus->name}");

        Site::protect($order, "Order");

        if ($order->order_status_id == OrderStatus::FirstOrder()->id) {
            Site::protect($order->user, "User");
            $this->userManager->rejectNewMember($order->user);
        }

        $order->rejected_at = new Carbon();

        if (Auth::user())
        {
            $order->rejected_by_id = Auth::user()->id;
        }

        $order->save();

        $order->order_status_id = OrderStatus::Rejected()->id;
        $order->save();

        if (Auth::user()) {
            Log::info("Order rejected:$order->id by:" . Auth::user()->id);

            User::createUserEvent($order->user, ['controller' => 'timeline', 'route' => '/order-rejected', 'target_id' => $order->id, 'parameter_id' => Auth::user()->id]);
        }
        else {
            Log::info("Order rejected:$order->id by:online");

            User::createUserEvent($order->user, ['controller' => 'timeline', 'route' => '/order-auto-rejected', 'target_id' => $order->id]);
        }
        NotificationRequest::create(['target_id' => $order->id, 'route' => 'order-rejected', 'channel' => 'Sms', 'to_user_id' => $order->user->id]);
    }

    public function shipOrder(Order $order, $trackingId)
    {
        $order->tracking_id = $trackingId;
        $order->order_status_id = OrderStatus::Shipped()->id;

        $order->shipped_at = Carbon::now();
        $order->shipped_by_id = Auth::user()->id;
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

    function createRestockOrder($proofOfTransfer, $draft, $productPricingIdHash, $quantityHash)
    {
        $orderModel = config('order.order_model');
        $order = new $orderModel();
        $order->fill(
            [
                'order_status_id' => $draft ? OrderStatus::Draft()->id : OrderStatus::PaymentUploaded()->id,
                'proof_of_transfer_id' => $proofOfTransfer->id,
            ]);

        $order->save();

        foreach ($productPricingIdHash as $key => $item)
        {
            $productPricing = ProductPricing::find(\Crypt::decrypt($item));

            OrderItem::create([
                'product_pricing_id' => \Crypt::decrypt($item),
                'order_id' => $order->id,
                'quantity' => config('order.allow_quantity') ? $quantityHash[$key] : 1,
                'product_price' => $productPricing->product->isOtherProduct() ? $proofOfTransfer->amount : $productPricing->price,
            ]);
        }

        return $order;
    }

    function cancelOrder(Order $order)
    {
        $order->rejected_at = new Carbon();
        $order->rejected_by_id = Auth::user()->id;
        $order->order_status_id = OrderStatus::Rejected()->id;
        $order->save();
    }

    function createFirstOrder($productPricingId, $proofOfTransfer)
    {
        $orderModel = config('order.order_model');

        $order = $orderModel::create(
            [
                'order_status_id' => OrderStatus::FirstOrder()->id,
                'proof_of_transfer_id' => $proofOfTransfer->id,
            ]);

        $orderItem = new OrderItem();
        $orderItem->product_pricing_id = $productPricingId;
        $orderItem->index = 0;
        $orderItem->quantity = 1;
        $orderItem->order_id = $order->id;
        $orderItem->save();

        return $order;
    }

    function setPaymentUploaded($order)
    {
        $order->order_status_id = OrderStatus::PaymentUploaded()->id;
        $order->save();

        Log::info("set-payment-uploaded\t#order:$order->id user:{$order->user->id} status:{$order->orderStatus->name}");
    }
}
