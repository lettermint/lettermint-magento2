<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/magento/framework/Phrase/__.php';

// The tests install magento/framework only. Magento_Store is a full module with
// many dependencies, and the module only reads this constant from it.
if (!interface_exists(\Magento\Store\Model\ScopeInterface::class)) {
    eval('namespace Magento\Store\Model; interface ScopeInterface { public const SCOPE_STORE = "store"; }');
}

// Magento generates these factories at compile time. The tests build real
// EmailMessage objects, which need them.
if (!class_exists(\Magento\Framework\Mail\AddressFactory::class)) {
    eval('namespace Magento\Framework\Mail; class AddressFactory {
        public function create(array $data = []): Address { return new Address($data["email"] ?? null, $data["name"] ?? null); }
    }');
}
if (!class_exists(\Magento\Framework\Mail\MimeMessageInterfaceFactory::class)) {
    eval('namespace Magento\Framework\Mail; class MimeMessageInterfaceFactory {
        public function create(array $data = []): MimeMessageInterface { return new MimeMessage($data["parts"] ?? []); }
    }');
}
