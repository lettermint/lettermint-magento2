<?php
declare(strict_types=1);

namespace Lettermint\Email\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\TransportInterfaceFactory;
use Magento\Store\Model\ScopeInterface;
use Lettermint\Email\Model\Transport;
use Psr\Log\LoggerInterface;

/**
 * Intercepts transport creation and swaps in Lettermint Transport
 * for newsletter emails and when enabled for all emails.
 */
class TransportSwitcher
{
    private const CONFIG_PATH_ENABLED = 'lettermint_email/general/enabled';

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private LoggerInterface      $logger,
        private Transport            $lettermintTransport
    )
    {
    }

    /**
     * Around plugin on TransportInterfaceFactory::create()
     */
    public function aroundCreate(
        TransportInterfaceFactory $subject,
        callable                  $proceed,
        ?array                    $data = null
    )
    {

        if (!$this->isEnabled()) {
            $this->logger->info('Lettermint Transport Switcher: Module disabled, using default transport');
            return $proceed($data);
        }

        $message = $data['message'] ?? null;

        if ($message) {
            // Use Lettermint transport for all emails when enabled
            $this->lettermintTransport->setMessage($message);
            return $this->lettermintTransport;
        }

        // Fallback to default transport
        return $proceed($data);
    }

    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

}
