<?php
declare(strict_types=1);
// Phase 6 — RED/GREEN spec for the access-control PolicyEngine.
// Pure PHP (no CI4 bootstrap) so it runs under php-cgi/phpdbg without composer.
// Run:  php-cgi -q -f tests/phase6/policy_engine_check.php

require __DIR__ . '/../../app/Libraries/PolicyEngine.php';

use App\Libraries\PolicyEngine;

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $label\n"; }
    else       { $fail++; echo "FAIL: $label\n"; }
}

$pe = new PolicyEngine();

// --- RBAC: permission presence -------------------------------------------
$vendor = ['actor_id'=>3, 'permissions'=>['order.view.own','pos.sell'], 'scopes'=>[['type'=>'vendor','id'=>1]], 'attributes'=>[]];
check('can() true when permission present',  $pe->can($vendor,'pos.sell') === true);
check('can() false when permission absent',  $pe->can($vendor,'payout.approve') === false);
check('authorize denies when permission absent (scope ok)', $pe->authorize($vendor,'payout.approve',['vendor_id'=>1]) === false);

// --- ABAC: vendor scope (tenant isolation) -------------------------------
check('vendor scope allows own vendor',  $pe->authorize($vendor,'order.view.own',['vendor_id'=>1]) === true);
check('vendor scope denies other vendor', $pe->authorize($vendor,'order.view.own',['vendor_id'=>2]) === false);

// --- ABAC: shop scope (branch isolation) ---------------------------------
$shop = ['actor_id'=>3, 'permissions'=>['inventory.view'], 'scopes'=>[['type'=>'shop','id'=>10]], 'attributes'=>[]];
check('shop scope allows assigned shop',  $pe->authorize($shop,'inventory.view',['shop_id'=>10]) === true);
check('shop scope denies other shop',     $pe->authorize($shop,'inventory.view',['shop_id'=>11]) === false);

// --- ABAC: platform scope (cross-vendor) ---------------------------------
$platform = ['actor_id'=>1, 'permissions'=>['order.view'], 'scopes'=>[['type'=>'platform','id'=>null]], 'attributes'=>[]];
check('platform scope allows any vendor target', $pe->authorize($platform,'order.view',['vendor_id'=>99]) === true);

// --- ABAC: self scope (delivery boy / customer) --------------------------
$self = ['actor_id'=>4, 'permissions'=>['delivery.update'], 'scopes'=>[['type'=>'self','id'=>4]], 'attributes'=>[]];
check('self scope allows own data',   $pe->authorize($self,'delivery.update',['owner_id'=>4]) === true);
check('self scope denies others data', $pe->authorize($self,'delivery.update',['owner_id'=>5]) === false);

echo "\nRESULT: pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
