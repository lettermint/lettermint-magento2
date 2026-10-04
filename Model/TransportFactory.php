<?php
declare(strict_types=1);

namespace Lettermint\Email\Model;

use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Creates a new Lettermint transport for every email, so a transport never
 * carries the message of an earlier email.
 */
class TransportFactory
{
    public function __construct(
        private ObjectManagerInterface $objectManager
    ) {
    }

    public function create(EmailMessageInterface $message): Transport
    {
        /** @var Transport $transport */
        $transport = $this->objectManager->create(Transport::class);
        $transport->setMessage($message);

        return $transport;
    }
}
