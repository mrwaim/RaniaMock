<?php

namespace Klsandbox\RaniaMock\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\ProductPricingManager\ProductPricingManagerInterface;
use App\Services\UserManager;
use Klsandbox\BonusModel\Services\BonusManager;
use App\Services\MembershipManager\MembershipManagerInterface as MembershipManager;
use Klsandbox\OrderModel\Models\ProofOfTransfer;
use Log;

class RaniaDropshipMembershipOrderManager extends RaniaOrderManager
{
    protected $debug = true;

    public function __construct(BonusManager $bonusManager, UserManager $userManager, ProductPricingManagerInterface $productPricingManager, MembershipManager $membershipManager)
    {
        parent::__construct($bonusManager, $userManager, $productPricingManager, $membershipManager);
    }

    public function createRestockOrder(User $user, ProofOfTransfer $proofOfTransfer, $draft, array $productPricingIdHash, array $quantityHash, $isHq, $customer = null)
    {
        if ($this->debug) {
            Log::debug('createRestockOrder - with-membership');
        }

        $hasOrganizationMembership = false;
        foreach ($productPricingIdHash as $key => $productPricing) {
            if ($productPricing->product->is_membership && !$productPricing->product->is_hq) {
                $hasOrganizationMembership = true;
                break;
            }
        }

        if ($hasOrganizationMembership) {

            $globalScopeUser = \App\Http\Middleware\GlobalScopeMiddleware::$user;
            \App\Http\Middleware\GlobalScopeMiddleware::setScope(null);

            list($new_referral_id, $organization_id) = $this->getNewMemberOrganizationParent($user);

            \App\Http\Middleware\GlobalScopeMiddleware::setScope($globalScopeUser);

            if ($new_referral_id !== null && $organization_id !== null) {
                $user->new_referral_id = $new_referral_id;
                $user->organization_id = $organization_id;
                $user->save();

                if ($proofOfTransfer->id)
                {
                    $proofOfTransfer->receiver_user_id = Organization::find($organization_id)->admin->id;
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
        }

        return parent::createRestockOrder($user, $proofOfTransfer, $draft, $productPricingIdHash, $quantityHash, $isHq, $customer);
    }

    /**
     * @param User $user
     * @return array
     */
    private function getNewMemberOrganizationParent(User $user)
    {
        $access = $user->access();
        $new_referral_id = null;
        $organization_id = null;
        if (!$user->new_referral_id && !$user->organization_id && $access->stockist && !$access->dropship) {
            if ($this->debug) {
                Log::debug('  processing-membership');
            }

            if ($user->upLevel->hasDropshipAccess()) {
                if ($this->debug) {
                    Log::debug('    connect-with-uplevel');
                }

                $user->new_referral_id = $user->upLevel->id;
                $user->organization_id = $user->upLevel->organization_id;
                $user->save();
                return array($new_referral_id, $organization_id);
            } else {
                if ($this->debug) {
                    Log::debug("    connecting-with-manager user:$user->id");
                }

                $firstManager = null;
                $parent = $user;
                do {
                    if ($this->debug) {
                        Log::debug("    processing:$parent->id");
                    }

                    $parent = $parent->upLevel;
                    assert($parent);
                    if ($this->debug) {
                        Log::debug("parent:$parent->id");
                    }

                    if ($parent->isManager()) {
                        $firstManager = $parent;
                    }
                } while ($firstManager === null);

                if ($this->debug) {
                    Log::debug('    connect-with-manager');
                }

                $new_referral_id = $firstManager->id;
                $organization_id = $firstManager->organization_id;
                return array($new_referral_id, $organization_id);
            }
        }
        return array($new_referral_id, $organization_id);
    }
}
