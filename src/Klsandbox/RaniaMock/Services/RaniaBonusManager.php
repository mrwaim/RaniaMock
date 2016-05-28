<?php

namespace Klsandbox\RaniaMock\Services;

use App\Models\BonusCategory;
use Klsandbox\BonusModel\Models\BonusNote;
use App\Models\User;
use App\Models\Bonus;
use Klsandbox\OrderModel\Models\OrderItem;
use Klsandbox\BonusModel\Models\BonusPayout;
use Klsandbox\BonusModel\Models\BonusType;
use Klsandbox\BonusModel\Services\BonusCommand;
use Klsandbox\BonusModel\Services\BonusManager;
use Klsandbox\OrderModel\Models\Order;
use Carbon\Carbon;
use DateTime;

class RaniaBonusManager implements BonusManager
{
    public function getExpiry(BonusCommand $bonusCommand)
    {
        $date = $bonusCommand->orderItem->created_at;

        return $date->addMonth(1)->endOfMonth();
    }

    public function resolveBonus(OrderItem $orderItem)
    {
        $first = new DateTime();

        assert($orderItem->order->isApproved(), 'order is not accepted');

        if ($orderItem->order->user->upLevel->role->name != 'admin') {
            self::payIntroducerBonus($orderItem, $orderItem->order->user->upLevel);
        }

        self::payIntroducerBonus($orderItem, $orderItem->order->user);
    }

    public function resolveBonusCommandsForOrderItemUserDetails($order_item_id, Carbon $created_at, OrderItem $order, $user, BonusCategory $orderItemBonusCategory)
    {
        return [];
    }

    // Payment methods
    private static function payIntroducerBonus(OrderItem $orderItem, User $user)
    {
        assert($orderItem->order->isApproved(), 'order is not accepted');

        //echo "FIRST ORDER => GIVE INTRODUCER BONUS\n";
        //echo "ORDER_USER " . $order->user . PHP_EOL;
        //echo "INTRODUCER " . $user . PHP_EOL;

        $bonus = Bonus::create([
            'created_at' => $orderItem->order->approved_at,
            'updated_at' => $orderItem->order->updated_at,
            'workflow_status' => 'ProcessedByReceiver',
            'bonus_payout_id' => BonusPayout::IntroducerBonusPayoutCashOption()->id,
            'bonus_type_id' => BonusType::IntroducerBonus()->id,
            'awarded_by_user_id' => 2,
            'awarded_to_user_id' => $user->id,
            'order_item_id' => $orderItem->id,
        ]);

        $bonusNote = BonusNote::create([
            'notes' => 'method:' . __METHOD__,
            'bonus_id' => $bonus->id,
        ]);

        //echo "BONUS_PAID " . $bonus . PHP_EOL;
    }
}
