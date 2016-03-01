<?php

namespace Klsandbox\RaniaMock\Services;

use Klsandbox\BonusModel\Models\BonusNote;
use App\Models\User;
use App\Models\Bonus;
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
        $date = $bonusCommand->order->created_at;
        return $date->addMonth(1)->endOfMonth();
    }

    public function resolveBonus(Order $order)
    {
        $first = new DateTime();

        assert($order->isApproved(), 'order is not accepted');

        if ($order->user->upLevel->role->name != 'admin') {
            self::payIntroducerBonus($order, $order->user->upLevel);
        }
        self::payIntroducerBonus($order, $order->user);
    }

    public function resolveBonusCommandsForOrderUserDetails($order_id, Carbon $created_at, Order $order, $user)
    {
        return [];
    }

    // Payment methods
    private static function payIntroducerBonus(Order $order, User $user)
    {
        assert($order->isApproved(), 'order is not accepted');

        //echo "FIRST ORDER => GIVE INTRODUCER BONUS\n";
        //echo "ORDER_USER " . $order->user . PHP_EOL;
        //echo "INTRODUCER " . $user . PHP_EOL;

        $bonus = Bonus::create([
            'created_at' => $order->approved_at,
            'updated_at' => $order->updated_at,
            'workflow_status' => 'ProcessedByReceiver',
            'bonus_payout_id' => BonusPayout::IntroducerBonusPayoutCashOption()->id,
            'bonus_type_id' => BonusType::IntroducerBonus()->id,
            'awarded_by_user_id' => 2,
            'awarded_to_user_id' => $user->id,
            'order_id' => $order->id,
        ]);

        $bonusNote = BonusNote::create([
            'notes' => 'method:' . __METHOD__,
            'bonus_id' => $bonus->id,
        ]);

        //echo "BONUS_PAID " . $bonus . PHP_EOL;
    }
}
