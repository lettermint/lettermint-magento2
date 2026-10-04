<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/magento/framework/Phrase/__.php';

// The tests install magento/framework only. Magento_Store is a full module with
// many dependencies, and the module only reads this constant from it.
if (!interface_exists(\Magento\Store\Model\ScopeInterface::class)) {
    eval('namespace Magento\Store\Model; interface ScopeInterface { public const SCOPE_STORE = "store"; }');
}
