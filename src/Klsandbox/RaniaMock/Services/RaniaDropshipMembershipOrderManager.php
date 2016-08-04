<?php

namespace Klsandbox\RaniaMock\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\ProductPricingManager\ProductPricingManagerInterface;
use App\Services\UserManager;
use Klsandbox\BonusModel\Services\BonusManager;
use App\Services\MembershipManager\MembershipManagerInterface as MembershipManager;
use Klsandbox\OrderModel\Models\ProofOfTransfer;
use Klsandbox\RoleModel\Role;
use Log;

class RaniaDropshipMembershipOrderManager extends RaniaOrderManager
{
    protected $debug = true;

    public function __construct(BonusManager $bonusManager, UserManager $userManager, ProductPricingManagerInterface $productPricingManager, MembershipManager $membershipManager)
    {
        parent::__construct($bonusManager, $userManager, $productPricingManager, $membershipManager);
    }

    public function createRestockOrder(User $user, ProofOfTransfer $proofOfTransfer, $draft, array $products, array $quantityHash, $isHq, $customer = null, $isPickup = false)
    {
        if ($this->debug) {
            Log::debug('createRestockOrder - with-membership');
        }

        $hasOrganizationMembership = false;
        $hasHqMembership = false;
        foreach ($products as $key => $product) {

            if ($product->is_membership) {
                if ($product->is_hq) {
                    $hasHqMembership = true;
                } else {
                    $hasOrganizationMembership = true;
                }

                break;
            }
        }

        $access = $user->access();

        if ($hasOrganizationMembership && $access->stockist && !$access->dropship) {
            $parent = $this->userManager->getNewMemberParent($user);

            $globalScopeUser = \App\Http\Middleware\GlobalScopeMiddleware::$user;
            \App\Http\Middleware\GlobalScopeMiddleware::setScope(null);

            if ($parent && $parent->id !== null && $parent->organization_id !== null) {
                $user->new_referral_id = $parent->id;
                $user->organization_id = $parent->organization_id;
                $user->organization()->associate($parent->organization);
                $user->save();

                if ($proofOfTransfer->id) {
                    $proofOfTransfer->receiver_user_id = $parent->organization->admin->id;
                    $proofOfTransfer->save();
                }

                /**
                 * @var $downLevel User
                 */
                foreach ($user->downLevels as $downLevel) {
                    if ($downLevel->hasDropshipAccess()) {
                        $downLevel->new_referral_id = $user->id;
                        $downLevel->organization_id = $user->organization_id;
                        $downLevel->save();
                    }
                }
            }

            \App\Http\Middleware\GlobalScopeMiddleware::setScope($globalScopeUser);
        }

        if ($hasHqMembership && $access->dropship && !$access->stockist) {
            $globalScopeUser = \App\Http\Middleware\GlobalScopeMiddleware::$user;
            \App\Http\Middleware\GlobalScopeMiddleware::setScope(null);

            if ($user->upLevelNew->hasStockistAccess()) {
                $parent = $user->upLevelNew;
            } else {
                $parent = $user->organization->admin;
            }

            if ($parent && $parent->id !== null) {
                $user->referral_id = $parent->id;
                $user->upLevel()->associate($parent);

                $user->save();
            }

            \App\Http\Middleware\GlobalScopeMiddleware::setScope($globalScopeUser);
        }

        return parent::createRestockOrder($user, $proofOfTransfer, $draft, $products, $quantityHash, $isHq, $customer, $isPickup);
    }
}
