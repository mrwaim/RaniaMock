<?php

namespace Klsandbox\RaniaMock\Services;

use App\Models\Organization;
use App\Services\ProductPricingManager\ProductPricingManagerInterface;
use App\Services\UserManager;
use Klsandbox\NotificationService\Models\NotificationRequest;
//use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;
use Klsandbox\OrderModel\Models\OrderItem;
use Klsandbox\OrderModel\Models\OrderStatus;
use App\Models\User;
use Carbon\Carbon;
use Auth;
use Klsandbox\OrderModel\Services\OrderManager;
use Klsandbox\SiteModel\Site;
use Log;

class RaniaOrderManagerWithNoBonus implements OrderManager
{
    /**
     * @var UserManager $userManager
     */
    protected $userManager;

    /**
     * @var ProductPricingManagerInterface $productPricingManager
     */
    protected $productPricingManager;
    protected $date;

    public function __construct(UserManager $userManager, ProductPricingManagerInterface $productPricingManager)
    {
        $this->userManager = $userManager;
        $this->productPricingManager = $productPricingManager;
    }

    public function setDate($date)
    {
        $this->date = $date;
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

        Site::protect($order, 'Order');
        User::userProtect($order->user);

        if ($order->order_status_id == OrderStatus::FirstOrder()->id) {
            Site::protect($order->user, 'User');

            $this->userManager->approveNewMember($order->user);
        }

        $order->orderStatus()->associate(OrderStatus::Approved());

        if (!$approved_at) {
            $approved_at = new Carbon();
        }

        $order->approved_at = $approved_at;

        if (Auth::user()) {
            $order->approved_by_id = Auth::user()->id;
        }

        $order->save();

        $approveId = (Auth::user() ? Auth::user()->id : 0);
        Log::info("Order approved:$order->id by:" . $approveId);

        if (Auth::user()) {
            User::createUserEvent($order->user, ['created_at' => $approved_at, 'controller' => 'timeline', 'route' => '/order-approved', 'target_id' => $order->id, 'parameter_id' => $approveId]);
        } else {
            User::createUserEvent($order->user, ['created_at' => $approved_at, 'controller' => 'timeline', 'route' => '/order-auto-approved', 'target_id' => $order->id]);
        }

        NotificationRequest::create(['target_id' => $order->id, 'route' => 'order-approved', 'channel' => 'Sms', 'to_user_id' => $order->user->id]);

        return $order;
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

        Site::protect($order, 'Order');
        User::userProtect($order->user);

        if ($order->order_status_id == OrderStatus::FirstOrder()->id) {
            Site::protect($order->user, 'User');
            $this->userManager->rejectNewMember($order->user);
        }

        $order->rejected_at = new Carbon();

        if (Auth::user()) {
            $order->rejected_by_id = Auth::user()->id;
        }

        $order->save();

        $order->orderStatus()->associate(OrderStatus::Rejected());
        $order->save();

        if (Auth::user()) {
            Log::info("Order rejected:$order->id by:" . Auth::user()->id);

            User::createUserEvent($order->user, ['controller' => 'timeline', 'route' => '/order-rejected', 'target_id' => $order->id, 'parameter_id' => Auth::user()->id]);
        } else {
            Log::info("Order rejected:$order->id by:online");

            User::createUserEvent($order->user, ['controller' => 'timeline', 'route' => '/order-auto-rejected', 'target_id' => $order->id]);
        }
        NotificationRequest::create(['target_id' => $order->id, 'route' => 'order-rejected', 'channel' => 'Sms', 'to_user_id' => $order->user->id]);

        return $order;
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

    public function orderCreated(Order $order)
    {
        assert($order->user_id);
        assert($order->user);

        Log::info("created\t#order:$order->id user:{$order->user->id} status:{$order->orderStatus->name} created_at:{$order->created_at}");

        NotificationRequest::create(['target_id' => $order->id, 'route' => 'new-order', 'channel' => 'Sms', 'to_user_id' => User::admin()->id]);

        if ($order->is_hq)
        {
            assert($order->user->referral_id);
            NotificationRequest::create(['target_id' => $order->id, 'route' => 'new-downlevel-order', 'channel' => 'Sms', 'to_user_id' => $order->user->referral_id]);
        }
        else
        {
            assert($order->organization_id);
            if (!$order->user->isManager()) {
                if ($order->organization_id != Organization::HQ()->id) {
                    NotificationRequest::create(['target_id' => $order->id, 'route' => 'new-order', 'channel' => 'Sms', 'to_user_id' => $order->organization->admin_id]);
                }

                $referral_id = $order->user->new_referral_id;
                assert($referral_id);

                NotificationRequest::create(['target_id' => $order->id, 'route' => 'new-downlevel-order', 'channel' => 'Sms', 'to_user_id' => $referral_id]);
            }
        }

        User::createUserEvent($order->user, ['created_at' => $order->created_at, 'controller' => 'timeline', 'route' => '/new-order', 'target_id' => $order->id]);
    }

    public function createRestockOrder(User $user, $proofOfTransfer, $draft, array $productPricingIdHash, array $quantityHash, $isHq, $customer = null)
    {
        $status = $draft ? OrderStatus::Draft()->id : OrderStatus::PaymentUploaded()->id;

        return $this->createOrder($user, $proofOfTransfer, $productPricingIdHash, $quantityHash, $status, $customer, $isHq);
    }

    /**
     * @param User $user
     * @param $proofOfTransfer
     * @param array $productPricingIdHash
     * @param array $quantityHash
     * @param $status
     * @param $customer
     *
     * @return mixed
     */
    private function createOrder(User $user, $proofOfTransfer, array $productPricingIdHash, array $quantityHash, $status, $customer, $isHq)
    {
        assert($user);
        assert($user->id);

        if ($customer) {
            assert($customer->referral_id == $user->id);
        }

        if (empty($productPricingIdHash)) {
            \App::abort(500, 'invalid');
        }

        if (empty($quantityHash)) {
            \App::abort(500, 'invalid');
        }

        $allowedProducts = $this->productPricingManager->getAvailableProductPricingList($user, (bool)$customer)->pluck('id')->all();

        assert(!empty($allowedProducts));

        $organizationId = null;

        $hq = Organization::HQ();
        $organizationId = $isHq ? $hq->id : $user->organization_id;

        $orderModel = config('order.order_model');
        $order = new $orderModel();
        $order->fill(
            [
                'order_status_id' => $status,
                'proof_of_transfer_id' => $proofOfTransfer->id,
                'customer_id' => $customer ? $customer->id : null,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'is_hq' => $isHq,
            ]);

        if ($this->date) {
            $order->created_at = $this->date;
        }

        $order->save();

        $index = 0;
        foreach ($productPricingIdHash as $key => $productPricing) {
            if (!config('order.allow_quantity') && $quantityHash[$key] != 1) {
                \App::abort(500, 'invalid');
            }

            assert(in_array($productPricing->id, $allowedProducts));

            $productPricing->getPriceAndDelivery($user, $customer, $price, $delivery);

            $organizationId = $productPricing->product->is_hq ? $hq->id : $user->organization_id;

            assert($organizationId == $order->organization_id, 'organization_id');
            assert($isHq == $productPricing->product->is_hq, 'is_hq');

            $product = $productPricing->product;

            $quantity = $quantityHash[$key];

            assert($product->max_quantity >= $quantity);

            // by default awarded_user_id is auth user
            $awardedUserId = $user->id;

            // if product->awarded_parent is true the set referral id as awarded
            // Only applies to new system
            if ($product->award_parent) {
                assert($user->new_referral_id);
                $awardedUserId = $user->new_referral_id;
            }

            $orderItem = new OrderItem();
            $orderItem->fill(
                [
                    'product_pricing_id' => $productPricing->id,
                    'order_id' => $order->id,
                    'quantity' => $quantity,
                    'product_price' => $productPricing->product->isOtherProduct() ? $proofOfTransfer->amount : $price,
                    'delivery' => $delivery,
                    'index' => $index++,
                    'organization_id' => $organizationId,
                    'awarded_user_id' => $awardedUserId,
                ]
            );

            if ($this->date) {
                $orderItem->created_at = $this->date;
            }

            $orderItem->save();
        }

        $this->orderCreated($order);

        return $order;
    }

    public function cancelOrder(Order $order)
    {
        $order->rejected_at = new Carbon();
        $order->rejected_by_id = Auth::user()->id;
        $order->order_status_id = OrderStatus::Rejected()->id;
        $order->save();
    }

    public function createFirstOrder(User $user, $proofOfTransfer, array $productPricingIdHash, array $quantityHash, $isHq)
    {
        assert($user, '$user');
        assert($user->id, '$user->id');

        $status = OrderStatus::FirstOrder()->id;

        return $this->createOrder($user, $proofOfTransfer, $productPricingIdHash, $quantityHash, $status, null, $isHq);
    }

    public function setPaymentUploaded($order)
    {
        $order->order_status_id = OrderStatus::PaymentUploaded()->id;
        $order->save();

        Log::info("set-payment-uploaded\t#order:$order->id user:{$order->user->id} status:{$order->orderStatus->name}");
    }

    /**
     * @param $filter
     * @param $user
     *
     * @return array
     */
    public function getOrderList(&$filter, $user)
    {
        $orderModel = config('order.order_model');

        $q = $orderModel::with('proofOfTransfer', 'user', 'customer', 'proofOfTransfer.billplzResponses', 'orderStatus');

        if (preg_match('/-org$/', $filter)) {
            $q = $q
                ->where('organization_id', $user->organization_id);
            $filter = preg_replace('/-org$/', '', $filter);
        }

        if ($filter == 'draft') {
            $q = $q->where('order_status_id', '=', OrderStatus::Draft()->id);
        } else {
            // Draft is for internal debugging only
            $q = $q->where('order_status_id', '<>', OrderStatus::Draft()->id);
        }

        if ($user->access()->manager || $user->access()->staff) {
            if ($filter == 'unapproved' || $filter == 'late-approvals') {
                $q = Order::whereNotApproved($q);
            }

            if ($user->access()->manager) {
                if ($filter == 'unfulfilled') {
                    $q = Order::whereNotFulfilled($q);
                }
            }

            if ($filter == 'approved') {
                $q = Order::whereNotFulfilled($q);
            }

            if ($filter == 'shipped') {
                $q = Order::whereFulfilled($q);
            }
        }

        if ($filter == 'me' || $filter == 'down-line') {
            $userIds = User::userIdsForFilter($filter);

            $q = $q->whereIn('user_id', $userIds);

            return $q;
        } elseif ($user->access()->manager || $user->access()->staff) {
            if ($user->access()->staff) {
                $q = $q->where('created_at', '<=', 'DATE_SUB(CURDATE(), INTERVAL' . \Config::get('staff.canView') . ' ' . \Config::get('staff.system') . ')');
            }

            if ($filter == 'unapproved' || $filter == 'late-approvals') {
                $q = $q->where('created_at', '<=', 'DATE_SUB(CURDATE(), INTERVAL 1 WEEK)');
            }

            return $q;
        } else {
            return $q;
        }
    }
}
