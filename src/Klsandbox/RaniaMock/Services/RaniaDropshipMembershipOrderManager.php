<?php

namespace Klsandbox\RaniaMock\Services;

use App\Models\User;
use App\Services\ProductPricingManager\ProductPricingManagerInterface;
use App\Services\UserManager;
use Klsandbox\BonusModel\Services\BonusManager;
use App\Services\MembershipManager\MembershipManagerInterface as MembershipManager;
use Log;

class RaniaDropshipMembershipOrderManager extends RaniaOrderManager
{
    protected $debug = true;

    public function __construct(BonusManager $bonusManager, UserManager $userManager, ProductPricingManagerInterface $productPricingManager, MembershipManager $membershipManager)
    {
        parent::__construct($bonusManager, $userManager, $productPricingManager, $membershipManager);
    }

    public function createRestockOrder(User $user, $proofOfTransfer, $draft, array $productPricingIdHash, array $quantityHash, $isHq, $customer = null)
    {
        if ($this->debug) {
            Log::debug('createRestockOrder - with-membership');
        }

        $globalScopeUser = \App\Http\Middleware\GlobalScopeMiddleware::$user;
        \App\Http\Middleware\GlobalScopeMiddleware::setScope(null);

        $access = $user->access();
        if (!$user->new_referral_id && !$user->organization_id && $access->stockist && !$access->dropship) {
            foreach ($productPricingIdHash as $key => $productPricing) {
                if ($productPricing->product->is_membership && !$productPricing->product->is_hq) {
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

                        $user->new_referral_id = $firstManager->id;
                        $user->organization_id = $firstManager->organization_id;
                        $user->save();
                    }
                }
            }

            if ($user->new_referral_id && $user->organization_id) {
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

        \App\Http\Middleware\GlobalScopeMiddleware::setScope($globalScopeUser);

        return parent::createRestockOrder($user, $proofOfTransfer, $draft, $productPricingIdHash, $quantityHash, $isHq, $customer);
    }
}
